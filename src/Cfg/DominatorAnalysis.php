<?php

declare(strict_types=1);

namespace Rephper\Cfg;

/**
 * Calculates dominators for one control-flow graph.
 *
 * Only ordinary successors are followed. Exception edges stay out of the
 * reachable set and out of the predecessor relation used here. The graph is
 * not modified. Iteration continues until the sets stop changing, so the
 * result does not depend on block ids increasing in execution order.
 */
final class DominatorAnalysis
{
    /** @var array<string, Block> */
    private array $byId = [];

    public function analyze(Graph $graph): Dominators
    {
        $this->byId = [];
        foreach ($graph->blocks as $block) {
            $this->byId[$block->id] = $block;
        }

        $entry = $graph->blockStartingAt(0);
        if ($entry === null) {
            return new Dominators(
                null,
                [],
                $this->sortIds(array_keys($this->byId)),
                [],
                [],
            );
        }

        $reachable = $this->reachableFrom($entry->id);
        $sets = $this->dominatorSets($entry->id, $reachable);
        $immediate = [];
        foreach (array_keys($reachable) as $id) {
            $immediate[$id] = $this->immediate($id, $entry->id, $sets);
        }

        $listed = [];
        foreach ($sets as $id => $set) {
            $listed[$id] = $this->sortIds(array_keys($set));
        }

        $unreachable = [];
        foreach ($this->byId as $id => $_) {
            if (!isset($reachable[$id])) {
                $unreachable[] = $id;
            }
        }

        return new Dominators(
            $entry->id,
            $this->sortIds(array_keys($reachable)),
            $this->sortIds($unreachable),
            $listed,
            $immediate,
        );
    }

    /**
     * @return array<string, true>
     */
    private function reachableFrom(string $entry): array
    {
        $seen = [];
        $stack = [$entry];
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($this->byId[$id]->successors as $edge) {
                $stack[] = $edge->to;
            }
        }

        return $seen;
    }

    /**
     * @param array<string, true> $reachable
     * @return array<string, array<string, true>>
     */
    private function dominatorSets(string $entry, array $reachable): array
    {
        $sets = [$entry => [$entry => true]];
        foreach ($reachable as $id => $_) {
            if ($id !== $entry) {
                $sets[$id] = $reachable;
            }
        }

        $limit = count($reachable) * count($reachable) + 1;
        for ($round = 0; $round < $limit; $round++) {
            $changed = false;
            foreach ($reachable as $id => $_) {
                if ($id === $entry) {
                    continue;
                }
                $next = $this->intersectPredecessors($id, $reachable, $sets);
                $next[$id] = true;
                if (!$this->sameSet($next, $sets[$id])) {
                    $sets[$id] = $next;
                    $changed = true;
                }
            }
            if (!$changed) {
                return $sets;
            }
        }

        throw new CfgException('Dominator analysis did not converge');
    }

    /**
     * @param array<string, true> $reachable
     * @param array<string, array<string, true>> $sets
     * @return array<string, true>
     */
    private function intersectPredecessors(string $id, array $reachable, array $sets): array
    {
        $intersection = null;
        foreach ($this->byId[$id]->predecessors as $predecessor) {
            if (!isset($reachable[$predecessor])) {
                continue;
            }
            $intersection = $intersection === null
                ? $sets[$predecessor]
                : array_intersect_key($intersection, $sets[$predecessor]);
        }

        return $intersection ?? [];
    }

    /**
     * @param array<string, true> $left
     * @param array<string, true> $right
     */
    private function sameSet(array $left, array $right): bool
    {
        return count($left) === count($right) && array_diff_key($left, $right) === [];
    }

    /**
     * The immediate dominator is the strict dominator that every other strict
     * dominator dominates. A tie yields null.
     *
     * @param array<string, array<string, true>> $sets
     */
    private function immediate(string $id, string $entry, array $sets): ?string
    {
        if ($id === $entry) {
            return null;
        }

        $strict = $sets[$id];
        unset($strict[$id]);
        $best = null;
        foreach ($strict as $candidate => $_) {
            $closest = true;
            foreach ($strict as $other => $_) {
                if ($other !== $candidate && !isset($sets[$candidate][$other])) {
                    $closest = false;
                    break;
                }
            }
            if (!$closest) {
                continue;
            }
            if ($best !== null) {
                return null;
            }
            $best = $candidate;
        }

        return $best;
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function sortIds(array $ids): array
    {
        usort($ids, function (string $left, string $right): int {
            return $this->byId[$left]->start <=> $this->byId[$right]->start
                ?: $left <=> $right;
        });

        return $ids;
    }
}
