<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Cfg;

use PHPUnit\Framework\TestCase;
use Rephper\Cfg\Block;
use Rephper\Cfg\Builder;
use Rephper\Cfg\DominatorAnalysis;
use Rephper\Cfg\Dominators;
use Rephper\Cfg\Edge;
use Rephper\Cfg\Graph;
use Rephper\Ir\Document;
use Rephper\Ir\OpArray;
use Rephper\Ir\Reader;

final class DominatorAnalysisTest extends TestCase
{
    public function testTheEntryDominatesReachableBlocksAndUnreachableBlocksHaveNone(): void
    {
        [, $dominators] = $this->analyzed('Conditionals.json', 'ifElse');

        $this->assertSame('B0', $dominators->entryId);
        $this->assertNull($dominators->immediateDominator('B0'));
        $this->assertContains('B4', $dominators->unreachable);
        $this->assertArrayNotHasKey('B4', $dominators->dominators);
        $this->assertArrayNotHasKey('B4', $dominators->immediateDominators);
        $this->assertFalse($dominators->dominates('B0', 'B4'));

        foreach ($dominators->reachable as $id) {
            $this->assertTrue($dominators->dominates('B0', $id));
            $this->assertTrue($dominators->dominates($id, $id));
            $this->assertArrayHasKey($id, $dominators->immediateDominators);
        }
        $this->assertSame(['B0', 'B1', 'B2', 'B3'], $dominators->reachable);
    }

    public function testNeitherArmOfAConditionalDominatesTheOtherOrTheMerge(): void
    {
        [$graph, $dominators] = $this->analyzed('Conditionals.json', 'ifElse');
        $branch = $graph->blockStartingAt(0);
        $this->assertNotNull($branch);
        $then = $this->target($branch, 'true');
        $else = $this->target($branch, 'false');
        $merge = $this->target($this->block($graph, $else), 'fallthrough');

        $this->assertTrue($dominators->dominates($branch->id, $then));
        $this->assertTrue($dominators->dominates($branch->id, $else));
        $this->assertFalse($dominators->dominates($then, $else));
        $this->assertFalse($dominators->dominates($else, $then));
        $this->assertFalse($dominators->dominates($then, $merge));
        $this->assertFalse($dominators->dominates($else, $merge));
        $this->assertTrue($dominators->dominates($branch->id, $merge));
        $this->assertSame($branch->id, $dominators->immediateDominator($then));
        $this->assertSame($branch->id, $dominators->immediateDominator($else));
        $this->assertSame($branch->id, $dominators->immediateDominator($merge));
    }

    public function testAnInnerConditionalIsDominatedByTheOuterBranchOnly(): void
    {
        [$graph, $dominators] = $this->analyzed('Conditionals.json', 'nestedIf');
        $outer = $graph->blockStartingAt(0);
        $this->assertNotNull($outer);
        $inner = $this->block($graph, $this->target($outer, 'true'));
        $then = $this->target($inner, 'true');
        $else = $this->target($inner, 'false');
        $outerElse = $this->target($outer, 'false');

        $this->assertSame('JMPZ', $this->opcode($inner));
        $this->assertTrue($dominators->dominates($outer->id, $inner->id));
        $this->assertTrue($dominators->dominates($inner->id, $then));
        $this->assertTrue($dominators->dominates($inner->id, $else));
        $this->assertFalse($dominators->dominates($then, $else));
        $this->assertFalse($dominators->dominates($else, $then));
        $this->assertFalse($dominators->dominates($inner->id, $outerElse));
        $this->assertSame($inner->id, $dominators->immediateDominator($then));
        $this->assertSame($outer->id, $dominators->immediateDominator($outerElse));
    }

    public function testAWhileHeaderDominatesTheBodyLaidOutBeforeIt(): void
    {
        [$graph, $dominators] = $this->analyzed('LoopAnalysis.json', 'simpleWhile');
        $header = $this->blockEnding($graph, 'JMPNZ');
        $body = $this->block($graph, $this->target($header, 'true'));
        $exit = $this->block($graph, $this->target($header, 'false'));

        $this->assertLessThan($header->start, $body->start);
        $this->assertTrue($dominators->dominates($header->id, $body->id));
        $this->assertFalse($dominators->dominates($body->id, $header->id));
        $this->assertSame($header->id, $dominators->immediateDominator($body->id));
        $this->assertSame($dominators->entryId, $dominators->immediateDominator($header->id));
        $this->assertTrue($dominators->dominates($header->id, $exit->id));
        $this->assertFalse($dominators->dominates($body->id, $exit->id));
        $this->assertSame(
            [$dominators->entryId, $body->id, $header->id],
            $dominators->dominators[$body->id],
        );
        $this->assertNotContains($body->id, $dominators->dominators[$header->id]);
    }

    public function testAnOuterLoopHeaderDominatesTheInnerLoopAndNotTheOtherWay(): void
    {
        [$graph, $dominators] = $this->analyzed('LoopAnalysis.json', 'nestedWhile');
        $headers = $this->blocksEnding($graph, 'JMPNZ');
        $this->assertCount(2, $headers);
        $inner = $headers[0];
        $outer = $headers[1];
        $return = $this->block($graph, $this->target($outer, 'false'));

        $this->assertLessThan($outer->start, $inner->start);
        $this->assertTrue($dominators->dominates($outer->id, $inner->id));
        $this->assertFalse($dominators->dominates($inner->id, $outer->id));
        $this->assertNotSame($outer->id, $dominators->immediateDominator($inner->id));
        $this->assertTrue($dominators->dominates($outer->id, $dominators->immediateDominator($inner->id) ?? ''));
        $body = $this->block($graph, $this->target($inner, 'true'));
        $this->assertSame($inner->id, $dominators->immediateDominator($body->id));
        $this->assertTrue($dominators->dominates($outer->id, $return->id));
        $this->assertFalse($dominators->dominates($inner->id, $return->id));
        $this->assertContains('B7', $dominators->unreachable);
        $this->assertArrayNotHasKey('B7', $dominators->dominators);
    }

    public function testTheStartLabelOfABackwardGotoDoesNotDominateTheGoto(): void
    {
        [$graph, $dominators] = $this->analyzed('LoopAnalysis.json', 'notALoop');
        $backward = false;
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
                $this->assertFalse($dominators->dominates($target->id, $block->id));
            }
        }
        $this->assertTrue($backward);
    }

    public function testBlockIdsAreNotTreatedAsExecutionOrder(): void
    {
        $entry = new Block('B2', 0, 1, []);
        $later = new Block('B0', 1, 2, []);
        $entry->successors[] = new Edge('fallthrough', 'B0');
        $later->predecessors[] = 'B2';
        $dominators = (new DominatorAnalysis())->analyze(new Graph(1, 'synthetic', 'function', [$entry, $later]));

        $this->assertSame('B2', $dominators->entryId);
        $this->assertSame(['B2', 'B0'], $dominators->reachable);
        $this->assertTrue($dominators->dominates('B2', 'B0'));
        $this->assertFalse($dominators->dominates('B0', 'B2'));
        $this->assertSame('B2', $dominators->immediateDominator('B0'));
        $this->assertNull($dominators->immediateDominator('B2'));
    }

    public function testAnExceptionEdgeDoesNotReachABlockOrItsCycle(): void
    {
        $entry = new Block('B0', 0, 1, []);
        $cycle = new Block('B1', 4, 5, []);
        $entry->exceptions[] = new Edge('catch', 'B1');
        $cycle->successors[] = new Edge('jump', 'B1');
        $cycle->predecessors[] = 'B1';
        $cycle->exceptionPredecessors[] = 'B0';
        $dominators = (new DominatorAnalysis())->analyze(new Graph(1, 'synthetic', 'function', [$entry, $cycle]));

        $this->assertSame(['B0'], $dominators->reachable);
        $this->assertSame(['B1'], $dominators->unreachable);
        $this->assertArrayNotHasKey('B1', $dominators->dominators);
        $this->assertFalse($dominators->dominates('B0', 'B1'));
        $this->assertSame(['B0'], $dominators->dominators['B0']);
    }

    public function testAnEmptyGraphHasNoEntry(): void
    {
        $dominators = (new DominatorAnalysis())->analyze(new Graph(1, null, 'function', []));

        $this->assertNull($dominators->entryId);
        $this->assertSame([], $dominators->reachable);
        $this->assertSame([], $dominators->unreachable);
        $this->assertSame([], $dominators->dominators);
    }

    /**
     * @return array{Graph, Dominators}
     */
    private function analyzed(string $file, string $name): array
    {
        $opArray = $this->opArray($file, $name);
        $graph = (new Builder())->build($opArray);

        return [$graph, (new DominatorAnalysis())->analyze($graph)];
    }

    private function opArray(string $file, string $name): OpArray
    {
        $opArray = $this->document($file)->opArrayNamed($name);
        $this->assertInstanceOf(OpArray::class, $opArray);

        return $opArray;
    }

    private function document(string $file): Document
    {
        static $documents = [];
        if (isset($documents[$file])) {
            return $documents[$file];
        }
        $json = (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/' . $file);
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);
        $documents[$file] = (new Reader())->readString($json);

        return $documents[$file];
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

    private function target(Block $block, string $kind): string
    {
        $found = [];
        foreach ($block->successors as $edge) {
            if ($edge->kind === $kind) {
                $found[] = $edge->to;
            }
        }
        $this->assertCount(1, $found, $block->id . ' ' . $kind);

        return $found[0];
    }

    private function blockEnding(Graph $graph, string $opcode): Block
    {
        $found = $this->blocksEnding($graph, $opcode);
        $this->assertCount(1, $found, $opcode);

        return $found[0];
    }

    /**
     * @return list<Block>
     */
    private function blocksEnding(Graph $graph, string $opcode): array
    {
        $found = [];
        foreach ($graph->blocks as $block) {
            if ($block->instructions !== [] && $this->opcode($block) === $opcode) {
                $found[] = $block;
            }
        }

        return $found;
    }

    private function opcode(Block $block): string
    {
        return $block->instructions[array_key_last($block->instructions)]->opcode;
    }
}
