<?php

declare(strict_types=1);

namespace Rephper\Stmt;

/**
 * A break that leaves a switch, not a loop.
 *
 * `level` is the instruction's op2 depth. `switchId` is the switch whose
 * breaks[] row the depth selects. A break that leaves a loop stays a
 * BreakStatement instead.
 */
final class SwitchBreakStatement extends Statement
{
    public function __construct(
        int $opline,
        int $line,
        public readonly int $level,
        public readonly string $switchId,
    ) {
        parent::__construct($opline, $line);
    }
}
