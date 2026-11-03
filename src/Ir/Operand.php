<?php

declare(strict_types=1);

namespace Rephper\Ir;

final class Operand
{
    /**
     * @param list<string> $smartBranch
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?int $index,
        public readonly ?string $name,
        public readonly ?int $target,
        public readonly ?int $value,
        public readonly ?int $id,
        public readonly bool $jumpTable,
        public readonly array $smartBranch,
        public readonly ?int $encoded,
        public readonly ?int $typeCode,
        public readonly array $extra,
    ) {
    }
}
