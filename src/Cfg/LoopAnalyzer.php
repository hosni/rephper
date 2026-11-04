<?php

declare(strict_types=1);

namespace Rephper\Cfg;

use Rephper\Ir\BreakContinue;
use Rephper\Ir\Instruction;
use Rephper\Ir\OpArray;

/**
 * Finds natural loops in an existing control-flow graph.
 *
 * A back edge runs from N to H when H dominates N. The edge may point at a
 * block that is earlier or later in the opline layout. PHP lays a while body
 * before its condition, so the body's fallthrough into the condition is the
 * back edge and the condition's branch into the body is not.
 *
 * The loop of every back edge into one header is that header plus every block
 * that can reach a back-edge source without passing through the header.
 * Several back edges into the same header are one loop. The graph is not
 * modified. Exception edges are ignored. An unreachable cycle is not a loop.
 *
 * Classification uses the header opcode, the back-edge sources, and the
 * non-switch breaks[] row whose continue target lies in the loop and whose
 * break target lies outside it. while and for share a shape. The continue
 * opline tells them apart: the header means while, another block in the loop
 * means for. A goto stays unknown.
 *
 * A reachable BRK or CONT is resolved from the loop that contains its block
 * or the predecessor that branches to it. The CFG edge is the target. The
 * instruction's op2 depth is kept as the level and is not recomputed from
 * block order. A break's loop is the outermost related loop that does not
 * contain the target. A continue's loop is the one whose continue block is
 * that target. A switch break, or a switch continue that does not land on a
 * loop's continue block, has no loop id. An edge out of an unreachable block
 * is not a loop entry.
 */
final class LoopAnalyzer
{
    /** @var array<string, Block> */
    private array $byId = [];

    private Graph $graph;

    private OpArray $opArray;

    private Dominators $dominators;

    public function analyze(Graph $graph, OpArray $opArray): LoopAnalysis
    {
        if ($graph->opArrayId !== $opArray->id) {
            throw new CfgException(
                'Control-flow graph ' . $graph->opArrayId . ' does not match op array ' . $opArray->id
            );
        }

        $this->graph = $graph;
        $this->opArray = $opArray;
        $this->byId = [];
        foreach ($graph->blocks as $block) {
            $this->byId[$block->id] = $block;
        }
        $this->dominators = (new DominatorAnalysis())->analyze($graph);

        $drafts = $this->drafts();
        $this->nest($drafts);
        $loops = $this->finish($drafts);

        return new LoopAnalysis($this->dominators, $loops, $this->controls($loops));
    }

    /**
     * @return list<array{
     *     header: Block,
     *     backEdges: list<LoopEdge>,
     *     members: array<string, true>,
     *     entries: list<LoopEdge>,
     *     exits: list<LoopEdge>,
     *     kind: string,
     *     reason: ?string,
     *     oplines: list<int>,
     *     parent: ?int,
     *     children: list<int>
     * }>
     */
    private function drafts(): array
    {
        $grouped = [];
        $reachable = array_fill_keys($this->dominators->reachable, true);
        foreach ($this->graph->blocks as $block) {
            if (!isset($reachable[$block->id])) {
                continue;
            }
            foreach ($block->successors as $edge) {
                if (!isset($reachable[$edge->to])) {
                    continue;
                }
                if (!$this->dominators->dominates($edge->to, $block->id)) {
                    continue;
                }
                $grouped[$edge->to][] = $this->loopEdge($block, $edge);
            }
        }

        $drafts = [];
        foreach ($grouped as $headerId => $backEdges) {
            $header = $this->byId[$headerId];
            $members = $this->members($headerId, $backEdges, $reachable);
            $entries = $this->entries($members);
            $exits = $this->exits($members);
            [$kind, $reason] = $this->classify($header, $backEdges, $entries, $members);
            $drafts[] = [
                'header' => $header,
                'backEdges' => $this->sortEdges($backEdges),
                'members' => $members,
                'entries' => $this->sortEdges($entries),
                'exits' => $this->sortEdges($exits),
                'kind' => $kind,
                'reason' => $reason,
                'oplines' => $this->oplines($header, $backEdges),
                'parent' => null,
                'children' => [],
            ];
        }

        usort($drafts, function (array $left, array $right): int {
            return $left['header']->start <=> $right['header']->start
                ?: $left['header']->id <=> $right['header']->id;
        });

        return $drafts;
    }

    /**
     * @param list<LoopEdge> $backEdges
     * @param array<string, true> $reachable
     * @return array<string, true>
     */
    private function members(string $headerId, array $backEdges, array $reachable): array
    {
        $members = [$headerId => true];
        $stack = [];
        foreach ($backEdges as $edge) {
            $stack[] = $edge->from;
        }
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($members[$id]) || !isset($reachable[$id])) {
                continue;
            }
            $members[$id] = true;
            foreach ($this->byId[$id]->predecessors as $predecessor) {
                $stack[] = $predecessor;
            }
        }

        return $members;
    }

    /**
     * @param array<string, true> $members
     * @return list<LoopEdge>
     */
    private function entries(array $members): array
    {
        $reachable = array_fill_keys($this->dominators->reachable, true);
        $edges = [];
        foreach ($members as $id => $_) {
            foreach ($this->byId[$id]->predecessors as $predecessor) {
                if (isset($members[$predecessor]) || !isset($reachable[$predecessor])) {
                    continue;
                }
                foreach ($this->byId[$predecessor]->successors as $edge) {
                    if ($edge->to === $id) {
                        $edges[] = $this->loopEdge($this->byId[$predecessor], $edge);
                    }
                }
            }
        }

        return $edges;
    }

    /**
     * @param array<string, true> $members
     * @return list<LoopEdge>
     */
    private function exits(array $members): array
    {
        $edges = [];
        foreach ($members as $id => $_) {
            $block = $this->byId[$id];
            foreach ($block->successors as $edge) {
                if (!isset($members[$edge->to])) {
                    $edges[] = $this->loopEdge($block, $edge);
                }
            }
        }

        return $edges;
    }

    /**
     * @param list<LoopEdge> $backEdges
     * @param list<LoopEdge> $entries
     * @param array<string, true> $members
     * @return array{string, ?string}
     */
    private function classify(Block $header, array $backEdges, array $entries, array $members): array
    {
        if ($this->containsGoto($members)) {
            return [NaturalLoop::UNKNOWN, NaturalLoop::REASON_GOTO];
        }
        foreach ($entries as $edge) {
            if ($edge->to !== $header->id) {
                return [NaturalLoop::UNKNOWN, NaturalLoop::REASON_ENTRY];
            }
        }

        $foreach = $this->classifyForeach($header, $backEdges, $entries);
        if ($foreach !== null) {
            return $foreach;
        }
        $self = $this->selfLoop($header, $entries, $members);
        if ($self !== null) {
            return $self;
        }
        if ($this->isDoWhile($header, $backEdges, $members)) {
            return [NaturalLoop::DO_WHILE, null];
        }
        if ($this->isWhileHeader($header, $members)) {
            return $this->whileOrFor($header, $members);
        }

        $terminator = $this->terminator($header);
        if ($terminator === null || !$this->isLoopCondition($header)) {
            $opcode = $terminator === null ? 'an empty block' : $terminator->opcode;

            return [
                NaturalLoop::UNKNOWN,
                'loop header ends with ' . $opcode . ', which is not a recognized loop condition.',
            ];
        }

        return [NaturalLoop::UNKNOWN, NaturalLoop::REASON_SHAPE];
    }

    /**
     * @param array<string, true> $members
     */
    private function containsGoto(array $members): bool
    {
        foreach ($members as $id => $_) {
            $block = $this->byId[$id];
            foreach ($block->instructions as $instruction) {
                if ($instruction->opcode === 'GOTO') {
                    return true;
                }
            }
            foreach ($block->successors as $edge) {
                if ($edge->kind === 'goto') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<LoopEdge> $backEdges
     * @param list<LoopEdge> $entries
     * @return array{string, ?string}|null
     */
    private function classifyForeach(Block $header, array $backEdges, array $entries): ?array
    {
        $terminator = $this->terminator($header);
        if ($terminator === null || !in_array($terminator->opcode, ['FE_FETCH_R', 'FE_FETCH_RW'], true)) {
            return null;
        }

        $reset = false;
        foreach ($entries as $edge) {
            $opcode = $this->terminator($this->byId[$edge->from])?->opcode ?? '';
            if ($opcode === 'FE_RESET_R' || $opcode === 'FE_RESET_RW') {
                $reset = true;
                break;
            }
        }
        if (!$reset) {
            return [NaturalLoop::UNKNOWN, NaturalLoop::REASON_FOREACH_RESET];
        }

        foreach ($backEdges as $edge) {
            if ($edge->kind === 'jump') {
                return [NaturalLoop::FOREACH, null];
            }
        }

        return [NaturalLoop::UNKNOWN, NaturalLoop::REASON_FOREACH_JUMP];
    }

    /**
     * A condition that branches to itself is an empty while when every entry
     * is the compiler's prologue jump, and a do-while when control falls in
     * or arrives from a conditional edge. Mixed entry kinds stay unknown.
     *
     * @param list<LoopEdge> $entries
     * @param array<string, true> $members
     * @return array{string, ?string}|null
     */
    private function selfLoop(Block $header, array $entries, array $members): ?array
    {
        if (!$this->isLoopCondition($header) || !$this->branchesToSelf($header)) {
            return null;
        }
        if ($entries === []) {
            return [NaturalLoop::UNKNOWN, NaturalLoop::REASON_SHAPE];
        }

        $jump = false;
        $other = false;
        foreach ($entries as $edge) {
            if ($edge->kind === 'jump') {
                $jump = true;
            } else {
                $other = true;
            }
        }
        if ($jump && !$other) {
            return $this->whileOrFor($header, $members);
        }
        if ($other && !$jump) {
            return [NaturalLoop::DO_WHILE, null];
        }

        return [NaturalLoop::UNKNOWN, NaturalLoop::REASON_SHAPE];
    }

    /**
     * @param list<LoopEdge> $backEdges
     * @param array<string, true> $members
     */
    private function isDoWhile(Block $header, array $backEdges, array $members): bool
    {
        foreach ($backEdges as $edge) {
            if ($edge->from === $header->id) {
                continue;
            }
            $source = $this->byId[$edge->from];
            if (!$this->isLoopCondition($source) || !$this->leaves($source, $members)) {
                continue;
            }
            if (!$this->isWhileHeader($header, $members)) {
                return true;
            }
            $continueAt = $this->continueBlock($members);
            if ($continueAt !== null && $continueAt->id === $source->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, true> $members
     * @return array{string, ?string}
     */
    private function whileOrFor(Block $header, array $members): array
    {
        $rows = $this->matchingRows($members);
        if (count($rows) !== 1) {
            return [
                NaturalLoop::UNKNOWN,
                count($rows) === 0 ? NaturalLoop::REASON_NO_RECORD : NaturalLoop::REASON_MANY_RECORDS,
            ];
        }

        $continueAt = $this->blockOf($rows[0]->continueTarget);
        if ($continueAt === null || !isset($members[$continueAt->id])) {
            return [NaturalLoop::UNKNOWN, NaturalLoop::REASON_NO_RECORD];
        }
        if ($continueAt->id === $header->id) {
            return [NaturalLoop::WHILE, null];
        }

        return [NaturalLoop::FOR, null];
    }

    /**
     * @param array<string, true> $members
     */
    private function isWhileHeader(Block $header, array $members): bool
    {
        return $this->isLoopCondition($header)
            && !$this->branchesToSelf($header)
            && $this->leaves($header, $members);
    }

    private function isLoopCondition(Block $block): bool
    {
        $terminator = $this->terminator($block);

        return $terminator !== null && ($terminator->opcode === 'JMPZ' || $terminator->opcode === 'JMPNZ');
    }

    private function branchesToSelf(Block $block): bool
    {
        foreach ($block->successors as $edge) {
            if ($edge->to === $block->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, true> $members
     */
    private function leaves(Block $block, array $members): bool
    {
        foreach ($block->successors as $edge) {
            if (!isset($members[$edge->to])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, true> $members
     * @return list<BreakContinue>
     */
    private function matchingRows(array $members): array
    {
        if ($this->opArray->breaks === null) {
            return [];
        }

        $rows = [];
        foreach ($this->opArray->breaks as $row) {
            if ($row->switch) {
                continue;
            }
            $continueAt = $this->blockOf($row->continueTarget);
            if ($continueAt === null || !isset($members[$continueAt->id])) {
                continue;
            }
            $breakAt = $this->blockOf($row->breakTarget);
            if ($breakAt !== null && isset($members[$breakAt->id])) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, true> $members
     */
    private function continueBlock(array $members): ?Block
    {
        $rows = $this->matchingRows($members);
        if (count($rows) !== 1) {
            return null;
        }

        return $this->blockOf($rows[0]->continueTarget);
    }

    /**
     * @param array<string, true> $members
     */
    private function continueOpline(array $members): ?int
    {
        $rows = $this->matchingRows($members);
        if (count($rows) !== 1) {
            return null;
        }

        return $rows[0]->continueTarget;
    }

    /**
     * @param list<array{
     *     header: Block,
     *     backEdges: list<LoopEdge>,
     *     members: array<string, true>,
     *     entries: list<LoopEdge>,
     *     exits: list<LoopEdge>,
     *     kind: string,
     *     reason: ?string,
     *     oplines: list<int>,
     *     parent: ?int,
     *     children: list<int>
     * }> $drafts
     */
    private function nest(array &$drafts): void
    {
        $count = count($drafts);
        $overlap = array_fill(0, $count, false);
        for ($left = 0; $left < $count; $left++) {
            for ($right = $left + 1; $right < $count; $right++) {
                if (array_intersect_key($drafts[$left]['members'], $drafts[$right]['members']) === []) {
                    continue;
                }
                $leftContains = $this->containsAll($drafts[$left]['members'], $drafts[$right]['members']);
                $rightContains = $this->containsAll($drafts[$right]['members'], $drafts[$left]['members']);
                if (!$leftContains && !$rightContains) {
                    $overlap[$left] = true;
                    $overlap[$right] = true;
                }
            }
        }

        for ($index = 0; $index < $count; $index++) {
            $parent = null;
            $parentSize = PHP_INT_MAX;
            $tied = false;
            foreach ($drafts as $other => $candidate) {
                if ($other === $index) {
                    continue;
                }
                if (!$this->containsAll($candidate['members'], $drafts[$index]['members'])) {
                    continue;
                }
                if (count($candidate['members']) === count($drafts[$index]['members'])) {
                    continue;
                }
                $size = count($candidate['members']);
                if ($size < $parentSize) {
                    $parent = $other;
                    $parentSize = $size;
                    $tied = false;
                } elseif ($size === $parentSize) {
                    $tied = true;
                }
            }
            if ($tied) {
                $overlap[$index] = true;
                $parent = null;
            }
            $drafts[$index]['parent'] = $parent;
        }

        $children = array_fill(0, $count, []);
        foreach ($drafts as $index => $draft) {
            if ($draft['parent'] !== null) {
                $children[$draft['parent']][] = $index;
            }
        }
        foreach ($children as $index => $kids) {
            usort($kids, function (int $left, int $right) use ($drafts): int {
                return $drafts[$left]['header']->start <=> $drafts[$right]['header']->start
                    ?: $drafts[$left]['header']->id <=> $drafts[$right]['header']->id;
            });
            $drafts[$index]['children'] = $kids;
        }

        foreach ($overlap as $index => $overlaps) {
            if ($overlaps) {
                $drafts[$index]['kind'] = NaturalLoop::UNKNOWN;
                $drafts[$index]['reason'] = NaturalLoop::REASON_OVERLAP;
            }
        }
    }

    /**
     * @param array<string, true> $outer
     * @param array<string, true> $inner
     */
    private function containsAll(array $outer, array $inner): bool
    {
        foreach ($inner as $id => $_) {
            if (!isset($outer[$id])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{
     *     header: Block,
     *     backEdges: list<LoopEdge>,
     *     members: array<string, true>,
     *     entries: list<LoopEdge>,
     *     exits: list<LoopEdge>,
     *     kind: string,
     *     reason: ?string,
     *     oplines: list<int>,
     *     parent: ?int,
     *     children: list<int>
     * }> $drafts
     * @return list<NaturalLoop>
     */
    private function finish(array $drafts): array
    {
        $loops = [];
        foreach ($drafts as $index => $draft) {
            $terminator = $this->terminator($draft['header']);
            $loops[] = new NaturalLoop(
                'L' . $index,
                $draft['header']->id,
                $draft['header']->start,
                $terminator->line ?? 0,
                $draft['backEdges'],
                $this->sortIds(array_keys($draft['members'])),
                $draft['entries'],
                $draft['exits'],
                $draft['parent'] === null ? null : 'L' . $draft['parent'],
                array_map(static fn (int $child): string => 'L' . $child, $draft['children']),
                $draft['kind'],
                $draft['reason'],
                $draft['oplines'],
                $this->continueOpline($draft['members']),
            );
        }

        return $loops;
    }

    /**
     * @param list<NaturalLoop> $loops
     * @return list<LoopControl>
     */
    private function controls(array $loops): array
    {
        $byLoop = [];
        foreach ($loops as $loop) {
            $byLoop[$loop->id] = $loop;
        }
        $reachable = array_fill_keys($this->dominators->reachable, true);
        $controls = [];
        foreach ($this->graph->blocks as $block) {
            if (!isset($reachable[$block->id])) {
                continue;
            }
            $instruction = $this->terminator($block);
            if ($instruction === null || ($instruction->opcode !== 'BRK' && $instruction->opcode !== 'CONT')) {
                continue;
            }
            $controls[] = $this->control($block, $instruction, $loops, $byLoop);
        }

        usort($controls, static function (LoopControl $left, LoopControl $right): int {
            return $left->opline <=> $right->opline ?: $left->opcode <=> $right->opcode;
        });

        return $controls;
    }

    /**
     * @param list<NaturalLoop> $loops
     * @param array<string, NaturalLoop> $byLoop
     */
    private function control(Block $block, Instruction $instruction, array $loops, array $byLoop): LoopControl
    {
        if ($instruction->op1->kind !== 'level' || $instruction->op1->value === null) {
            throw new CfgException($instruction->opcode . ' at ' . $instruction->index . ' has no breaks index');
        }
        if ($instruction->op2->value === null || $instruction->op2->value < 1) {
            throw new CfgException($instruction->opcode . ' at ' . $instruction->index . ' has no positive depth');
        }

        $kind = $instruction->opcode === 'BRK' ? 'break' : 'continue';
        $edge = null;
        foreach ($block->successors as $candidate) {
            if ($candidate->kind === $kind) {
                $edge = $candidate;
                break;
            }
        }
        if ($edge === null) {
            throw new CfgException($instruction->opcode . ' at ' . $instruction->index . ' has no ' . $kind . ' edge');
        }

        $target = $this->byId[$edge->to];
        $innermost = $this->innermost($this->related($block, $loops));
        $resolved = $instruction->opcode === 'BRK'
            ? $this->breakLoop($target->id, $innermost, $byLoop)
            : $this->continueLoop($target->id, $innermost, $byLoop);

        return new LoopControl(
            $instruction->opcode,
            $instruction->index,
            $instruction->line,
            $block->id,
            $instruction->op2->value,
            $instruction->op1->value,
            $target->id,
            $target->start,
            $resolved?->id,
            $resolved !== null && $innermost !== null && $resolved->id !== $innermost->id,
        );
    }

    /**
     * Loops that contain the control block, or the block that branches to it.
     * A break block has no path back to the header, so it is absent from the
     * member set; its predecessor is the block inside the loop.
     *
     * @param list<NaturalLoop> $loops
     * @return array<string, NaturalLoop>
     */
    private function related(Block $block, array $loops): array
    {
        $anchors = [$block->id => true];
        $reachable = array_fill_keys($this->dominators->reachable, true);
        foreach ($block->predecessors as $predecessor) {
            if (isset($reachable[$predecessor])) {
                $anchors[$predecessor] = true;
            }
        }

        $related = [];
        foreach ($loops as $loop) {
            foreach ($anchors as $anchor => $_) {
                if ($loop->contains($anchor)) {
                    $related[$loop->id] = $loop;
                    break;
                }
            }
        }

        return $related;
    }

    /**
     * @param array<string, NaturalLoop> $related
     */
    private function innermost(array $related): ?NaturalLoop
    {
        $best = null;
        foreach ($related as $loop) {
            $parentOfAnother = false;
            foreach ($related as $other) {
                if ($other->parentId === $loop->id) {
                    $parentOfAnother = true;
                    break;
                }
            }
            if ($parentOfAnother) {
                continue;
            }
            if ($best === null || count($loop->blocks) < count($best->blocks)) {
                $best = $loop;
            }
        }

        return $best;
    }

    /**
     * The target continues a loop only when it is that loop's continue block.
     * A continue that lands inside the loop on some other block, such as the
     * end of a switch, does not continue the loop.
     *
     * @param array<string, NaturalLoop> $byLoop
     */
    private function continueLoop(string $targetId, ?NaturalLoop $innermost, array $byLoop): ?NaturalLoop
    {
        $loop = $innermost;
        while ($loop !== null) {
            if ($loop->contains($targetId)) {
                $continueAt = $this->continueBlock(array_fill_keys($loop->blocks, true));
                if ($continueAt !== null && $continueAt->id === $targetId) {
                    return $loop;
                }

                return null;
            }
            $loop = $loop->parentId === null ? null : $byLoop[$loop->parentId];
        }

        return null;
    }

    /**
     * @param array<string, NaturalLoop> $byLoop
     */
    private function breakLoop(string $targetId, ?NaturalLoop $innermost, array $byLoop): ?NaturalLoop
    {
        $exited = null;
        $loop = $innermost;
        while ($loop !== null) {
            if ($loop->contains($targetId)) {
                break;
            }
            $exited = $loop;
            $loop = $loop->parentId === null ? null : $byLoop[$loop->parentId];
        }

        return $exited;
    }

    private function loopEdge(Block $block, Edge $edge): LoopEdge
    {
        $terminator = $this->terminator($block);

        return new LoopEdge(
            $block->id,
            $edge->to,
            $edge->kind,
            $terminator->index ?? $block->start,
            $terminator->line ?? 0,
        );
    }

    /**
     * @param list<LoopEdge> $backEdges
     * @return list<int>
     */
    private function oplines(Block $header, array $backEdges): array
    {
        $oplines = [$header->start];
        $terminator = $this->terminator($header);
        if ($terminator !== null) {
            $oplines[] = $terminator->index;
        }
        foreach ($backEdges as $edge) {
            $oplines[] = $edge->opline;
        }
        $oplines = array_values(array_unique($oplines, SORT_REGULAR));
        sort($oplines);

        return $oplines;
    }

    private function terminator(Block $block): ?Instruction
    {
        if ($block->instructions === []) {
            return null;
        }

        return $block->instructions[array_key_last($block->instructions)];
    }

    private function blockOf(int $opline): ?Block
    {
        return $this->graph->blockContaining($opline);
    }

    /**
     * @param list<LoopEdge> $edges
     * @return list<LoopEdge>
     */
    private function sortEdges(array $edges): array
    {
        usort($edges, static function (LoopEdge $left, LoopEdge $right): int {
            return $left->opline <=> $right->opline
                ?: $left->from <=> $right->from
                ?: $left->to <=> $right->to
                ?: $left->kind <=> $right->kind;
        });

        return $edges;
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
