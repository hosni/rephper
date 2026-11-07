<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

/**
 * Prints resolved names so they do not bind again inside a namespace.
 *
 * The compiler stores the resolved name without a leading backslash. Inside
 * `namespace App`, that spelling would be resolved relative to App. A leading
 * backslash keeps the resolved target. Builtin types and the relative class
 * keywords stay unqualified. An unqualified constant (`attr` bit 2048, the
 * compiler's IS_CONSTANT_UNQUALIFIED_IN_NAMESPACE) keeps the fallback to the
 * global constant, so only its unqualified tail is printed.
 */
final class NameQualifier
{
    private const UNQUALIFIED_IN_NAMESPACE = 2048;

    /** @var list<string> */
    private const BUILTINS = [
        'int', 'float', 'string', 'bool', 'array', 'object', 'callable', 'iterable',
        'mixed', 'void', 'never', 'false', 'true', 'null', 'resource',
    ];

    /** @var list<string> */
    private const RELATIVE = ['self', 'parent', 'static'];

    private const IDENTIFIER = '/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/';

    private const TYPE_NAME = '/\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*/';

    public function __construct(
        public readonly string $namespace,
    ) {
    }

    public function type(string $display): string
    {
        $qualified = preg_replace_callback(self::TYPE_NAME, function (array $match): string {
            return $this->symbol($match[0]);
        }, $display);
        if (!is_string($qualified)) {
            throw new SourceGenerationException('Cannot generate PHP for a type: the name cannot be qualified.');
        }

        return $qualified;
    }

    public function symbol(string $name): string
    {
        if (in_array($name, self::BUILTINS, true) || in_array(strtolower($name), self::RELATIVE, true)) {
            return $name;
        }
        if (str_starts_with($name, '\\')) {
            return $name;
        }

        return '\\' . $name;
    }

    public function unqualifiedFunction(string $name): ?string
    {
        $prefix = $this->namespace . '\\';
        if (!str_starts_with($name, $prefix)) {
            return null;
        }
        $short = substr($name, strlen($prefix));
        if (preg_match(self::IDENTIFIER, $short) !== 1) {
            return null;
        }

        return $short;
    }

    public function constant(string $name, int $attr): ?string
    {
        if (($attr & self::UNQUALIFIED_IN_NAMESPACE) === 0) {
            return $this->symbol($name);
        }
        $separator = strrpos($name, '\\');
        $prefix = $separator === false ? '' : substr($name, 0, $separator);
        if ($prefix !== $this->namespace) {
            return null;
        }
        $short = $separator === false ? $name : substr($name, $separator + 1);
        if (preg_match(self::IDENTIFIER, $short) !== 1) {
            return null;
        }

        return $short;
    }
}
