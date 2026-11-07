<?php

declare(strict_types=1);

namespace Rephper\Ir;

/**
 * One property declared on a class.
 *
 * `defaultValue` is null when the slot is uninitialized. A PHP null default
 * is a literal of type `null`, including the implicit null of an untyped
 * property. Inherited properties are not stored on the child.
 */
final class ClassProperty
{
    /**
     * @param list<string> $flags
     * @param array<string, mixed>|null $type
     * @param list<string> $hooks
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $name,
        public readonly array $flags,
        public readonly ?array $type,
        public readonly ?Literal $defaultValue,
        public readonly array $hooks,
        public readonly array $extra,
    ) {
    }
}
