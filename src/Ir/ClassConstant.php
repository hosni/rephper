<?php

declare(strict_types=1);

namespace Rephper\Ir;

/**
 * One constant declared on a class, interface, or enum.
 *
 * The value is the zval or constant AST captured at `pass_two()`. Enum cases
 * are constants too, and their values can still be `CONST_ENUM_INIT` nodes.
 */
final class ClassConstant
{
    /**
     * @param list<string> $flags
     * @param array<string, mixed>|null $type
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $name,
        public readonly array $flags,
        public readonly ?array $type,
        public readonly Literal $value,
        public readonly array $extra,
    ) {
    }
}
