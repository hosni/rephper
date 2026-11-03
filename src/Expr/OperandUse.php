<?php

declare(strict_types=1);

namespace Rephper\Expr;

/**
 * One operand of an instruction whose opcode is not reconstructed.
 *
 * The fields copy the IR operand. `resolved` is set for a const, compiled
 * variable, temporary, or compiler variable. Other kinds stay as IR data.
 * A temporary's `resolved` expression is the same object stored for that slot.
 */
final class OperandUse
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
        public readonly ?Expression $resolved,
    ) {
    }
}
