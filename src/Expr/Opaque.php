<?php

declare(strict_types=1);

namespace Rephper\Expr;

/**
 * An instruction kept in order because its effect is not a reconstructed expression.
 *
 * The result operand records the written slot. It does not point back at this node.
 */
final class Opaque extends Expression
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        int $opline,
        int $line,
        public readonly string $opcode,
        public readonly int $opcodeNumber,
        public readonly int $extendedValue,
        public readonly ?int $jumpTarget,
        public readonly OperandUse $op1,
        public readonly OperandUse $op2,
        public readonly OperandUse $result,
        public readonly array $extra,
        public readonly string $reason,
    ) {
        parent::__construct($opline, $line);
    }
}
