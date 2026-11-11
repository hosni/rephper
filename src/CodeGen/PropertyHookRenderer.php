<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

use Rephper\Ir\ClassDeclaration;
use Rephper\Ir\ClassProperty;
use Rephper\Ir\OpArray;

/**
 * Renders one property's locally declared hooks.
 *
 * The signature comes from the hook op array. The body reuses the statement
 * renderer. `get` and `set` are spelled in lowercase. A short `=>` body is not
 * in the IR, so a concrete hook is a brace block. Nothing from a rejected hook
 * is returned.
 */
final class PropertyHookRenderer
{
    private const IDENTIFIER = '/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/';

    /** @var list<string> */
    private const FLAGS = [
        'public', 'protected', 'private', 'static', 'final', 'abstract',
        'has_type_hints', 'deprecated', 'return_reference', 'has_return_type',
        'variadic', 'has_finally', 'early_binding', 'uses_this', 'constructor',
        'override', 'closure', 'fake_closure', 'generator', 'strict_types', 'top_level',
    ];

    public function __construct(
        private readonly PhpSourceGenerator $source = new PhpSourceGenerator(),
        private readonly TypeRenderer $types = new TypeRenderer(),
        private readonly AttributeRenderer $attributes = new AttributeRenderer(),
    ) {
    }

    /**
     * @param list<PropertyHookDeclaration> $hooks op-array order
     */
    public function renderBlock(
        ClassDeclaration $class,
        ClassProperty $property,
        array $hooks,
        int $indent,
        ?NameQualifier $names,
    ): string {
        if ($hooks === []) {
            $this->fail($class, $property->name, 'get', 'has no hooks to render');
        }
        $parts = [];
        foreach ($hooks as $hook) {
            $parts[] = $this->renderHook($class, $property, $hook, $indent + 1, $names);
        }
        $pad = str_repeat('    ', $indent);

        return "{\n" . implode("\n", $parts) . "\n" . $pad . '}';
    }

    private function renderHook(
        ClassDeclaration $class,
        ClassProperty $property,
        PropertyHookDeclaration $declaration,
        int $indent,
        ?NameQualifier $names,
    ): string {
        $opArray = $declaration->opArray;
        $meta = $opArray->propertyHook;
        $kind = $meta === null ? '' : $meta->hook;
        if ($meta === null || ($kind !== 'get' && $kind !== 'set') || $meta->property !== $property->name) {
            $this->fail($class, $property->name, $kind === '' ? 'get' : $kind, 'metadata is inconsistent');
        }
        $this->assertFlags($class, $property, $opArray, $kind);
        $this->assertParameters($class, $property, $opArray, $kind);
        $this->assertReturn($class, $property, $opArray, $kind);
        $this->assertAttributes($class, $property, $opArray, $kind);

        $abstract = in_array('abstract', $opArray->flags, true);
        $final = in_array('final', $opArray->flags, true);
        if ($abstract && $final) {
            $this->fail($class, $property->name, $kind, 'is final and abstract');
        }
        if ($final && (in_array('private', $opArray->flags, true) || in_array('private', $property->flags, true))) {
            $this->fail($class, $property->name, $kind, 'is final and private');
        }
        if ($class->kind === 'interface' && !$abstract) {
            $this->fail($class, $property->name, $kind, 'is not abstract');
        }
        if ($abstract && !in_array('abstract', $property->flags, true)) {
            $this->fail($class, $property->name, $kind, 'is abstract, but the property is not');
        }
        if ($abstract && $declaration->body->statements !== []) {
            $this->fail($class, $property->name, $kind, 'abstract hook has a body');
        }

        $label = $property->name . '::' . $kind;
        $pad = str_repeat('    ', $indent);
        $lines = $this->commentLines($opArray->docComment, $class, $property->name, $kind, $indent);
        foreach ($this->attributes->render($opArray->attributes, 0, $label, 'property hook', $opArray->lineStart, $names) as $attribute) {
            $lines[] = $pad . $attribute;
        }
        $signature = ($final ? 'final ' : '')
            . ($kind === 'get' && in_array('return_reference', $opArray->flags, true) ? '&' : '')
            . $kind;
        if ($kind === 'set') {
            $argument = $opArray->arguments[0] ?? null;
            if (!is_array($argument)) {
                $this->fail($class, $property->name, $kind, 'must have one parameter');
            }
            $signature .= '(' . $this->parameter($class, $property, $opArray, $argument, $names) . ')';
        }
        if ($abstract) {
            $lines[] = $pad . $signature . ';';

            return implode("\n", $lines);
        }

        $lines[] = $pad . $signature;
        $source = $names === null ? $this->source : new PhpSourceGenerator($names);
        $body = $source->renderStatements($declaration->body->statements, $indent + 1);
        $text = implode("\n", $lines) . "\n" . $pad . "{\n";
        if ($body !== '') {
            $text .= $body . "\n";
        }
        $text .= $pad . '}';

        return $text;
    }

    private function assertFlags(ClassDeclaration $class, ClassProperty $property, OpArray $opArray, string $kind): void
    {
        foreach ($opArray->flags as $flag) {
            if (!in_array($flag, self::FLAGS, true)) {
                $this->fail($class, $property->name, $kind, 'has flag ' . $flag . ', which is not supported');
            }
        }
        $visibility = array_values(array_intersect($opArray->flags, ['public', 'protected', 'private']));
        if (count($visibility) !== 1) {
            $this->fail($class, $property->name, $kind, 'metadata is inconsistent');
        }
        foreach (['static', 'variadic', 'constructor', 'override', 'deprecated'] as $flag) {
            if (in_array($flag, $opArray->flags, true)) {
                $this->fail($class, $property->name, $kind, 'has flag ' . $flag . ', which is not supported');
            }
        }
        if (in_array('generator', $opArray->flags, true)) {
            $this->fail($class, $property->name, $kind, 'contains a generator, which is not supported');
        }
        if (in_array('closure', $opArray->flags, true) || in_array('fake_closure', $opArray->flags, true)) {
            $this->fail($class, $property->name, $kind, 'contains a closure, which is not supported');
        }
        if ($kind === 'set' && in_array('return_reference', $opArray->flags, true)) {
            $this->fail($class, $property->name, $kind, 'returns by reference');
        }
    }

    private function assertParameters(ClassDeclaration $class, ClassProperty $property, OpArray $opArray, string $kind): void
    {
        if ($kind === 'get') {
            if ($opArray->numArgs !== 0 || $opArray->requiredNumArgs !== 0 || $opArray->arguments !== []) {
                $this->fail($class, $property->name, $kind, 'has parameters');
            }
            if (in_array('has_type_hints', $opArray->flags, true)) {
                $this->fail($class, $property->name, $kind, 'metadata is inconsistent');
            }

            return;
        }
        if ($opArray->numArgs !== 1 || $opArray->requiredNumArgs !== 1 || count($opArray->arguments) !== 1) {
            $this->fail($class, $property->name, $kind, 'must have one parameter');
        }
        $argument = $opArray->arguments[0];
        if (!is_array($argument) || array_is_list($argument)) {
            $this->fail($class, $property->name, $kind, 'must have one parameter');
        }
        foreach (['name', 'type', 'send', 'variadic', 'promoted', 'default_literal'] as $key) {
            if (!array_key_exists($key, $argument)) {
                $this->fail($class, $property->name, $kind, 'parameter metadata is incomplete');
            }
        }
        if ($argument['variadic'] !== false) {
            $this->fail($class, $property->name, $kind, 'parameter is variadic');
        }
        if ($argument['promoted'] !== false) {
            $this->fail($class, $property->name, $kind, 'metadata is inconsistent');
        }
        if ($argument['send'] !== 'value') {
            $this->fail($class, $property->name, $kind, 'parameter is not passed by value');
        }
        if ($argument['default_literal'] !== null) {
            $this->fail($class, $property->name, $kind, 'parameter has a default value');
        }
        $typed = $argument['type'] !== null;
        if (in_array('has_type_hints', $opArray->flags, true) !== $typed) {
            $this->fail($class, $property->name, $kind, 'metadata is inconsistent');
        }
    }

    private function assertReturn(ClassDeclaration $class, ClassProperty $property, OpArray $opArray, string $kind): void
    {
        $recorded = in_array('has_return_type', $opArray->flags, true);
        if ($recorded !== ($opArray->returnType !== null)) {
            $this->fail($class, $property->name, $kind, 'return type metadata is incomplete');
        }
        if ($kind === 'set') {
            if (!$this->isVoid($opArray->returnType)) {
                $this->fail($class, $property->name, $kind, 'return type metadata is inconsistent');
            }

            return;
        }
        if (!$this->sameType($property->type, $opArray->returnType)) {
            $this->fail($class, $property->name, $kind, 'return type metadata is inconsistent');
        }
    }

    private function assertAttributes(ClassDeclaration $class, ClassProperty $property, OpArray $opArray, string $kind): void
    {
        $limit = $kind === 'set' ? 1 : 0;
        foreach ($opArray->attributes as $attribute) {
            if (!is_array($attribute) || !array_key_exists('offset', $attribute) || !is_int($attribute['offset'])) {
                $this->fail($class, $property->name, $kind, 'attribute target is not in the IR');
            }
            if ($attribute['offset'] < 0 || $attribute['offset'] > $limit) {
                $this->fail($class, $property->name, $kind, 'attribute target is not on this hook');
            }
        }
    }

    /**
     * @param array<mixed> $argument
     */
    private function parameter(
        ClassDeclaration $class,
        ClassProperty $property,
        OpArray $opArray,
        array $argument,
        ?NameQualifier $names,
    ): string {
        $name = $argument['name'] ?? null;
        if (!is_string($name) || preg_match(self::IDENTIFIER, $name) !== 1) {
            $this->fail($class, $property->name, 'set', 'parameter name cannot be rendered');
        }
        $label = $property->name . '::set';
        $attributes = $this->attributes->render(
            $opArray->attributes,
            1,
            $label,
            'property hook',
            $opArray->lineStart,
            $names,
        );
        try {
            $type = $this->types->render(
                $argument['type'] ?? null,
                'the type of parameter $' . $name . ' on property hook ' . $class->name . '::$' . $label,
                0,
                $names,
            );
        } catch (SourceGenerationException $exception) {
            $this->fail($class, $property->name, 'set', 'parameter cannot be rendered: ' . $exception->getMessage());
        }
        $head = $type === null ? '' : $type . ' ';

        return ($attributes === [] ? '' : implode(' ', $attributes) . ' ') . $head . '$' . $name;
    }

    /**
     * @return list<string>
     */
    private function commentLines(?string $comment, ClassDeclaration $class, string $property, string $kind, int $indent): array
    {
        if ($comment === null) {
            return [];
        }
        $normalized = str_replace("\r\n", "\n", $comment);
        if (str_contains($normalized, "\r") || preg_match('/\A\/\*\*(?:[^*]|\*(?!\/))*\*\/\z/s', $normalized) !== 1) {
            $this->fail($class, $property, $kind, 'doc comment cannot be rendered');
        }
        $pad = str_repeat('    ', $indent);
        $lines = [];
        foreach (explode("\n", $normalized) as $line) {
            $lines[] = $pad . $line;
        }

        return $lines;
    }

    private function isVoid(mixed $type): bool
    {
        return is_array($type)
            && !array_is_list($type)
            && count($type) === 3
            && ($type['display'] ?? null) === 'void'
            && ($type['nullable'] ?? null) === false
            && ($type['kind'] ?? null) === 'builtin';
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
                if (!is_array($left['members']) || !is_array($right['members']) || !array_is_list($left['members']) || !array_is_list($right['members'])) {
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

    private function fail(ClassDeclaration $class, string $property, string $kind, string $reason): never
    {
        throw new SourceGenerationException(
            'Cannot generate PHP for property hook ' . $class->name . '::$' . $property . '::' . $kind . ': ' . $reason . '.'
        );
    }
}
