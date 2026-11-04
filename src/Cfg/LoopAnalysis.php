<?php

declare(strict_types=1);

namespace Rephper\Cfg;

/**
 * Dominators, natural loops, and resolved break/continue edges for one op array.
 */
final class LoopAnalysis
{
    /**
     * @param list<NaturalLoop> $loops
     * @param list<LoopControl> $controls
     */
    public function __construct(
        public readonly Dominators $dominators,
        public readonly array $loops,
        public readonly array $controls,
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
