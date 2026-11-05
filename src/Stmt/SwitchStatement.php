<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Expr\Expression;

/**
 * A jump-table switch.
 *
 * `subject` is the expression already stored on the SWITCH_LONG or
 * SWITCH_STRING operand. `cases` are in source order and include the default
 * arm when the IR has a default body. `typeFallback` records the comparisons
 * on the type-mismatch path. Those comparisons do not own the case bodies.
 * `switchId` is assigned from the switch opcode's position in the op array.
 */
final class SwitchStatement extends Statement
{
    /**
     * @param list<SwitchCase> $cases
     * @param list<SwitchFallbackArm> $typeFallback
     */
    public function __construct(
        int $opline,
        int $line,
        public readonly Expression $subject,
        public readonly array $cases,
        public readonly array $typeFallback,
        public readonly string $switchId,
    ) {
        parent::__construct($opline, $line);
    }
}
