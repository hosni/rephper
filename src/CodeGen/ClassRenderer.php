<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

use Rephper\Ir\ClassConstant;
use Rephper\Ir\ClassDeclaration;
use Rephper\Ir\ClassProperty;
use Rephper\Ir\Literal as IrLiteral;
use Rephper\Ir\OpArray;
use Rephper\Ir\TraitAlias;
use Rephper\Ir\TraitPrecedence;

/**
 * Renders one class, interface, trait, or enum from its IR declaration and method bodies.
 *
 * A trait use comes first. Constants, then properties, then methods. Promoted
 * properties are parameters of the constructor, not a second declaration.
 * Hooks declared in this class are part of that property or parameter. A hook
 * the property lists but this class does not declare is left to the parent.
 * Precedences and aliases are separate IR lists, so every precedence is printed
 * before any alias, and each list keeps its own order. An enum walks that same
 * constant list, printing cases and ordinary constants in their relative order.
 * A member the IR cannot spell fails the declaration.
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
        private readonly PropertyHookRenderer $hookRenderer = new PropertyHookRenderer(),
    ) {
    }

    /**
     * @param list<FunctionDeclaration> $methods
     * @param list<PropertyHookDeclaration> $propertyHooks op-array order
     */
    public function render(
        ClassDeclaration $class,
        array $methods,
        int $indent,
        string $declaredName,
        ?NameQualifier $names,
        array $propertyHooks = [],
    ): string {
        if (!in_array($class->kind, ['class', 'interface', 'trait', 'enum'], true)) {
            $this->fail($class, $class->kind . ' is not supported');
        }
        if (preg_match(self::IDENTIFIER, $declaredName) !== 1) {
            $this->fail($class, 'name cannot be rendered');
        }
        $this->assertFlags($class);
        $this->assertMembers($class);
        $groupedHooks = $this->groupHooks($class, $propertyHooks);
        $constructor = $this->constructor($class, $methods);
        $promoted = $this->promotedParameters($class, $constructor, $groupedHooks, $indent + 1, $names);

        $pad = str_repeat('    ', $indent);
        $lines = $this->commentLines($class->docComment, $class, $class->kind, $indent);
        foreach ($this->classAttributes($class, $names) as $attribute) {
            $lines[] = $pad . $attribute;
        }
        $lines[] = $pad . $this->header($class, $declaredName, $names);
        $members = [];
        $use = $this->traitUse($class, $indent + 1, $names);
        if ($use !== null) {
            $members[] = $use;
        }
        foreach ($class->constants as $constant) {
            $members[] = in_array('enum_case', $constant->flags, true)
                ? $this->enumCase($class, $constant, $indent + 1, $names)
                : $this->constantDeclaration($class, $constant, $indent + 1, $names);
        }
        foreach ($class->properties as $property) {
            if ($class->kind === 'enum' && $this->namedEngineProperty($class, $property)) {
                if (!$this->engineEnumProperty($class, $property)) {
                    $this->fail($class, 'property $' . $property->name . ' does not match the engine property');
                }
                continue;
            }
            if (in_array('promoted', $property->flags, true)) {
                continue;
            }
            if ($class->kind === 'enum') {
                $this->fail($class, 'property $' . $property->name . ' cannot be declared on an enum');
            }
            $members[] = $this->propertyDeclaration(
                $class,
                $property,
                $groupedHooks[$property->name] ?? [],
                $indent + 1,
                $names,
            );
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
        if ($class->kind === 'enum') {
            $this->assertEnum($class);
        } elseif ($class->enumBacking !== null) {
            $this->fail($class, 'enum backing is not supported');
        }
        if ($class->kind === 'trait') {
            if ($class->parent !== null || $class->interfaces !== []) {
                $this->fail($class, 'cannot extend or implement another type');
            }
            if (
                in_array('final', $class->flags, true)
                || in_array('abstract', $class->flags, true)
                || in_array('readonly', $class->flags, true)
            ) {
                $this->fail($class, 'modifier is not supported');
            }
        }
        if ($class->kind === 'interface' && $class->traits !== []) {
            $this->fail($class, 'cannot use traits');
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
     * @param array<string, list<PropertyHookDeclaration>> $groupedHooks
     * @return array{order: list<string>, map: array<string, PromotedProperty>}
     */
    private function promotedParameters(
        ClassDeclaration $class,
        ?OpArray $constructor,
        array $groupedHooks,
        int $indent,
        ?NameQualifier $names,
    ): array {
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
            $map[$name] = new PromotedProperty(
                $this->propertyModifiers($class, $property),
                $this->hookBlock($class, $property, $groupedHooks[$name] ?? [], $indent, $names),
            );
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
        if ($class->kind === 'enum' && $isAbstract) {
            $this->fail($class, 'method ' . $name . ' is abstract');
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
        if ($class->kind === 'trait') {
            return 'trait ' . $declaredName;
        }
        if ($class->kind === 'enum') {
            return $this->enumHeader($class, $declaredName, $names);
        }
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

    private function assertEnum(ClassDeclaration $class): void
    {
        if ($class->enumBacking !== null && $class->enumBacking !== 'int' && $class->enumBacking !== 'string') {
            $this->fail($class, 'enum backing is not supported');
        }
        if ($class->parent !== null) {
            $this->fail($class, 'cannot extend another type');
        }
        if (!in_array('final', $class->flags, true)) {
            $this->fail($class, 'final flag is missing');
        }
        foreach (['abstract', 'implicit_abstract', 'readonly', 'linked', 'allow_dynamic_properties'] as $flag) {
            if (in_array($flag, $class->flags, true)) {
                $this->fail($class, 'flag ' . $flag . ' is not supported');
            }
        }
    }

    private function enumHeader(ClassDeclaration $class, string $declaredName, ?NameQualifier $names): string
    {
        $header = 'enum ' . $declaredName;
        if ($class->enumBacking !== null) {
            $header .= ': ' . $class->enumBacking;
        }
        $interfaces = $this->declaredEnumInterfaces($class);
        if ($interfaces === []) {
            return $header;
        }
        $rendered = [];
        foreach ($interfaces as $interface) {
            $rendered[] = $this->qualified($class, $interface, $names);
        }

        return $header . ' implements ' . implode(', ', $rendered);
    }

    /**
     * The engine appends `UnitEnum`, and `BackedEnum` after it for a backed
     * enum. An earlier copy was written in the source and stays.
     *
     * @return list<string>
     */
    private function declaredEnumInterfaces(ClassDeclaration $class): array
    {
        $interfaces = $class->interfaces;
        if ($class->enumBacking === null) {
            $last = $interfaces === [] ? null : $interfaces[array_key_last($interfaces)];
            if ($last !== 'UnitEnum') {
                $this->fail($class, 'engine interface UnitEnum is missing');
            }
            array_pop($interfaces);

            return $interfaces;
        }
        $count = count($interfaces);
        if ($count < 2 || $interfaces[$count - 2] !== 'UnitEnum' || $interfaces[$count - 1] !== 'BackedEnum') {
            $this->fail($class, 'engine interfaces UnitEnum and BackedEnum are missing');
        }

        return array_slice($interfaces, 0, -2);
    }

    private function namedEngineProperty(ClassDeclaration $class, ClassProperty $property): bool
    {
        if ($property->name === 'name') {
            return true;
        }

        return $property->name === 'value' && $class->enumBacking !== null;
    }

    private function engineEnumProperty(ClassDeclaration $class, ClassProperty $property): bool
    {
        $display = $property->name === 'name' ? 'string' : $class->enumBacking;
        if ($display !== 'string' && $display !== 'int') {
            return false;
        }
        $flags = $property->flags;
        sort($flags);

        return $flags === ['protected_set', 'public', 'readonly']
            && $property->defaultValue === null
            && $property->hooks === []
            && $property->attributes === []
            && $property->docComment === null
            && $property->extra === []
            && $this->builtinType($property->type, $display);
    }

    /**
     * @param array<string, mixed>|null $type
     */
    private function builtinType(?array $type, string $display): bool
    {
        return is_array($type)
            && !array_is_list($type)
            && count($type) === 3
            && ($type['display'] ?? null) === $display
            && ($type['nullable'] ?? null) === false
            && ($type['kind'] ?? null) === 'builtin';
    }

    private function enumCase(
        ClassDeclaration $class,
        ClassConstant $constant,
        int $indent,
        ?NameQualifier $names,
    ): string {
        if ($class->kind !== 'enum') {
            $this->fail($class, 'constant ' . $constant->name . ' is an enum case');
        }
        foreach ($constant->flags as $flag) {
            if (!in_array($flag, ['public', 'enum_case', 'deprecated'], true)) {
                $this->fail($class, 'case ' . $constant->name . ' has flag ' . $flag . ', which is not supported');
            }
        }
        if (!in_array('public', $constant->flags, true) || !in_array('enum_case', $constant->flags, true)) {
            $this->fail($class, 'case ' . $constant->name . ' metadata is incomplete');
        }
        if (count(array_intersect($constant->flags, ['public', 'protected', 'private'])) !== 1) {
            $this->fail($class, 'case ' . $constant->name . ' has a visibility modifier');
        }
        if (in_array('deprecated', $constant->flags, true) && !$this->hasAttribute($constant->attributes, 'Deprecated')) {
            $this->fail($class, 'case ' . $constant->name . ' is deprecated, but the attribute is not in the IR');
        }
        if ($constant->type !== null) {
            $this->fail($class, 'case ' . $constant->name . ' has a type');
        }
        if (preg_match(self::IDENTIFIER, $constant->name) !== 1) {
            $this->fail($class, 'case ' . $constant->name . ' cannot be rendered');
        }
        $value = $this->enumCaseValue($class, $constant);
        $text = 'case ' . $constant->name;
        if ($value !== null) {
            try {
                $rendered = $this->literals->renderLiteral(
                    new IrLiteral('constant_ast', null, false, false, [], $value, []),
                    $class->lineStart,
                    $names,
                );
            } catch (SourceGenerationException $exception) {
                $this->fail($class, 'case ' . $constant->name . ' cannot be rendered: ' . $exception->getMessage());
            }
            $text .= ' = ' . $rendered;
        }
        $lines = $this->commentLines($constant->docComment, $class, 'case ' . $constant->name, $indent);
        foreach ($this->memberAttributes($class, $constant->attributes, 'case ' . $constant->name, $indent, $names) as $attribute) {
            $lines[] = $attribute;
        }
        $lines[] = str_repeat('    ', $indent) . $text . ';';

        return implode("\n", $lines);
    }

    /**
     * @return array<mixed>|null
     */
    private function enumCaseValue(ClassDeclaration $class, ClassConstant $constant): ?array
    {
        $ast = $constant->value->ast;
        if ($constant->value->type !== 'constant_ast' || !is_array($ast) || array_is_list($ast) || ($ast['kind'] ?? null) !== 'CONST_ENUM_INIT') {
            $this->fail($class, 'case ' . $constant->name . ' is not an enum case value');
        }
        $children = $ast['children'] ?? null;
        if (!is_array($children) || !array_is_list($children) || count($children) !== 3) {
            $this->fail($class, 'case ' . $constant->name . ' value cannot be rendered');
        }
        $this->assertCaseZval($class, $children[0], $class->name, $constant->name, 'enum');
        $this->assertCaseZval($class, $children[1], $constant->name, $constant->name, 'case');
        if ($class->enumBacking === null) {
            if ($children[2] !== null) {
                $this->fail($class, 'case ' . $constant->name . ' has a value');
            }

            return null;
        }
        if (!is_array($children[2]) || array_is_list($children[2])) {
            $this->fail($class, 'case ' . $constant->name . ' value cannot be rendered');
        }

        return $children[2];
    }

    private function assertCaseZval(ClassDeclaration $class, mixed $node, string $expected, string $case, string $role): void
    {
        $value = is_array($node) ? ($node['value'] ?? null) : null;
        if (
            !is_array($node)
            || array_is_list($node)
            || ($node['kind'] ?? null) !== 'ZVAL'
            || !is_array($value)
            || ($value['type'] ?? null) !== 'string'
            || ($value['value'] ?? null) !== $expected
        ) {
            $this->fail($class, 'case ' . $case . ' does not name its ' . $role);
        }
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
        if (in_array('deprecated', $constant->flags, true) && !$this->hasAttribute($constant->attributes, 'Deprecated')) {
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
        $lines = $this->commentLines($constant->docComment, $class, 'constant ' . $constant->name, $indent);
        foreach ($this->memberAttributes($class, $constant->attributes, 'constant ' . $constant->name, $indent, $names) as $attribute) {
            $lines[] = $attribute;
        }
        $lines[] = str_repeat('    ', $indent) . implode(' ', $parts) . ' = ' . $value . ';';

        return implode("\n", $lines);
    }

    /**
     * @param list<PropertyHookDeclaration> $hooks
     */
    private function propertyDeclaration(
        ClassDeclaration $class,
        ClassProperty $property,
        array $hooks,
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
        $lines = $this->commentLines($property->docComment, $class, 'property $' . $property->name, $indent);
        foreach ($this->memberAttributes($class, $property->attributes, 'property $' . $property->name, $indent, $names) as $attribute) {
            $lines[] = $attribute;
        }
        $block = $this->hookBlock($class, $property, $hooks, $indent, $names);
        $lines[] = str_repeat('    ', $indent) . $text . ($block === null ? ';' : ' ' . $block);

        return implode("\n", $lines);
    }

    /**
     * @param list<PropertyHookDeclaration> $hooks op-array order for this class
     * @return array<string, list<PropertyHookDeclaration>>
     */
    private function groupHooks(ClassDeclaration $class, array $hooks): array
    {
        $properties = [];
        foreach ($class->properties as $property) {
            $properties[$property->name] = $property;
        }
        $grouped = [];
        $seen = [];
        foreach ($hooks as $hook) {
            if (!$hook instanceof PropertyHookDeclaration) {
                $this->fail($class, 'property hook metadata is incomplete');
            }
            $meta = $hook->opArray->propertyHook;
            $scope = $hook->opArray->scope;
            $kind = $meta === null ? '' : $meta->hook;
            $name = $meta === null ? '' : $meta->property;
            if (
                $meta === null
                || $scope === null
                || ($kind !== 'get' && $kind !== 'set')
                || $scope->name !== $class->name
                || $scope->kind !== $class->kind
            ) {
                $label = $name === '' ? '(unknown)' : $name . '::' . $kind;
                $this->fail($class, 'property hook ' . $label . ' metadata is inconsistent');
            }
            $property = $properties[$name] ?? null;
            if (!$property instanceof ClassProperty) {
                $this->fail($class, 'property hook ' . $name . '::' . $kind . ' does not match a property');
            }
            if (isset($seen[$name][$kind])) {
                $this->fail($class, 'property $' . $name . ' declares hook ' . $kind . ' more than once');
            }
            $seen[$name][$kind] = true;
            $grouped[$name][] = $hook;
        }
        foreach ($class->properties as $property) {
            $this->assertHookList($class, $property, $grouped[$property->name] ?? []);
        }

        return $grouped;
    }

    /**
     * @param list<PropertyHookDeclaration> $hooks
     */
    private function assertHookList(ClassDeclaration $class, ClassProperty $property, array $hooks): void
    {
        $present = [];
        foreach ($hooks as $hook) {
            $kind = $hook->opArray->propertyHook === null ? '' : $hook->opArray->propertyHook->hook;
            $present[$kind] = true;
        }
        $listed = [];
        foreach ($property->hooks as $hook) {
            if ($hook !== 'get' && $hook !== 'set') {
                $this->fail($class, 'property $' . $property->name . ' has hook ' . $hook . ', which is not supported');
            }
            if (isset($listed[$hook])) {
                $this->fail($class, 'property $' . $property->name . ' lists hook ' . $hook . ' more than once');
            }
            $listed[$hook] = true;
            if (!isset($present[$hook]) && !($class->kind === 'class' && $class->parent !== null)) {
                $this->fail($class, 'property $' . $property->name . ' lists hook ' . $hook . ', which is not declared in this class');
            }
        }
        foreach (array_keys($present) as $hook) {
            if (!isset($listed[$hook])) {
                $this->fail($class, 'property $' . $property->name . ' declares hook ' . $hook . ', which is not listed on the property');
            }
        }
        $abstract = in_array('abstract', $property->flags, true);
        $final = in_array('final', $property->flags, true);
        $virtual = in_array('virtual', $property->flags, true);
        $hooked = $property->hooks !== [] || $hooks !== [];
        if ($virtual && $property->defaultValue !== null) {
            $this->fail($class, 'property $' . $property->name . ' is virtual and has a default value');
        }
        if ($abstract && $final) {
            $this->fail($class, 'property $' . $property->name . ' is final and abstract');
        }
        if (in_array('readonly', $property->flags, true) && $hooked) {
            $this->fail($class, 'property $' . $property->name . ' is readonly and has hooks');
        }
        if (in_array('static', $property->flags, true) && $hooked) {
            $this->fail($class, 'property $' . $property->name . ' is static and has hooks');
        }
        if (in_array('promoted', $property->flags, true) && $abstract) {
            $this->fail($class, 'property $' . $property->name . ' is promoted and abstract');
        }
        if (in_array('promoted', $property->flags, true) && $virtual) {
            $this->fail($class, 'property $' . $property->name . ' is promoted and virtual');
        }
        if ($class->kind === 'interface' && $property->hooks === []) {
            $this->fail($class, 'interface property $' . $property->name . ' has no hooks');
        }
        if ($abstract) {
            $found = false;
            foreach ($hooks as $hook) {
                if (in_array('abstract', $hook->opArray->flags, true)) {
                    $found = true;
                }
            }
            if (!$found) {
                $this->fail($class, 'property $' . $property->name . ' is abstract and has no abstract hook');
            }
            if (
                $class->kind === 'class'
                && !in_array('abstract', $class->flags, true)
                && !in_array('implicit_abstract', $class->flags, true)
            ) {
                $this->fail($class, 'property $' . $property->name . ' is abstract, but the class is not');
            }
        }
    }

    /**
     * @param list<PropertyHookDeclaration> $hooks
     */
    private function hookBlock(
        ClassDeclaration $class,
        ClassProperty $property,
        array $hooks,
        int $indent,
        ?NameQualifier $names,
    ): ?string {
        if ($hooks === []) {
            return null;
        }

        return $this->hookRenderer->renderBlock($class, $property, $hooks, $indent, $names);
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
        $abstract = in_array('abstract', $property->flags, true);
        $parts = [];
        if (in_array('final', $property->flags, true) && !$privateSet) {
            $parts[] = 'final';
        }
        if ($abstract && $class->kind !== 'interface') {
            $parts[] = 'abstract';
        }
        $parts[] = $visibility[0];
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

    private function traitUse(ClassDeclaration $class, int $indent, ?NameQualifier $names): ?string
    {
        if ($class->traits === [] && $class->traitAliases === [] && $class->traitPrecedences === []) {
            return null;
        }
        if ($class->traits === []) {
            $this->fail($class, 'trait adaptations are not attached to a used trait');
        }
        $traits = [];
        foreach ($class->traits as $trait) {
            $traits[] = $this->qualified($class, $trait, $names);
        }
        $rules = [];
        foreach ($class->traitPrecedences as $precedence) {
            if (!$precedence instanceof TraitPrecedence) {
                $this->fail($class, 'trait precedence metadata is incomplete');
            }
            $rules[] = $this->precedenceRule($class, $precedence, $names);
        }
        foreach ($class->traitAliases as $alias) {
            if (!$alias instanceof TraitAlias) {
                $this->fail($class, 'trait alias metadata is incomplete');
            }
            $rules[] = $this->aliasRule($class, $alias, $names);
        }
        $pad = str_repeat('    ', $indent);
        if ($rules === []) {
            return $pad . 'use ' . implode(', ', $traits) . ';';
        }
        $lines = [$pad . 'use ' . implode(', ', $traits) . ' {'];
        foreach ($rules as $rule) {
            $lines[] = $pad . '    ' . $rule . ';';
        }
        $lines[] = $pad . '}';

        return implode("\n", $lines);
    }

    private function precedenceRule(ClassDeclaration $class, TraitPrecedence $precedence, ?NameQualifier $names): string
    {
        $this->assertUsedTrait($class, $precedence->trait, 'precedence for ' . $precedence->trait . '::' . $precedence->method);
        if (preg_match(self::IDENTIFIER, $precedence->method) !== 1) {
            $this->fail($class, 'precedence method ' . $precedence->method . ' cannot be rendered');
        }
        if ($precedence->insteadOf === []) {
            $this->fail($class, 'precedence for ' . $precedence->method . ' excludes nothing');
        }
        $excluded = [];
        foreach ($precedence->insteadOf as $trait) {
            $this->assertUsedTrait($class, $trait, 'precedence for ' . $precedence->method);
            $excluded[] = $this->qualified($class, $trait, $names);
        }

        return $this->qualified($class, $precedence->trait, $names)
            . '::' . $precedence->method
            . ' insteadof ' . implode(', ', $excluded);
    }

    private function aliasRule(ClassDeclaration $class, TraitAlias $alias, ?NameQualifier $names): string
    {
        if (preg_match(self::IDENTIFIER, $alias->method) !== 1) {
            $this->fail($class, 'alias method ' . $alias->method . ' cannot be rendered');
        }
        if ($alias->trait !== null) {
            $this->assertUsedTrait($class, $alias->trait, 'alias for ' . $alias->trait . '::' . $alias->method);
        }
        if (count($alias->modifiers) > 1) {
            $this->fail($class, 'alias for ' . $alias->method . ' has more than one modifier');
        }
        $modifier = $alias->modifiers[0] ?? null;
        if ($modifier !== null && !in_array($modifier, ['public', 'protected', 'private', 'final'], true)) {
            $this->fail($class, 'alias for ' . $alias->method . ' has modifier ' . $modifier . ', which is not supported');
        }
        if ($alias->alias !== null && preg_match(self::IDENTIFIER, $alias->alias) !== 1) {
            $this->fail($class, 'alias ' . $alias->alias . ' cannot be rendered');
        }
        if ($alias->alias === null && $modifier === null) {
            $this->fail($class, 'alias for ' . $alias->method . ' does not change the method');
        }
        $target = $alias->trait === null
            ? $alias->method
            : $this->qualified($class, $alias->trait, $names) . '::' . $alias->method;
        $tail = 'as';
        if ($modifier !== null) {
            $tail .= ' ' . $modifier;
        }
        if ($alias->alias !== null) {
            $tail .= ' ' . $alias->alias;
        }

        return $target . ' ' . $tail;
    }

    private function assertUsedTrait(ClassDeclaration $class, string $name, string $owner): void
    {
        foreach ($class->traits as $trait) {
            if (strcasecmp($trait, $name) === 0) {
                return;
            }
        }
        $this->fail($class, $owner . ' is not a used trait');
    }

    /**
     * @return list<string>
     */
    private function commentLines(?string $comment, ClassDeclaration $class, string $owner, int $indent): array
    {
        if ($comment === null) {
            return [];
        }
        $normalized = str_replace("\r\n", "\n", $comment);
        if (str_contains($normalized, "\r") || preg_match('/\A\/\*\*(?:[^*]|\*(?!\/))*\*\/\z/s', $normalized) !== 1) {
            $this->fail($class, $owner . ' doc comment cannot be rendered');
        }
        $pad = str_repeat('    ', $indent);
        $lines = [];
        foreach (explode("\n", $normalized) as $line) {
            $lines[] = $pad . $line;
        }

        return $lines;
    }

    /**
     * @param list<mixed> $attributes
     * @return list<string>
     */
    private function memberAttributes(
        ClassDeclaration $class,
        array $attributes,
        string $owner,
        int $indent,
        ?NameQualifier $names,
    ): array {
        foreach ($attributes as $attribute) {
            if (!is_array($attribute) || !array_key_exists('offset', $attribute) || !is_int($attribute['offset'])) {
                $this->fail($class, $owner . ' attribute target is not in the IR');
            }
            if ($attribute['offset'] !== 0) {
                $this->fail($class, $owner . ' attribute target is not on this declaration');
            }
        }
        $pad = str_repeat('    ', $indent);
        $lines = [];
        foreach ($this->attributes->render($attributes, 0, $class->name, $class->kind, $class->lineStart, $names) as $attribute) {
            $lines[] = $pad . $attribute;
        }

        return $lines;
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
