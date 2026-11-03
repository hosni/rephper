<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Cfg;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephper\Cfg\Builder;
use Rephper\Cfg\CfgException;
use Rephper\Cfg\Graph;
use Rephper\Cfg\IncompleteMetadataException;
use Rephper\Cfg\UnsupportedSemanticsException;
use Rephper\Cli\GraphPrinter;
use Rephper\Ir\Document;
use Rephper\Ir\Reader;

final class BuilderTest extends TestCase
{
    private Reader $reader;

    protected function setUp(): void
    {
        $this->reader = new Reader();
    }

    #[DataProvider('edges')]
    public function testEdge(string $file, string $name, int $start, string $kind, int $target, mixed $case = null): void
    {
        $this->assertTrue($this->hasEdge($this->graph($file, $name), $start, $kind, $target, $case));
    }

    /**
     * @return list<array{string, string, int, string, int, mixed}>
     */
    public static function edges(): array
    {
        return [
            ['Common/Conditions.json', 'classify', 0, 'true', 3, null],
            ['Common/Conditions.json', 'classify', 0, 'false', 4, null],
            ['Common/ControlFlow.json', 'walk', 0, 'true', 5, null],
            ['Common/ControlFlow.json', 'walk', 0, 'false', 7, null],
            ['Common/ControlFlow.json', 'walk', 5, 'jump', 8, null],
            ['Common/ControlFlow.json', 'walk', 18, 'continue', 23, null],
            ['Common/ControlFlow.json', 'walk', 21, 'break', 26, null],
            ['Common/ControlFlow.json', 'walk', 24, 'true', 16, null],
            ['Common/ControlFlow.json', 'walk', 24, 'false', 26, null],
            ['Common/ControlFlow.json', 'walk', 29, 'true', 28, null],
            ['Common/ControlFlow.json', 'walk', 29, 'false', 31, null],
            ['Common/ControlFlow.json', 'walk', 31, 'foreach_exit', 35, null],
            ['Common/ControlFlow.json', 'walk', 32, 'fallthrough', 33, null],
            ['Common/ControlFlow.json', 'walk', 32, 'foreach_exit', 35, null],
            ['Common/ControlFlow.json', 'walk', 33, 'jump', 32, null],
            ['Common/Loops.json', 'calculateTotal', 0, 'fallthrough', 3, null],
            ['Common/Loops.json', 'calculateTotal', 0, 'foreach_exit', 6, null],
            ['Common/Loops.json', 'calculateTotal', 4, 'jump', 3, null],
            ['Common/StructuredFlow.json', 'nested', 0, 'true', 3, null],
            ['Common/StructuredFlow.json', 'nested', 0, 'false', 8, null],
            ['Common/StructuredFlow.json', 'nested', 3, 'true', 5, null],
            ['Common/StructuredFlow.json', 'nested', 3, 'false', 7, null],
            ['Common/StructuredFlow.json', 'dowhile', 2, 'true', 2, null],
            ['Common/StructuredFlow.json', 'dowhile', 2, 'false', 5, null],
            ['Common/StructuredFlow.json', 'nestedLoops', 8, 'break', 16, null],
            ['Common/StructuredFlow.json', 'nestedLoops', 11, 'true', 6, null],
            ['Common/StructuredFlow.json', 'nestedLoops', 14, 'true', 4, null],
            ['Common/StructuredFlow.json', 'choose', 0, 'switch', 9, 'a'],
            ['Common/StructuredFlow.json', 'choose', 0, 'switch', 10, 'b'],
            ['Common/StructuredFlow.json', 'choose', 0, 'switch', 10, 'c'],
            ['Common/StructuredFlow.json', 'choose', 0, 'default', 11, null],
            ['Common/StructuredFlow.json', 'choose', 0, 'fallthrough', 2, null],
            ['Common/StructuredFlow.json', 'jump', 3, 'goto', 5, null],
            ['Common/StructuredFlow.json', 'jump', 0, 'false', 4, null],
            ['Common/StructuredFlow.json', 'jump', 4, 'fallthrough', 5, null],
            ['Common/StructuredFlow.json', 'early', 0, 'true', 3, null],
            ['Common/StructuredFlow.json', 'early', 0, 'false', 4, null],
            ['Common/StructuredFlow.json', 'early', 4, 'true', 6, null],
            ['Common/StructuredFlow.json', 'early', 4, 'false', 7, null],
            ['Common/StructuredFlow.json', 'fallback', 0, 'not_null', 3, null],
            ['Common/StructuredFlow.json', 'fallback', 0, 'null', 2, null],
            ['Common/StructuredFlow.json', 'fallback', 3, 'true', 6, null],
            ['Common/StructuredFlow.json', 'fallback', 3, 'false', 5, null],
            ['Common/StructuredFlow.json', 'fallback', 6, 'assert_skip', 15, null],
            ['Common/StructuredFlow.json', 'fallback', 6, 'fallthrough', 10, null],
            ['PHP/8.0/MatchExpression.json', 'classify', 0, 'switch', 2, 0],
            ['PHP/8.0/MatchExpression.json', 'classify', 0, 'switch', 4, 1],
            ['PHP/8.0/MatchExpression.json', 'classify', 0, 'switch', 4, 2],
            ['PHP/8.0/MatchExpression.json', 'classify', 0, 'default', 6, null],
        ];
    }

    #[DataProvider('exceptionEdges')]
    public function testExceptionEdge(string $file, string $name, int $start, string $kind, int $target): void
    {
        $graph = $this->graph($file, $name);
        $block = $graph->blockStartingAt($start);
        $handler = $graph->blockStartingAt($target);
        $this->assertNotNull($block);
        $this->assertNotNull($handler);
        $matched = false;
        foreach ($block->exceptions as $edge) {
            if ($edge->kind === $kind && $edge->to === $handler->id) {
                $matched = true;
            }
        }
        $this->assertTrue($matched);
    }

    /**
     * @return list<array{string, string, int, string, int}>
     */
    public static function exceptionEdges(): array
    {
        return [
            ['Common/Exceptions.json', 'guard', 1, 'catch', 8],
            ['Common/Exceptions.json', 'guard', 3, 'catch', 8],
            ['Common/Exceptions.json', 'guard', 8, 'finally', 13],
            ['Common/TryForms.json', 'caught', 1, 'catch', 8],
            ['Common/TryForms.json', 'finalized', 1, 'finally', 13],
            ['Common/TryForms.json', 'wrapped', 1, 'catch', 8],
            ['Common/TryForms.json', 'wrapped', 8, 'catch', 14],
        ];
    }

    public function testStraightLineAdditionKeepsTheUnreachableEpilogue(): void
    {
        $graph = $this->graph('Common/Arithmetic.json', 'calculate');
        $this->assertInstructionCoverage($graph, 7);
        $this->assertCount(2, $graph->blocks);
        $this->assertSame(0, $graph->blocks[0]->start);
        $this->assertSame(5, $graph->blocks[0]->end);
        $this->assertSame([], $graph->blocks[0]->successors);
        $this->assertSame([], $graph->blocks[1]->predecessors);
    }

    public function testConditionalEpilogueHasNoPredecessor(): void
    {
        $graph = $this->graph('Common/Conditions.json', 'classify');
        $this->assertInstructionCoverage($graph, 7);
        $this->assertSame([], $graph->blockStartingAt(5)?->predecessors);
    }

    public function testWalkCoversEveryOpline(): void
    {
        $this->assertInstructionCoverage($this->graph('Common/ControlFlow.json', 'walk'), 40);
    }

    public function testSwitchKeepsOneTypeMismatchFallthrough(): void
    {
        $block = $this->graph('Common/StructuredFlow.json', 'choose')->blockStartingAt(0);
        $this->assertNotNull($block);
        $fallthrough = array_filter($block->successors, static fn ($edge): bool => $edge->kind === 'fallthrough');
        $this->assertCount(1, $fallthrough);
    }

    public function testMatchDoesNotFallThrough(): void
    {
        $block = $this->graph('PHP/8.0/MatchExpression.json', 'classify')->blockStartingAt(0);
        $this->assertNotNull($block);
        foreach ($block->successors as $edge) {
            $this->assertNotSame('fallthrough', $edge->kind);
        }
    }

    public function testThrowOutsideTryHasNoHandler(): void
    {
        $throw = $this->graph('Common/StructuredFlow.json', 'fail')->blockStartingAt(3);
        $this->assertNotNull($throw);
        $this->assertSame('THROW', $throw->instructions[array_key_last($throw->instructions)]->opcode);
        $this->assertSame([], $throw->successors);
        $this->assertSame([], $throw->exceptions);
    }

    public function testFinallyCallAndReturn(): void
    {
        $guard = $this->graph('Common/Exceptions.json', 'guard');
        $this->assertTrue($this->hasEdge($guard, 8, 'finally_call', 13));
        $this->assertTrue($this->hasEdge($guard, 11, 'finally_call', 13));
        $this->assertTrue($this->hasEdge($guard, 13, 'finally_return', 10));
        $this->assertTrue($this->hasEdge($guard, 13, 'finally_return', 12));
        $this->assertSame([], $guard->blockStartingAt(13)?->exceptions);
    }

    public function testMultiCatchFallsThroughAndJumpsToTheNextType(): void
    {
        $caught = $this->graph('Common/TryForms.json', 'caught');
        $this->assertTrue($this->hasEdge($caught, 8, 'fallthrough', 9));
        $this->assertTrue($this->hasEdge($caught, 8, 'catch_next', 11));
    }

    public function testTryFinallyDoesNotInventACatchEdge(): void
    {
        $finalized = $this->graph('Common/TryForms.json', 'finalized');
        $this->assertTrue($this->hasEdge($finalized, 7, 'finally_call', 13));
        $this->assertSame('finally', $finalized->blockStartingAt(1)?->exceptions[0]->kind);
    }

    public function testMinimalScriptBuildsAMainGraph(): void
    {
        $graphs = $this->graphs('Common/Minimal.json');
        $this->assertArrayHasKey('main', $graphs);
        $this->assertSame('main', $graphs['main']->kind);
        $this->assertNotSame([], $graphs['main']->blocks);
    }

    public function testClosuresAreSeparateGraphs(): void
    {
        $document = $this->document('Common/Closures.json');
        $sawClosure = false;
        $sawMain = false;
        foreach ((new Builder())->buildDocument($document) as $graph) {
            $this->assertInstructionCoverage($graph, $document->opArrays[$graph->opArrayId]->instructionCount());
            $sawClosure = $sawClosure || $graph->kind === 'closure';
            $sawMain = $sawMain || $graph->name === null;
        }
        $this->assertTrue($sawClosure);
        $this->assertTrue($sawMain);
    }

    public function testPrinterShowsConditionalBranches(): void
    {
        $printed = (new GraphPrinter())->print($this->graph('Common/Conditions.json', 'classify'));
        $again = (new GraphPrinter())->print($this->graph('Common/Conditions.json', 'classify'));

        $this->assertSame($printed, $again);
        $this->assertStringContainsString('Block B0 [0, 3)', $printed);
        $this->assertStringContainsString('B1 (true)', $printed);
        $this->assertStringContainsString('B2 (false)', $printed);
    }

    public function testJumpOutsideTheOpArrayIsInvalid(): void
    {
        $document = $this->mutated('Common/Conditions.json', function (array $document): array {
            $document['op_arrays'][0]['instructions'][2]['op2']['target'] = 99;

            return $document;
        });

        try {
            (new Builder())->buildDocument($document);
            $this->fail('Jump target 99 was accepted');
        } catch (UnsupportedSemanticsException | IncompleteMetadataException $exception) {
            $this->fail('Out-of-range jump was classified as ' . $exception::class);
        } catch (CfgException) {
            $this->assertTrue(true);
        }
    }

    public function testUnknownOpcodeIsUnsupported(): void
    {
        $document = $this->mutated('Common/Conditions.json', function (array $document): array {
            $document['op_arrays'][0]['instructions'][0]['opcode'] = 'NOT_A_REAL_OPCODE';
            $document['op_arrays'][0]['instructions'][0]['opcode_number'] = 250;

            return $document;
        });

        $this->expectException(UnsupportedSemanticsException::class);
        (new Builder())->buildDocument($document);
    }

    public function testUserOpcodeIsUnsupported(): void
    {
        $document = $this->mutated('Common/Conditions.json', function (array $document): array {
            $document['op_arrays'][0]['instructions'][0]['opcode'] = 'USER_OPCODE';
            $document['op_arrays'][0]['instructions'][0]['opcode_number'] = 150;

            return $document;
        });

        $this->expectException(UnsupportedSemanticsException::class);
        (new Builder())->buildDocument($document);
    }

    public function testBreakWithoutABreaksTableIsIncomplete(): void
    {
        $document = $this->mutated('Common/ControlFlow.json', function (array $document): array {
            $document['op_arrays'][0]['breaks'] = null;

            return $document;
        });

        $this->expectException(IncompleteMetadataException::class);
        (new Builder())->buildDocument($document);
    }

    public function testCatchOplineOutsideTheOpArrayIsInvalid(): void
    {
        $document = $this->mutated('Common/Exceptions.json', function (array $document): array {
            $document['op_arrays'][0]['try_catch'][0]['catch'] = 1000;

            return $document;
        });

        $this->expectException(CfgException::class);
        (new Builder())->buildDocument($document);
    }

    public function testEmptyInstructionListBuildsAnEmptyGraph(): void
    {
        $document = $this->mutated('Common/Minimal.json', function (array $document): array {
            foreach ($document['op_arrays'] as &$opArray) {
                $opArray['instructions'] = [];
            }

            return $document;
        });

        $graphs = (new Builder())->buildDocument($document);
        $this->assertNotSame([], $graphs);
        $this->assertSame([], $graphs[0]->blocks);
    }

    public function testInitializedStaticJumpsWhenAlreadyBound(): void
    {
        $document = $this->mutated('Common/StructuredFlow.json', function (array $document): array {
            $bind = &$document['op_arrays'][7]['instructions'][7];
            $bind['opcode'] = 'BIND_INIT_STATIC_OR_JMP';
            $bind['opcode_number'] = 203;
            $bind['op2'] = ['kind' => 'jump', 'target' => 18];

            return $document;
        });

        $graph = $this->namedGraph($document, 'fallback');
        $this->assertTrue($this->hasEdge($graph, 6, 'initialized', 18));
        $this->assertTrue($this->hasEdge($graph, 6, 'fallthrough', 8));
    }

    public function testFramelessCallMissFallsThrough(): void
    {
        $document = $this->mutated('Common/StructuredFlow.json', function (array $document): array {
            $call = &$document['op_arrays'][7]['instructions'][10];
            $call['opcode'] = 'JMP_FRAMELESS';
            $call['opcode_number'] = 208;
            $call['op2'] = ['kind' => 'jump', 'target' => 18];

            return $document;
        });

        $graph = $this->namedGraph($document, 'fallback');
        $this->assertTrue($this->hasEdge($graph, 10, 'frameless', 18));
        $this->assertTrue($this->hasEdge($graph, 10, 'fallthrough', 11));
    }

    private function graph(string $file, string $name): Graph
    {
        $found = $this->graphs($file);
        $this->assertArrayHasKey($name, $found);

        return $found[$name];
    }

    /**
     * @return array<string, Graph>
     */
    private function graphs(string $file): array
    {
        $byName = [];
        foreach ((new Builder())->buildDocument($this->document($file)) as $graph) {
            $byName[$graph->name ?? 'main'] = $graph;
        }

        return $byName;
    }

    private function document(string $file): Document
    {
        $json = (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/' . $file);
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);

        return $this->reader->readString($json);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    private function mutated(string $file, callable $change): Document
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/' . $file), true);
        $this->assertIsArray($decoded);
        $decoded['php']['version'] = '8.4.27';
        $decoded['php']['version_id'] = 80427;

        return $this->reader->readString((string) json_encode($change($decoded)));
    }

    private function namedGraph(Document $document, string $name): Graph
    {
        foreach ((new Builder())->buildDocument($document) as $graph) {
            if ($graph->name === $name) {
                return $graph;
            }
        }

        $this->fail('Missing op array ' . $name);
    }

    private function hasEdge(Graph $graph, int $start, string $kind, int $target, mixed $case = null): bool
    {
        $block = $graph->blockStartingAt($start);
        $destination = $graph->blockStartingAt($target);
        if ($block === null || $destination === null) {
            return false;
        }
        foreach ($block->successors as $edge) {
            if ($edge->kind === $kind && $edge->to === $destination->id && $edge->case === $case) {
                return true;
            }
        }

        return false;
    }

    private function assertInstructionCoverage(Graph $graph, int $count): void
    {
        $seen = [];
        foreach ($graph->blocks as $block) {
            $this->assertGreaterThan($block->start, $block->end);
            foreach ($block->instructions as $instruction) {
                $this->assertGreaterThanOrEqual($block->start, $instruction->index);
                $this->assertLessThan($block->end, $instruction->index);
                $seen[$instruction->index] = true;
            }
            foreach ($block->successors as $edge) {
                $target = null;
                foreach ($graph->blocks as $candidate) {
                    if ($candidate->id === $edge->to) {
                        $target = $candidate;
                    }
                }
                $this->assertNotNull($target);
                $this->assertContains($block->id, $target->predecessors);
            }
        }
        $this->assertCount($count, $seen);
    }
}
