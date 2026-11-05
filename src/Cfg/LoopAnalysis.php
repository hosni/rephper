<?php

declare(strict_types=1);

namespace Rephper\Cfg;

use Rephper\Ir\BreakContinue;

/**
 * Dominators, natural loops, and resolved break/continue edges for one op array.
 *
 * `breaks` is the op array's breaks[] table. Switch structuring uses it to
 * tell a switch break from a loop break. Loop classification does not.
 */
final class LoopAnalysis
{
    /**
     * @param list<NaturalLoop> $loops
     * @param list<LoopControl> $controls
     * @param list<BreakContinue>|null $breaks
     */
    public function __construct(
        public readonly Dominators $dominators,
        public readonly array $loops,
        public readonly array $controls,
        public readonly ?array $breaks = null,
    ) {
    }

    public function loop(string $id): ?NaturalLoop
    {
        foreach ($this->loops as $loop) {
            if ($loop->id === $id) {
                return $loop;
            }
        }

        return null;
    }
}
