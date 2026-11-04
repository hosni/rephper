<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Cfg;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephper\Cfg\Block;
use Rephper\Cfg\Builder;
use Rephper\Cfg\CfgException;
use Rephper\Cfg\Edge;
use Rephper\Cfg\Graph;
use Rephper\Cfg\LoopAnalysis;
use Rephper\Cfg\LoopAnalyzer;
use Rephper\Cfg\LoopControl;
use Rephper\Cfg\LoopEdge;
use Rephper\Cfg\NaturalLoop;
use Rephper\Ir\Document;
use Rephper\Ir\OpArray;
use Rephper\Ir\Reader;

final class LoopAnalyzerTest extends TestCase
{
    #[DataProvider('classifications')]
    public function testClassification(string $name, array $kinds): void
    {
        [, $analysis] = $this->analyzed($name);
        $actual = array_map(static fn (NaturalLoop $loop): string => $loop->kind, $analysis->loops);

        $this->assertSame($kinds, $actual);
        foreach ($analysis->loops as $index => $loop) {
            $this->assertSame('L' . $index, $loop->id);
            if ($loop->kind === NaturalLoop::UNKNOWN) {
                $this->assertNotNull($loop->reason);
            } else {
                $this->assertNull($loop->reason);
            }
        }
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function classifications(): array
    {
        return [
            'simple while' => ['simpleWhile', [NaturalLoop::WHILE]],
            'simple do-while' => ['simpleDo', [NaturalLoop::DO_WHILE]],
            'simple for' => ['simpleFor', [NaturalLoop::FOR]],
            'empty for' => ['emptyFor', [NaturalLoop::FOR]],
            'foreach' => ['simpleForeach', [NaturalLoop::FOREACH]],
            'nested while' => ['nestedWhile', [NaturalLoop::WHILE, NaturalLoop::WHILE]],
            'for containing foreach' => ['nestedMixed', [NaturalLoop::FOREACH, NaturalLoop::FOR]],
            'while with two back edges' => ['multiBack', [NaturalLoop::WHILE]],
            'break' => ['broken', [NaturalLoop::WHILE]],
            'continue' => ['continued', [NaturalLoop::FOR]],
            'break 2' => ['breakTwo', [NaturalLoop::WHILE, NaturalLoop::WHILE]],
            'continue 2' => ['continueTwo', [NaturalLoop::WHILE, NaturalLoop::FOR]],
            'conditional body' => ['conditionalBody', [NaturalLoop::WHILE]],
            'sequential loops' => ['sequential', [NaturalLoop::WHILE, NaturalLoop::WHILE]],
            'backward goto' => ['backwardGoto', [NaturalLoop::UNKNOWN]],
            'goto that is not a back edge' => ['notALoop', [NaturalLoop::UNKNOWN]],
            'unreachable loop' => ['dead', []],
            'short-circuit condition' => ['andWhile', [NaturalLoop::UNKNOWN]],
            'while true' => ['whileTrue', [NaturalLoop::WHILE]],
            'do-while with continue' => ['doContinue', [NaturalLoop::DO_WHILE]],
            'do-while with return' => ['doReturn', [NaturalLoop::DO_WHILE]],
        ];
    }

    public function testEveryFixtureGraphIsLeftUnchangedAndObeysTheLoopRules(): void
    {
        foreach ($this->document()->opArrays as $opArray) {
            $graph = (new Builder())->build($opArray);
            $before = $this->snapshot($graph);
            $analysis = (new LoopAnalyzer())->analyze($graph, $opArray);
            $label = $opArray->name ?? 'main';

            $this->assertSame($before, $this->snapshot($graph), $label);
            $this->assertLoopRules($graph, $analysis, $label);
        }
    }

    public function testASimpleWhileIsTheConditionAndTheBodyThatFallsIntoIt(): void
    {
        [$graph, $analysis] = $this->analyzed('simpleWhile');
        $loop = $this->only($analysis);
        $header = $this->block($graph, $loop->headerId);
        $body = $this->block($graph, $loop->backEdges[0]->from);

        $this->assertSame('JMPNZ', $this->opcode($header));
        $this->assertSame('PRE_INC', $this->opcode($body));
        $this->assertLessThan($header->start, $body->start);
        $this->assertSame([$body->id, $header->id], $loop->blocks);
        $this->assertSame(['fallthrough'], $this->kinds($loop->backEdges));
        $this->assertSame(['jump'], $this->kinds($loop->entries));
        $this->assertSame(['false'], $this->kinds($loop->exits));
        $this->assertSame($header->id, $loop->backEdges[0]->to);
        $this->assertFalse($loop->contains($analysis->dominators->entryId ?? ''));
        $this->assertFalse($loop->contains($loop->exits[0]->to));
        $this->assertNull($loop->parentId);
        $this->assertSame([], $loop->childIds);
        $this->assertSame([], $analysis->controls);
    }

    public function testADoWhileBranchesToItsOwnHeader(): void
    {
        [$graph, $analysis] = $this->analyzed('simpleDo');
        $loop = $this->only($analysis);
        $header = $this->block($graph, $loop->headerId);

        $this->assertSame([$header->id], $loop->blocks);
        $this->assertSame('JMPNZ', $this->opcode($header));
        $this->assertSame($header->id, $loop->backEdges[0]->from);
        $this->assertSame($header->id, $loop->backEdges[0]->to);
        $this->assertSame('true', $loop->backEdges[0]->kind);
        $this->assertSame('fallthrough', $loop->entries[0]->kind);
        $this->assertSame('false', $loop->exits[0]->kind);
        $this->assertNotContains($this->edgeKey($loop->backEdges[0]), $this->exitKeys($loop));
    }

    public function testAnEmptyForIsNotClassifiedAsWhile(): void
    {
        [$graph, $while] = $this->analyzed('simpleWhile');
        [$emptyGraph, $empty] = $this->analyzed('emptyFor');
        $whileLoop = $this->only($while);
        $forLoop = $this->only($empty);

        $this->assertSame('PRE_INC', $this->opcode($this->block($graph, $whileLoop->backEdges[0]->from)));
        $this->assertSame('JMPNZ', $this->opcode($this->block($graph, $whileLoop->headerId)));
        $this->assertSame('PRE_INC', $this->opcode($this->block($emptyGraph, $forLoop->backEdges[0]->from)));
        $this->assertSame('JMPNZ', $this->opcode($this->block($emptyGraph, $forLoop->headerId)));
        $this->assertSame(NaturalLoop::WHILE, $whileLoop->kind);
        $this->assertSame(NaturalLoop::FOR, $forLoop->kind);
        $this->assertNotSame($forLoop->headerId, $forLoop->backEdges[0]->from);
    }

    public function testAForKeepsTheBodyAndIncrementAheadOfTheCondition(): void
    {
        [$graph, $analysis] = $this->analyzed('simpleFor');
        $loop = $this->only($analysis);
        $latch = $this->block($graph, $loop->backEdges[0]->from);
        $opcodes = array_map(static fn ($instruction): string => $instruction->opcode, $latch->instructions);

        $this->assertSame(['PRE_INC', 'PRE_INC'], $opcodes);
        $this->assertSame('fallthrough', $loop->backEdges[0]->kind);
        $this->assertSame('JMPNZ', $this->opcode($this->block($graph, $loop->headerId)));
        $this->assertSame([$latch->id, $loop->headerId], $loop->blocks);
    }

    public function testForeachIsTheFetchHeaderAndNotTheResetOrFreeBlock(): void
    {
        [$graph, $analysis] = $this->analyzed('simpleForeach');
        $loop = $this->only($analysis);
        $header = $this->block($graph, $loop->headerId);
        $reset = $this->blockEnding($graph, 'FE_RESET_R');
        $free = $this->blockWith($graph, 'FE_FREE');

        $this->assertSame('FE_FETCH_R', $this->opcode($header));
        $this->assertSame(['jump'], $this->kinds($loop->backEdges));
        $this->assertSame($header->id, $loop->backEdges[0]->to);
        $this->assertSame('fallthrough', $loop->entries[0]->kind);
        $this->assertSame($reset->id, $loop->entries[0]->from);
        $this->assertSame(['foreach_exit'], $this->kinds($loop->exits));
        $this->assertFalse($loop->contains($reset->id));
        $this->assertFalse($loop->contains($free->id));
        $this->assertSame($free->id, $loop->exits[0]->to);
        $this->assertSame([$header->id, $loop->backEdges[0]->from], $loop->blocks);
    }

    public function testNestedWhilesKeepTheInnerLoopInsideTheOuterOne(): void
    {
        [$graph, $analysis] = $this->analyzed('nestedWhile');
        $inner = $analysis->loops[0];
        $outer = $analysis->loops[1];

        $this->assertSame($outer->id, $inner->parentId);
        $this->assertSame([$inner->id], $outer->childIds);
        $this->assertNull($outer->parentId);
        foreach ($inner->blocks as $id) {
            $this->assertTrue($outer->contains($id));
        }
        $this->assertFalse($inner->contains($outer->headerId));
        $this->assertTrue($analysis->dominators->dominates($outer->headerId, $inner->headerId));
        $this->assertFalse($analysis->dominators->dominates($inner->headerId, $outer->headerId));
        $this->assertTrue($outer->contains($inner->exits[0]->to));
        $this->assertFalse($inner->contains($inner->exits[0]->to));
        $this->assertSame('JMPNZ', $this->opcode($this->block($graph, $inner->headerId)));
        $this->assertSame('JMPNZ', $this->opcode($this->block($graph, $outer->headerId)));
    }

    public function testAForeachNestedInAForDoesNotAbsorbTheResetOrTheIncrement(): void
    {
        [$graph, $analysis] = $this->analyzed('nestedMixed');
        $foreach = $analysis->loops[0];
        $for = $analysis->loops[1];
        $reset = $this->blockEnding($graph, 'FE_RESET_R');

        $this->assertSame($for->id, $foreach->parentId);
        $this->assertSame([$foreach->id], $for->childIds);
        $this->assertFalse($foreach->contains($reset->id));
        $this->assertTrue($for->contains($reset->id));
        $this->assertFalse($foreach->contains($for->headerId));
        $this->assertTrue($for->contains($foreach->headerId));
        $this->assertSame('FE_FETCH_R', $this->opcode($this->block($graph, $foreach->headerId)));
        $this->assertSame('foreach_exit', $foreach->exits[0]->kind);
        $this->assertTrue($for->contains($foreach->exits[0]->to));
        $this->assertSame('fallthrough', $for->backEdges[0]->kind);
        $this->assertNotSame($for->headerId, $for->backEdges[0]->from);
    }

    public function testContinueAndTheLatchAreTwoBackEdgesOfOneWhile(): void
    {
        [, $analysis] = $this->analyzed('multiBack');
        $loop = $this->only($analysis);
        $continue = $this->control($analysis, 'CONT', 1);

        $this->assertCount(2, $loop->backEdges);
        $this->assertSame(['continue', 'fallthrough'], $this->kinds($loop->backEdges));
        $this->assertSame($loop->headerId, $loop->backEdges[0]->to);
        $this->assertSame($loop->headerId, $loop->backEdges[1]->to);
        $this->assertSame($loop->id, $continue->loopId);
        $this->assertFalse($continue->outer);
        $this->assertSame(0, $continue->breaksIndex);
        $this->assertSame($loop->headerId, $continue->targetId);
        $this->assertSame($loop->headerOpline, $continue->targetOpline);
        $this->assertNotContains($continue->blockId . '>' . $loop->headerId . '>continue', $this->exitKeys($loop));
        $this->assertSame(['false'], $this->kinds($loop->exits));
    }

    public function testBreakLeavesTheWhileAndTheBreakBlockIsNotALoopMember(): void
    {
        [$graph, $analysis] = $this->analyzed('broken');
        $loop = $this->only($analysis);
        $break = $this->control($analysis, 'BRK', 1);
        $false = $this->edgeKind($loop->exits, 'false');

        $this->assertSame($loop->id, $break->loopId);
        $this->assertFalse($break->outer);
        $this->assertSame(1, $break->level);
        $this->assertSame(0, $break->breaksIndex);
        $this->assertSame($false->to, $break->targetId);
        $this->assertFalse($loop->contains($break->blockId));
        $this->assertFalse($loop->contains($break->targetId));
        $this->assertTrue($this->hasExit($loop, $break->blockId));
        $this->assertSame('BRK', $this->opcode($this->block($graph, $break->blockId)));
        $this->assertNotSame($break->blockId, $loop->backEdges[0]->from);
    }

    public function testContinueInAForTargetsTheIncrementRatherThanTheHeader(): void
    {
        [, $analysis] = $this->analyzed('continued');
        $loop = $this->only($analysis);
        $continue = $this->control($analysis, 'CONT', 1);

        $this->assertSame(NaturalLoop::FOR, $loop->kind);
        $this->assertSame($loop->id, $continue->loopId);
        $this->assertFalse($continue->outer);
        $this->assertSame(1, $continue->level);
        $this->assertNotSame($loop->headerId, $continue->targetId);
        $this->assertTrue($loop->contains($continue->targetId));
        $this->assertTrue($loop->contains($continue->blockId));
        $this->assertSame($loop->backEdges[0]->from, $continue->targetId);
        $this->assertNotContains('continue' . $continue->targetId, $this->backKeys($loop));
        $this->assertSame('fallthrough', $loop->backEdges[0]->kind);
    }

    public function testBreakTwoExitsTheOuterLoop(): void
    {
        [, $analysis] = $this->analyzed('breakTwo');
        $inner = $analysis->loops[0];
        $outer = $analysis->loops[1];
        $break = $this->control($analysis, 'BRK', 2);

        $this->assertSame(2, $break->level);
        $this->assertSame(1, $break->breaksIndex);
        $this->assertTrue($break->outer);
        $this->assertSame($outer->id, $break->loopId);
        $this->assertFalse($inner->contains($break->blockId));
        $this->assertFalse($outer->contains($break->blockId));
        $this->assertFalse($inner->contains($break->targetId));
        $this->assertFalse($outer->contains($break->targetId));
        $this->assertSame($this->edgeKind($outer->exits, 'false')->to, $break->targetId);
        $this->assertTrue($this->hasExit($inner, $break->blockId));
        $this->assertTrue($this->hasExit($outer, $break->blockId));
        $this->assertSame($outer->id, $inner->parentId);
    }

    public function testContinueTwoTargetsTheOuterIncrement(): void
    {
        [, $analysis] = $this->analyzed('continueTwo');
        $inner = $analysis->loops[0];
        $outer = $analysis->loops[1];
        $continue = $this->control($analysis, 'CONT', 2);

        $this->assertSame(NaturalLoop::FOR, $outer->kind);
        $this->assertSame(2, $continue->level);
        $this->assertSame(1, $continue->breaksIndex);
        $this->assertTrue($continue->outer);
        $this->assertSame($outer->id, $continue->loopId);
        $this->assertTrue($outer->contains($continue->targetId));
        $this->assertFalse($inner->contains($continue->targetId));
        $this->assertSame($outer->backEdges[0]->from, $continue->targetId);
        $this->assertNotSame($inner->headerId, $continue->targetId);
        $this->assertNotContains('continue' . $continue->targetId, $this->backKeys($inner));
        $this->assertTrue($this->hasExit($inner, $continue->blockId));
        $this->assertFalse($this->hasExit($outer, $continue->blockId));
    }

    public function testAConditionalInsideAWhileStaysOneWhile(): void
    {
        [$graph, $analysis] = $this->analyzed('conditionalBody');
        $loop = $this->only($analysis);
        $branch = $this->blockEnding($graph, 'JMPZ');
        $latch = $this->block($graph, $loop->backEdges[0]->from);

        $this->assertSame(NaturalLoop::WHILE, $loop->kind);
        $this->assertTrue($loop->contains($branch->id));
        $this->assertTrue($loop->contains($this->target($branch, 'true')));
        $this->assertTrue($loop->contains($this->target($branch, 'false')));
        $this->assertNotSame($loop->headerId, $latch->id);
        $this->assertSame('PRE_INC', $this->opcode($latch));
        $this->assertSame('fallthrough', $loop->backEdges[0]->kind);
        $this->assertCount(1, $loop->backEdges);
        $this->assertSame(['false'], $this->kinds($loop->exits));
        $this->assertCount(5, $loop->blocks);
    }

    public function testSequentialLoopsStayDisjointWhenTheFirstHeaderDominatesTheSecond(): void
    {
        [, $analysis] = $this->analyzed('sequential');
        $first = $analysis->loops[0];
        $second = $analysis->loops[1];

        $this->assertNull($first->parentId);
        $this->assertNull($second->parentId);
        $this->assertSame([], $first->childIds);
        $this->assertSame([], $second->childIds);
        $this->assertSame([], array_intersect($first->blocks, $second->blocks));
        $this->assertTrue($analysis->dominators->dominates($first->headerId, $second->headerId));
        $this->assertFalse($analysis->dominators->dominates($second->headerId, $first->headerId));
        $this->assertFalse($first->contains($second->headerId));
    }

    public function testABackwardGotoIsANaturalLoopAndStaysUnknown(): void
    {
        [, $analysis] = $this->analyzed('backwardGoto');
        $loop = $this->only($analysis);

        $this->assertSame(NaturalLoop::REASON_GOTO, $loop->reason);
        $this->assertSame(['goto'], $this->kinds($loop->backEdges));
        $this->assertNotSame(NaturalLoop::WHILE, $loop->kind);
        $this->assertNotSame(NaturalLoop::DO_WHILE, $loop->kind);
        $this->assertSame('false', $loop->exits[0]->kind);
        $this->assertCount(2, $loop->blocks);
    }

    public function testABackwardGotoWhoseTargetDoesNotDominateItIsNotABackEdge(): void
    {
        [$graph, $analysis] = $this->analyzed('notALoop');
        $loop = $this->only($analysis);
        $backward = false;

        $this->assertSame(NaturalLoop::REASON_GOTO, $loop->reason);
        $this->assertNotContains('goto', $this->kinds($loop->backEdges));
        $this->assertContains('fallthrough', $this->kinds($loop->backEdges));
        foreach ($graph->blocks as $block) {
            foreach ($block->successors as $edge) {
                if ($edge->kind !== 'goto') {
                    continue;
                }
                $target = $this->block($graph, $edge->to);
                if ($target->start >= $block->start) {
                    continue;
                }
                $backward = true;
                $this->assertFalse($analysis->dominators->dominates($target->id, $block->id));
                $this->assertNotSame($loop->headerId, $target->id);
            }
        }
        $this->assertTrue($backward);
        $this->assertTrue($loop->contains($loop->backEdges[0]->from));
        $this->assertSame($loop->headerId, $loop->backEdges[0]->to);
    }

    public function testAnUnreachableLoopAndItsBreakAreIgnored(): void
    {
        [$graph, $analysis] = $this->analyzed('dead');
        $condition = $this->blockEnding($graph, 'JMPNZ');
        $break = $this->blockEnding($graph, 'BRK');

        $this->assertSame([], $analysis->loops);
        $this->assertSame([], $analysis->controls);
        $this->assertContains($condition->id, $analysis->dominators->unreachable);
        $this->assertContains($break->id, $analysis->dominators->unreachable);
        $this->assertArrayNotHasKey($condition->id, $analysis->dominators->dominators);
        $this->assertFalse($analysis->dominators->dominates($analysis->dominators->entryId ?? '', $condition->id));
        $this->assertSame(['B0'], $analysis->dominators->reachable);
    }

    public function testAShortCircuitWhileStaysUnknown(): void
    {
        [$graph, $analysis] = $this->analyzed('andWhile');
        $loop = $this->only($analysis);

        $this->assertSame(
            'loop header ends with JMPZ_EX, which is not a recognized loop condition.',
            $loop->reason,
        );
        $this->assertSame('JMPZ_EX', $this->opcode($this->block($graph, $loop->headerId)));
        $this->assertGreaterThan(2, count($loop->blocks));
        $this->assertSame('fallthrough', $loop->backEdges[0]->kind);
        $this->assertSame('false', $loop->exits[0]->kind);
        $this->assertNotSame($loop->headerId, $loop->exits[0]->from);
    }

    public function testWhileTrueStillHasAConditionHeader(): void
    {
        [$graph, $analysis] = $this->analyzed('whileTrue');
        $loop = $this->only($analysis);
        $break = $this->control($analysis, 'BRK', 1);

        $this->assertSame('JMPNZ', $this->opcode($this->block($graph, $loop->headerId)));
        $this->assertSame($loop->id, $break->loopId);
        $this->assertFalse($break->outer);
        $this->assertSame(1, $break->level);
        $this->assertFalse($loop->contains($break->blockId));
        $this->assertSame($this->edgeKind($loop->exits, 'false')->to, $break->targetId);
    }

    public function testAMultiBlockDoWhileContinuesAtTheCondition(): void
    {
        [$graph, $analysis] = $this->analyzed('doContinue');
        $loop = $this->only($analysis);
        $continue = $this->control($analysis, 'CONT', 1);
        $header = $this->block($graph, $loop->headerId);
        $condition = $this->block($graph, $loop->backEdges[0]->from);

        $this->assertSame('JMPZ', $this->opcode($header));
        $this->assertSame('JMPNZ', $this->opcode($condition));
        $this->assertNotSame($header->id, $condition->id);
        $this->assertSame('true', $loop->backEdges[0]->kind);
        $this->assertSame($header->id, $loop->backEdges[0]->to);
        $this->assertSame($loop->id, $continue->loopId);
        $this->assertFalse($continue->outer);
        $this->assertSame(1, $continue->level);
        $this->assertSame($condition->id, $continue->targetId);
        $this->assertNotSame($header->id, $continue->targetId);
        $this->assertTrue($loop->contains($continue->blockId));
        $this->assertSame('false', $loop->exits[0]->kind);
        $this->assertSame($condition->id, $loop->exits[0]->from);
    }

    public function testADoWhileThatReturnsFromTheBodyKeepsTheConditionOnTheBackEdge(): void
    {
        [$graph, $analysis] = $this->analyzed('doReturn');
        $loop = $this->only($analysis);
        $header = $this->block($graph, $loop->headerId);
        $condition = $this->block($graph, $loop->backEdges[0]->from);
        $early = $this->block($graph, $this->target($header, 'true'));

        $this->assertSame('JMPZ', $this->opcode($header));
        $this->assertSame('JMPNZ', $this->opcode($condition));
        $this->assertSame('RETURN', $this->opcode($early));
        $this->assertFalse($loop->contains($early->id));
        $this->assertSame($early->id, $this->edgeKind($loop->exits, 'true')->to);
        $this->assertSame($header->id, $loop->backEdges[0]->to);
        $this->assertSame('false', $this->edgeKind($loop->exits, 'false')->kind);
        $this->assertNotSame(NaturalLoop::FOR, $loop->kind);
    }

    public function testAnUnreachableCycleReachedOnlyByAnExceptionEdgeIsNotALoop(): void
    {
        $opArray = $this->opArray('dead');
        $entry = new Block('B0', 0, 1, []);
        $cycle = new Block('B1', 4, 5, []);
        $entry->exceptions[] = new Edge('catch', 'B1');
        $cycle->successors[] = new Edge('jump', 'B1');
        $cycle->predecessors[] = 'B1';
        $cycle->exceptionPredecessors[] = 'B0';
        $graph = new Graph($opArray->id, $opArray->name, $opArray->kind, [$entry, $cycle]);
        $before = $this->snapshot($graph);
        $analysis = (new LoopAnalyzer())->analyze($graph, $opArray);

        $this->assertSame($before, $this->snapshot($graph));
        $this->assertSame([], $analysis->loops);
        $this->assertSame([], $analysis->controls);
        $this->assertSame(['B1'], $analysis->dominators->unreachable);
        $this->assertArrayNotHasKey('B1', $analysis->dominators->dominators);
    }

    public function testTheGraphMustBelongToTheOpArray(): void
    {
        [$graph] = $this->analyzed('simpleWhile');

        $this->expectException(CfgException::class);
        (new LoopAnalyzer())->analyze($graph, $this->opArray('simpleDo'));
    }

    private function assertLoopRules(Graph $graph, LoopAnalysis $analysis, string $label): void
    {
        $ids = array_map(static fn (Block $block): string => $block->id, $graph->blocks);
        $this->assertSame([], array_diff($analysis->dominators->reachable, $ids), $label);
        $this->assertSame([], array_diff($analysis->dominators->unreachable, $ids), $label);
        $this->assertSame(
            [],
            array_intersect($analysis->dominators->reachable, $analysis->dominators->unreachable),
            $label,
        );
        $entry = $analysis->dominators->entryId;
        foreach ($analysis->dominators->reachable as $id) {
            $this->assertTrue($analysis->dominators->dominates($id, $id), $label);
            if ($entry !== null) {
                $this->assertTrue($analysis->dominators->dominates($entry, $id), $label);
            }
        }
        foreach ($analysis->dominators->unreachable as $id) {
            $this->assertArrayNotHasKey($id, $analysis->dominators->dominators, $label);
            $this->assertArrayNotHasKey($id, $analysis->dominators->immediateDominators, $label);
        }

        foreach ($analysis->loops as $loop) {
            $this->assertContains($loop->kind, [
                NaturalLoop::WHILE,
                NaturalLoop::DO_WHILE,
                NaturalLoop::FOR,
                NaturalLoop::FOREACH,
                NaturalLoop::UNKNOWN,
            ], $label);
            $this->assertTrue($loop->contains($loop->headerId), $label);
            $header = $this->block($graph, $loop->headerId);
            $this->assertSame($header->start, $loop->headerOpline, $label);
            $this->assertContains($header->start, $loop->oplines, $label);
            foreach ($loop->blocks as $id) {
                $this->assertContains($id, $analysis->dominators->reachable, $label);
                $this->assertTrue($analysis->dominators->dominates($loop->headerId, $id), $label);
            }
            foreach ($loop->backEdges as $edge) {
                $this->assertSame($loop->headerId, $edge->to, $label);
                $this->assertTrue($loop->contains($edge->from), $label);
                $this->assertTrue($analysis->dominators->dominates($edge->to, $edge->from), $label);
                $this->assertNotContains($edge->from . '>' . $edge->to . '>' . $edge->kind, $this->exitKeys($loop), $label);
                $this->assertContains($edge->opline, $loop->oplines, $label);
                $this->assertEdgeMatchesTerminator($graph, $edge);
            }
            foreach ($loop->entries as $edge) {
                $this->assertFalse($loop->contains($edge->from), $label);
                $this->assertTrue($loop->contains($edge->to), $label);
                $this->assertEdgeMatchesTerminator($graph, $edge);
            }
            foreach ($loop->exits as $edge) {
                $this->assertTrue($loop->contains($edge->from), $label);
                $this->assertFalse($loop->contains($edge->to), $label);
                $this->assertEdgeMatchesTerminator($graph, $edge);
            }
            if ($loop->parentId !== null) {
                $parent = $analysis->loop($loop->parentId);
                $this->assertInstanceOf(NaturalLoop::class, $parent, $label);
                $this->assertContains($loop->id, $parent->childIds, $label);
                foreach ($loop->blocks as $id) {
                    $this->assertTrue($parent->contains($id), $label);
                }
            }
            foreach ($analysis->loops as $other) {
                if ($other->id === $loop->id || array_intersect($loop->blocks, $other->blocks) === []) {
                    continue;
                }
                $difference = array_diff($loop->blocks, $other->blocks);
                $reverse = array_diff($other->blocks, $loop->blocks);
                $this->assertTrue($difference === [] || $reverse === [], $label . ' ' . $loop->id);
            }
        }

        $reachable = array_fill_keys($analysis->dominators->reachable, true);
        foreach ($analysis->controls as $control) {
            $this->assertContains($control->opcode, ['BRK', 'CONT'], $label);
            $this->assertArrayHasKey($control->blockId, $reachable, $label);
            $this->assertGreaterThanOrEqual(1, $control->level, $label);
            $this->assertGreaterThanOrEqual(0, $control->breaksIndex, $label);
            $block = $this->block($graph, $control->blockId);
            $this->assertSame($control->opcode, $this->opcode($block), $label);
            $this->assertSame($control->opline, $block->instructions[array_key_last($block->instructions)]->index, $label);
            $target = $this->block($graph, $control->targetId);
            $this->assertSame($target->start, $control->targetOpline, $label);
            if ($control->loopId !== null) {
                $this->assertInstanceOf(NaturalLoop::class, $analysis->loop($control->loopId), $label);
            }
        }
    }

    private function assertEdgeMatchesTerminator(Graph $graph, LoopEdge $edge): void
    {
        $source = $this->block($graph, $edge->from);
        $terminator = $source->instructions[array_key_last($source->instructions)];
        $this->assertSame($terminator->index, $edge->opline);
        $this->assertSame($terminator->line, $edge->line);
    }

    /**
     * @return array{Graph, LoopAnalysis}
     */
    private function analyzed(string $name): array
    {
        $opArray = $this->opArray($name);
        $graph = (new Builder())->build($opArray);

        return [$graph, (new LoopAnalyzer())->analyze($graph, $opArray)];
    }

    private function only(LoopAnalysis $analysis): NaturalLoop
    {
        $this->assertCount(1, $analysis->loops);

        return $analysis->loops[0];
    }

    private function control(LoopAnalysis $analysis, string $opcode, int $level): LoopControl
    {
        $found = [];
        foreach ($analysis->controls as $control) {
            if ($control->opcode === $opcode && $control->level === $level) {
                $found[] = $control;
            }
        }
        $this->assertCount(1, $found, $opcode . ' ' . $level);

        return $found[0];
    }

    private function opArray(string $name): OpArray
    {
        $opArray = $this->document()->opArrayNamed($name);
        $this->assertInstanceOf(OpArray::class, $opArray);

        return $opArray;
    }

    private function document(): Document
    {
        static $document = null;
        if ($document instanceof Document) {
            return $document;
        }
        $json = (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/LoopAnalysis.json');
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);
        $document = (new Reader())->readString($json);

        return $document;
    }

    private function block(Graph $graph, string $id): Block
    {
        foreach ($graph->blocks as $block) {
            if ($block->id === $id) {
                return $block;
            }
        }
        $this->fail('Missing block ' . $id);
    }

    private function blockWith(Graph $graph, string $opcode): Block
    {
        $found = [];
        foreach ($graph->blocks as $block) {
            foreach ($block->instructions as $instruction) {
                if ($instruction->opcode === $opcode) {
                    $found[] = $block;
                    break;
                }
            }
        }
        $this->assertCount(1, $found, $opcode);

        return $found[0];
    }

    private function blockEnding(Graph $graph, string $opcode): Block
    {
        $found = [];
        foreach ($graph->blocks as $block) {
            if ($block->instructions !== [] && $this->opcode($block) === $opcode) {
                $found[] = $block;
            }
        }
        $this->assertCount(1, $found, $opcode);

        return $found[0];
    }

    private function opcode(Block $block): string
    {
        return $block->instructions[array_key_last($block->instructions)]->opcode;
    }

    private function target(Block $block, string $kind): string
    {
        foreach ($block->successors as $edge) {
            if ($edge->kind === $kind) {
                return $edge->to;
            }
        }
        $this->fail($block->id . ' has no ' . $kind . ' edge');
    }

    /**
     * @param list<LoopEdge> $edges
     * @return list<string>
     */
    private function kinds(array $edges): array
    {
        return array_map(static fn (LoopEdge $edge): string => $edge->kind, $edges);
    }

    /**
     * @param list<LoopEdge> $edges
     */
    private function edgeKind(array $edges, string $kind): LoopEdge
    {
        $found = [];
        foreach ($edges as $edge) {
            if ($edge->kind === $kind) {
                $found[] = $edge;
            }
        }
        $this->assertCount(1, $found, $kind);

        return $found[0];
    }

    private function hasExit(NaturalLoop $loop, string $target): bool
    {
        foreach ($loop->exits as $edge) {
            if ($edge->to === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function exitKeys(NaturalLoop $loop): array
    {
        return array_map($this->edgeKey(...), $loop->exits);
    }

    private function edgeKey(LoopEdge $edge): string
    {
        return $edge->from . '>' . $edge->to . '>' . $edge->kind;
    }

    /**
     * @return list<string>
     */
    private function backKeys(NaturalLoop $loop): array
    {
        return array_map(
            static fn (LoopEdge $edge): string => $edge->kind . $edge->to,
            $loop->backEdges,
        );
    }

    /**
     * @return list<array{string, int, int, list<array{string, string, mixed}>, list<string>, list<array{string, string, mixed}>, list<string>}>
     */
    private function snapshot(Graph $graph): array
    {
        $rows = [];
        foreach ($graph->blocks as $block) {
            $rows[] = [
                $block->id,
                $block->start,
                $block->end,
                $this->edgeRows($block->successors),
                $block->predecessors,
                $this->edgeRows($block->exceptions),
                $block->exceptionPredecessors,
            ];
        }

        return $rows;
    }

    /**
     * @param list<Edge> $edges
     * @return list<array{string, string, mixed}>
     */
    private function edgeRows(array $edges): array
    {
        $rows = [];
        foreach ($edges as $edge) {
            $rows[] = [$edge->kind, $edge->to, $edge->case];
        }

        return $rows;
    }
}
