<?php

declare(strict_types=1);

namespace Rephper\Ir;

final class Literal
{
    /**
     * @param list<array{key: Literal, value: Literal}> $elements
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $type,
        public readonly mixed $value,
        public readonly bool $binary,
        public readonly bool $recursive,
        public readonly array $elements,
        public readonly mixed $ast,
        public readonly array $extra,
    ) {
    }
}
