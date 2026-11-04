<?php

declare(strict_types=1);

namespace Rephper\Cfg;

/**
 * Dominators of the ordinary control-flow graph.
 *
 * A block D dominates N when every path from the entry to N goes through D.
 * A reachable block dominates itself. The entry's immediate dominator is null.
 * Unreachable blocks are listed and have no dominator set. Lists are sorted by
 * block start opline, then block id. That order is not dominance order and not
 * execution order. Block ids are not execution order.
 */
final class Dominators
{
    /**
     * @param list<string> $reachable
     * @param list<string> $unreachable
     * @param array<string, list<string>> $dominators
     * @param array<string, string|null> $immediateDominators
     */
    public function __construct(
        public readonly ?string $entryId,
        public readonly array $reachable,
        public readonly array $unreachable,
        public readonly array $dominators,
        public readonly array $immediateDominators,
    ) {
    }

    public function dominates(string $dominator, string $block): bool
    {
        return in_array($dominator, $this->dominators[$block] ?? [], true);
    }

    public function immediateDominator(string $block): ?string
    {
        return $this->immediateDominators[$block] ?? null;
    }
}
