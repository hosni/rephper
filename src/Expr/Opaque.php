<?php

declare(strict_types=1);

namespace Rephper\Expr;

/**
 * An instruction that is kept in order because its effect is not a reconstructed expression.
 */
final class Opaque extends Expression
{
    public function __construct(
        int $opline,
        int $line,
        public readonly string $opcode,
        public readonly string $reason,
    ) {
        parent::__construct($opline, $line);
    }
}
