<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

use Rephper\Ir\ClassConstant;
use Rephper\Ir\ClassDeclaration;
use Rephper\Ir\ClassProperty;
use Rephper\Ir\OpArray;

/**
 * Renders one class or interface from its IR declaration and method bodies.
 *
 * Constants, then properties, then methods. Promoted properties are parameters
 * of the constructor, not a second declaration. A member the IR cannot spell
 * fails the class.
 */
final class ClassRenderer
{
    private const IDENTIFIER = '/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/';

    private const QUALIFIED_NAME = '/\A\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z/';

    /** @var list<string> */
    private const CLASS_FLAGS = [
        'implicit_abstract', 'abstract', 'final', 'readonly', 'anonymous',
        'linked', 'allow_dynamic_properties', 'not_serializable',
    ];

    /** @var list<string> */
    private const PROPERTY_FLAGS = [
        'public', 'protected', 'private', 'public_set', 'protected_set', 'private_set',
        'static', 'readonly', 'final', 'abstract', 'virtual', 'promoted',
    ];

    /** @var list<string> */
    private const CONSTANT_FLAGS = [
        'public', 'protected', 'private', 'final', 'enum_case', 'deprecated',
    ];

    /** @var list<string> */
    private const METHOD_FLAGS = [
        'public', 'protected', 'private', 'static', 'final', 'abstract',
        'has_type_hints', 'deprecated', 'return_reference', 'has_return_type',
        'variadic', 'has_finally', 'early_binding', 'uses_this', 'constructor',
        'override', 'strict_types', 'top_level',
    ];

    public function __construct(
        private readonly FunctionRenderer $functions = new FunctionRenderer(),
        private readonly TypeRenderer $types = new TypeRenderer(),
        private readonly LiteralRenderer $literals = new LiteralRenderer(),
        private readonly AttributeRenderer $attributes = new AttributeRenderer(),
    ) {
    }

    /**
     * @param list<FunctionDeclaration> $methods
     */
    public function render(
        ClassDeclaration $class,
        array $methods,
        int $indent,
        string $declaredName,
        ?NameQualifier $names,
    ): string {
        if ($class->kind !== 'class' && $class->kind !== 'interface') {
            $this->fail($class, $class->kind . ' is not supported');
        }
        if (preg_match(self::IDENTIFIER, $declaredName) !== 1) {
            $this->fail($class, 'name cannot be rendered');
        }
        $this->assertFlags($class);
        $this->assertMembers($class);
        $constructor = $this->constructor($class, $methods);
        $promoted = $this->promotedParameters($class, $constructor);

        $pad = str_repeat('    ', $indent);
        $lines = [];
        foreach ($this->classAttributes($class, $names) as $attribute) {
            $lines[] = $pad . $attribute;
        }
        $lines[] = $pad . $this->header($class, $declaredName, $names);
        $members = [];
        foreach ($class->constants as $constant) {
            $members[] = $this->constantDeclaration($class, $constant, $indent + 1, $names);
        }
        foreach ($class->properties as $property) {
            if (in_array('promoted', $property->flags, true)) {
                continue;
            }
            $members[] = $this->propertyDeclaration($class, $property, $indent + 1, $names);
        }
        $seen = [];
        foreach ($methods as $declaration) {
            $opArray = $declaration->opArray;
            $methodName = $opArray->name;
            if (!is_string($methodName) || $methodName === '') {
                $this->fail($class, 'a method has no name');
            }
            $key = strtolower($methodName);
            if (isset($seen[$key])) {
                $this->fail($class, 'method ' . $methodName . ' is declared more than once');
            }
            $seen[$key] = true;
            $scope = $opArray->scope;
            if ($scope === null || $scope->name !== $class->name || $scope->kind !== $class->kind) {
                $this->fail($class, 'method ' . $methodName . ' does not belong to this ' . $class->kind);
            }
            $isConstructor = $opArray === $constructor;
            $style = $this->methodStyle($class, $opArray, $isConstructor ? $promoted['order'] : [], $isConstructor ? $promoted['map'] : []);
            $members[] = $this->functions->render($declaration, $indent + 1, null, $names, $style);
        }

        $text = implode("\n", $lines) . "\n" . $pad . "{\n";
        if ($members !== []) {
            $text .= implode("\n\n", $members) . "\n";
        }
        $text .= $pad . '}';

        return $text;
    }

    private function assertFlags(ClassDeclaration $class): void
    {
        foreach ($class->flags as $flag) {
            if (!in_array($flag, self::CLASS_FLAGS, true)) {
                $this->fail($class, 'flag ' . $flag . ' is not supported');
            }
        }
        if (in_array('anonymous', $class->flags, true)) {
            $this->fail($class, 'anonymous class is not supported');
        }
        if (in_array('not_serializable', $class->flags, true)) {
            $this->fail($class, 'not_serializable is not supported');
        }
        if ($class->enumBacking !== null) {
            $this->fail($class, 'enum backing is not supported');
        }
        if ($class->traits !== []) {
            $this->fail($class, 'traits are not supported');
        }
        $final = in_array('final', $class->flags, true);
        $explicitAbstract = in_array('abstract', $class->flags, true);
        $readonly = in_array('readonly', $class->flags, true);
        if ($class->kind === 'interface' && ($final || $explicitAbstract || $readonly || $class->parent !== null)) {
            $this->fail($class, 'interface modifiers are not supported');
        }
        if ($final && ($explicitAbstract || in_array('implicit_abstract', $class->flags, true))) {
            $this->fail($class, 'is final and abstract');
        }
        if (in_array('allow_dynamic_properties', $class->flags, true) && !$this->explainsDynamicProperties($class)) {
            $this->fail($class, 'dynamic properties are not explained by an attribute or a parent');
        }
    }

    private function explainsDynamicProperties(ClassDeclaration $class): bool
    {
        foreach ($class->attributes as $attribute) {
            $name = is_array($attribute) ? ($attribute['name'] ?? null) : null;
            if (is_string($name) && $this->tail($name) === 'AllowDynamicProperties') {
                return true;
            }
        }

        return $class->parent !== null;
    }

    private function assertMembers(ClassDeclaration $class): void
    {
        if ($class->kind === 'interface' && $class->properties !== []) {
            $this->fail($class, 'interface properties are not supported');
        }
        $properties = [];
        foreach ($class->properties as $property) {
            if (isset($properties[$property->name])) {
                $this->fail($class, 'property $' . $property->name . ' is declared more than once');
            }
            $properties[$property->name] = true;
        }
        $constants = [];
        foreach ($class->constants as $constant) {
            if (isset($constants[$constant->name])) {
                $this->fail($class, 'constant ' . $constant->name . ' is declared more than once');
            }
            $constants[$constant->name] = true;
        }
    }

    /**
     * @param list<FunctionDeclaration> $methods
     */
    private function constructor(ClassDeclaration $class, array $methods): ?OpArray
    {
        $constructor = null;
        foreach ($methods as $declaration) {
            $opArray = $declaration->opArray;
            $named = is_string($opArray->name) && strcasecmp($opArray->name, '__construct') === 0;
            $flag = in_array('constructor', $opArray->flags, true);
            if ($named !== $flag) {
                $this->fail($class, 'constructor metadata is incomplete');
            }
            if (!$flag) {
                continue;
            }
            if ($constructor !== null) {
                $this->fail($class, 'has more than one constructor');
            }
            $constructor = $opArray;
        }

        return $constructor;
    }

    /**
     * @return array{order: list<string>, map: array<string, PromotedProperty>}
     */
    private function promotedParameters(ClassDeclaration $class, ?OpArray $constructor): array
    {
        $declared = [];
        foreach ($class->properties as $property) {
            if (in_array('promoted', $property->flags, true)) {
                $declared[$property->name] = $property;
            }
        }
        if ($constructor === null) {
            if ($declared !== []) {
                $name = array_key_first($declared);
                $this->fail($class, 'promoted property $' . $name . ' is not a constructor parameter');
            }

            return ['order' => [], 'map' => []];
        }

        $order = [];
        $map = [];
        foreach ($constructor->arguments as $argument) {
            if (!is_array($argument) || ($argument['promoted'] ?? null) !== true) {
                continue;
            }
            $name = $argument['name'] ?? null;
            if (!is_string($name) || !isset($declared[$name])) {
                $label = is_string($name) ? '$' . $name : '(unknown)';
                $this->fail($class, 'promoted parameter ' . $label . ' has no property');
            }
            $property = $declared[$name];
            if (in_array('static', $property->flags, true)) {
                $this->fail($class, 'promoted property $' . $name . ' is static');
            }
            if ($property->defaultValue !== null) {
                $this->fail($class, 'promoted property $' . $name . ' has a property default');
            }
            if (!$this->sameType($property->type, $argument['type'] ?? null)) {
                $this->fail($class, 'promoted parameter $' . $name . ' does not match its property');
            }
            $map[$name] = new PromotedProperty($this->propertyModifiers($class, $property));
            $order[] = $name;
            unset($declared[$name]);
        }
        if ($declared !== []) {
            $name = array_key_first($declared);
            $this->fail($class, 'promoted property $' . $name . ' is not a constructor parameter');
        }

        return ['order' => $order, 'map' => $map];
    }

    /**
     * @param list<string> $promoted
     * @param array<string, PromotedProperty> $promotions
     */
    private function methodStyle(ClassDeclaration $class, OpArray $opArray, array $promoted, array $promotions): MethodStyle
    {
        $name = is_string($opArray->name) ? $opArray->name : '(unknown)';
        foreach ($opArray->arguments as $argument) {
            if (is_array($argument) && ($argument['promoted'] ?? null) === true && $promotions === []) {
                $this->fail($class, 'method ' . $name . ' has a promoted parameter');
            }
        }
        foreach ($opArray->flags as $flag) {
            if (in_array($flag, ['generator', 'closure', 'fake_closure'], true)) {
                $this->fail($class, 'method ' . $name . ' is a ' . str_replace('_', ' ', $flag));
            }
            if (!in_array($flag, self::METHOD_FLAGS, true)) {
                $this->fail($class, 'method ' . $name . ' has flag ' . $flag . ', which is not supported');
            }
        }
        if (in_array('override', $opArray->flags, true) && !$this->hasAttribute($opArray->attributes, 'Override')) {
            $this->fail($class, 'method ' . $name . ' is override, but the attribute is not in the IR');
        }
        if (in_array('deprecated', $opArray->flags, true) && !$this->hasAttribute($opArray->attributes, 'Deprecated')) {
            $this->fail($class, 'method ' . $name . ' is deprecated, but the attribute is not in the IR');
        }
        $visibility = array_values(array_intersect($opArray->flags, ['public', 'protected', 'private']));
        if (count($visibility) !== 1) {
            $this->fail($class, 'method ' . $name . ' has no single visibility');
        }
        $isAbstract = in_array('abstract', $opArray->flags, true);
        $isFinal = in_array('final', $opArray->flags, true);
        $isStatic = in_array('static', $opArray->flags, true);
        if ($isAbstract && $isFinal) {
            $this->fail($class, 'method ' . $name . ' is final and abstract');
        }
        if ($isAbstract && $promoted !== []) {
            $this->fail($class, 'method ' . $name . ' is abstract and promoted');
        }
        $classIsAbstract = in_array('abstract', $class->flags, true) || in_array('implicit_abstract', $class->flags, true);
        if ($class->kind === 'interface' && !$isAbstract) {
            $this->fail($class, 'method ' . $name . ' is not abstract');
        }
        if ($class->kind === 'class' && $isAbstract && !$classIsAbstract) {
            $this->fail($class, 'method ' . $name . ' is abstract, but the class is not');
        }

        return new MethodStyle(
            $visibility[0],
            $isStatic,
            $isFinal,
            $isAbstract,
            $isAbstract && $class->kind !== 'interface',
            $promoted,
            $promotions,
        );
    }

    private function header(ClassDeclaration $class, string $declaredName, ?NameQualifier $names): string
    {
        $abstract = $class->kind === 'class' && (
            in_array('abstract', $class->flags, true) || in_array('implicit_abstract', $class->flags, true)
        );
        $parts = [];
        if (in_array('final', $class->flags, true)) {
            $parts[] = 'final';
        }
        if ($abstract) {
            $parts[] = 'abstract';
        }
        if ($class->kind === 'class' && in_array('readonly', $class->flags, true)) {
            $parts[] = 'readonly';
        }
        $parts[] = $class->kind;
        $parts[] = $declaredName;
        $header = implode(' ', $parts);
        if ($class->kind === 'class' && $class->parent !== null) {
            $header .= ' extends ' . $this->qualified($class, $class->parent, $names);
        }
        if ($class->interfaces !== []) {
            $rendered = [];
            foreach ($class->interfaces as $interface) {
                $rendered[] = $this->qualified($class, $interface, $names);
            }
            $header .= ' ' . ($class->kind === 'interface' ? 'extends' : 'implements') . ' ' . implode(', ', $rendered);
        }

        return $header;
    }

    /**
     * @return list<string>
     */
    private function classAttributes(ClassDeclaration $class, ?NameQualifier $names): array
    {
        foreach ($class->attributes as $attribute) {
            if (!is_array($attribute) || !array_key_exists('offset', $attribute) || !is_int($attribute['offset'])) {
                $this->fail($class, 'attribute target is not in the IR');
            }
            if ($attribute['offset'] !== 0) {
                $this->fail($class, 'attribute target is not on this ' . $class->kind);
            }
        }

        return $this->attributes->render($class->attributes, 0, $class->name, $class->kind, $class->lineStart, $names);
    }

    private function constantDeclaration(
        ClassDeclaration $class,
        ClassConstant $constant,
        int $indent,
        ?NameQualifier $names,
    ): string {
        foreach ($constant->flags as $flag) {
            if (!in_array($flag, self::CONSTANT_FLAGS, true)) {
                $this->fail($class, 'constant ' . $constant->name . ' has flag ' . $flag . ', which is not supported');
            }
        }
        if (in_array('enum_case', $constant->flags, true)) {
            $this->fail($class, 'constant ' . $constant->name . ' is an enum case');
        }
        if (in_array('deprecated', $constant->flags, true)) {
            $this->fail($class, 'constant ' . $constant->name . ' is deprecated, but the attribute is not in the IR');
        }
        if (preg_match(self::IDENTIFIER, $constant->name) !== 1) {
            $this->fail($class, 'constant ' . $constant->name . ' cannot be rendered');
        }
        $visibility = array_values(array_intersect($constant->flags, ['public', 'protected', 'private']));
        if (count($visibility) !== 1) {
            $this->fail($class, 'constant ' . $constant->name . ' has no single visibility');
        }
        $parts = [];
        if (in_array('final', $constant->flags, true)) {
            $parts[] = 'final';
        }
        $parts[] = $visibility[0];
        $parts[] = 'const';
        $type = $this->types->render(
            $constant->type,
            'the type of constant ' . $constant->name . ' on ' . $class->kind . ' ' . $class->name,
            0,
            $names,
        );
        if ($type !== null) {
            $parts[] = $type;
        }
        $parts[] = $constant->name;
        try {
            $value = $this->literals->renderLiteral($constant->value, $class->lineStart, $names);
        } catch (SourceGenerationException $exception) {
            $this->fail($class, 'constant ' . $constant->name . ' cannot be rendered: ' . $exception->getMessage());
        }

        return str_repeat('    ', $indent) . implode(' ', $parts) . ' = ' . $value . ';';
    }

    private function propertyDeclaration(
        ClassDeclaration $class,
        ClassProperty $property,
        int $indent,
        ?NameQualifier $names,
    ): string {
        $modifiers = $this->propertyModifiers($class, $property);
        $type = $this->types->render(
            $property->type,
            'the type of property $' . $property->name . ' on ' . $class->kind . ' ' . $class->name,
            0,
            $names,
        );
        $text = $modifiers . ($type === null ? '' : ' ' . $type) . ' $' . $property->name;
        if ($property->defaultValue !== null) {
            try {
                $value = $this->literals->renderLiteral($property->defaultValue, $class->lineStart, $names);
            } catch (SourceGenerationException $exception) {
                $this->fail($class, 'default value of $' . $property->name . ' cannot be rendered: ' . $exception->getMessage());
            }
            $text .= ' = ' . $value;
        }

        return str_repeat('    ', $indent) . $text . ';';
    }

    private function propertyModifiers(ClassDeclaration $class, ClassProperty $property): string
    {
        foreach ($property->flags as $flag) {
            if (!in_array($flag, self::PROPERTY_FLAGS, true)) {
                $this->fail($class, 'property $' . $property->name . ' has flag ' . $flag . ', which is not supported');
            }
        }
        if (preg_match(self::IDENTIFIER, $property->name) !== 1) {
            $this->fail($class, 'property $' . $property->name . ' cannot be rendered');
        }
        if ($property->hooks !== []) {
            $this->fail($class, 'property $' . $property->name . ' has hooks, which are not supported');
        }
        if (in_array('abstract', $property->flags, true)) {
            $this->fail($class, 'property $' . $property->name . ' is abstract, which is not supported');
        }
        if (in_array('virtual', $property->flags, true)) {
            $this->fail($class, 'property $' . $property->name . ' is virtual, which is not supported');
        }
        $visibility = array_values(array_intersect($property->flags, ['public', 'protected', 'private']));
        if (count($visibility) !== 1) {
            $this->fail($class, 'property $' . $property->name . ' has no single visibility');
        }
        $set = array_values(array_intersect($property->flags, ['public_set', 'protected_set', 'private_set']));
        if (count($set) > 1) {
            $this->fail($class, 'property $' . $property->name . ' has more than one set visibility');
        }
        $readonly = in_array('readonly', $property->flags, true);
        $static = in_array('static', $property->flags, true);
        if ($static && $readonly) {
            $this->fail($class, 'property $' . $property->name . ' is static and readonly');
        }
        $privateSet = in_array('private_set', $property->flags, true);
        if (in_array('final', $property->flags, true) && !$privateSet) {
            $this->fail($class, 'property $' . $property->name . ' is final, which has no declaration spelling');
        }
        $parts = [$visibility[0]];
        if ($set !== [] && !($readonly && $set[0] === 'protected_set')) {
            $parts[] = match ($set[0]) {
                'public_set' => 'public(set)',
                'protected_set' => 'protected(set)',
                'private_set' => 'private(set)',
                default => $this->fail($class, 'property $' . $property->name . ' has an unknown set visibility'),
            };
        }
        if ($static) {
            $parts[] = 'static';
        }
        if ($readonly) {
            $parts[] = 'readonly';
        }

        return implode(' ', $parts);
    }

    private function qualified(ClassDeclaration $class, string $name, ?NameQualifier $names): string
    {
        if (preg_match(self::QUALIFIED_NAME, $name) !== 1) {
            $this->fail($class, 'name ' . $name . ' cannot be rendered');
        }
        if ($names === null) {
            return $name;
        }

        return $names->symbol($name);
    }

    /**
     * @param list<array<string, mixed>> $attributes
     */
    private function hasAttribute(array $attributes, string $short): bool
    {
        foreach ($attributes as $attribute) {
            $name = is_array($attribute) ? ($attribute['name'] ?? null) : null;
            if (is_string($name) && strcasecmp($this->tail($name), $short) === 0) {
                return true;
            }
        }

        return false;
    }

    private function tail(string $name): string
    {
        $separator = strrpos($name, '\\');

        return $separator === false ? $name : substr($name, $separator + 1);
    }

    private function sameType(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        if (!is_array($left) || !is_array($right) || array_is_list($left) || array_is_list($right)) {
            return false;
        }
        $leftKeys = array_keys($left);
        $rightKeys = array_keys($right);
        sort($leftKeys);
        sort($rightKeys);
        if ($leftKeys !== $rightKeys) {
            return false;
        }
        foreach ($leftKeys as $key) {
            if ($key === 'members') {
                if (!is_array($left['members']) || !is_array($right['members']) || array_is_list($left['members']) === false || array_is_list($right['members']) === false) {
                    return false;
                }
                if (count($left['members']) !== count($right['members'])) {
                    return false;
                }
                foreach ($left['members'] as $index => $member) {
                    if (!$this->sameType($member, $right['members'][$index])) {
                        return false;
                    }
                }
                continue;
            }
            if ($left[$key] !== $right[$key]) {
                return false;
            }
        }

        return true;
    }

    private function fail(ClassDeclaration $class, string $reason): never
    {
        throw new SourceGenerationException('Cannot generate PHP for ' . $class->kind . ' ' . $class->name . ': ' . $reason . '.');
    }
}
