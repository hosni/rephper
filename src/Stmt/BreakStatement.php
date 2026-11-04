<?php

declare(strict_types=1);

namespace Rephper\Stmt;

/**
 * A break that leaves a resolved loop.
 *
 * `level` is the instruction's op2 depth, including a break that counts an
 * enclosing switch. `loopId` is the loop from loop analysis, not a guess from
 * block order.
 */
final class BreakStatement extends Statement
{
    public function __construct(
        int $opline,
        int $line,
        public readonly int $level,
        public readonly string $loopId,
    ) {
        parent::__construct($opline, $line);
    }
}
