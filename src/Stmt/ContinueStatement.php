<?php

declare(strict_types=1);

namespace Rephper\Stmt;

/**
 * A continue that targets a resolved loop.
 *
 * `level` is the instruction's op2 depth. `loopId` is the loop whose continue
 * block the CFG edge lands on.
 */
final class ContinueStatement extends Statement
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
