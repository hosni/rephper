<?php

declare(strict_types=1);

namespace Rephper\Stmt;

/**
 * A continue whose breaks[] row is a switch.
 *
 * PHP gives a switch the same continue target and break target, so this
 * leaves the switch. It is not rewritten as a break, and it is not a loop
 * continue even when that target is also a loop header. `level` is op2.
 */
final class SwitchContinueStatement extends Statement
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
