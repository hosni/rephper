<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Expr\Literal;

/**
 * One comparison on the type-mismatch path after SWITCH_LONG or SWITCH_STRING.
 *
 * The jump table handles the matching type. This path is the compiler's loose
 * comparison for every other type. `value` is that comparison's literal, the
 * same object as the case label. It is not a new label. `caseIndex` selects
 * the case body the comparison jumps to. A default arm is not represented here.
 */
final class SwitchFallbackArm
{
    public function __construct(
        public readonly int $opline,
        public readonly int $line,
        public readonly Literal $value,
        public readonly int $caseIndex,
    ) {
    }
}
