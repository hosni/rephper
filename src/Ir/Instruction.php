<?php

declare(strict_types=1);

namespace Rephper\Ir;

final class Instruction
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly int $index,
        public readonly string $opcode,
        public readonly int $opcodeNumber,
        public readonly int $line,
        public readonly Operand $op1,
        public readonly Operand $op2,
        public readonly Operand $result,
        public readonly int $extendedValue,
        public readonly ?int $jumpTarget,
        public readonly array $extra,
    ) {
    }
}
