<?php

declare(strict_types=1);

namespace Rephper\Cfg;

/**
 * A reachable BRK or CONT and the loop it resolves to.
 *
 * `level` is the instruction's op2 depth. `breaksIndex` is op1, the breaks[]
 * slot, which is not the depth. `targetId` is the block the CFG edge already
 * points at. The instruction is tied to the innermost loop that contains its
 * block or the in-loop block that branches to it. A break block often cannot
 * reach the header, so that block stays outside the natural-loop member set.
 * `loopId` is the loop a break leaves, or the loop whose continue block a
 * continue lands on. It is null when a break's target stays inside that
 * innermost loop, and when a continue lands inside a loop but not on that
 * loop's continue block. `outer` is true when `loopId` names a loop outside
 * the innermost related loop.
 */
final class LoopControl
{
    public function __construct(
        public readonly string $opcode,
        public readonly int $opline,
        public readonly int $line,
        public readonly string $blockId,
        public readonly int $level,
        public readonly int $breaksIndex,
        public readonly string $targetId,
        public readonly int $targetOpline,
        public readonly ?string $loopId,
        public readonly bool $outer,
    ) {
    }
}
