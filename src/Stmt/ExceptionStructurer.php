<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Cfg\Block;
use Rephper\Cfg\CatchDispatch;
use Rephper\Cfg\ExceptionRegion;
use Rephper\Cfg\ExceptionRegions;
use Rephper\Cfg\Graph;
use Rephper\Cfg\LoopAnalysis;
use Rephper\Cfg\LoopAnalyzer;
use Rephper\Expr\Diagnostic;
use Rephper\Ir\Instruction;
use Rephper\Ir\OpArray;

/**
 * Rebuilds try/catch statements over bounded regions.
 *
 * The walk is the existing loop and conditional structuring. A block that
 * starts a try region is handed here instead of being read as ordinary
 * flow. Try and catch bodies are structured by that same walk, stopped at
 * the region's boundaries. Finally regions stay in their original statements.
 * The control-flow graph is not modified, and exception edges are not turned
 * into successors.
 */
final class ExceptionStructurer
{
    private LoopStructurer $loops;

    public function structure(OpArray $opArray, Graph $graph, LoopAnalysis $analysis, Reconstruction $statements): StructuredStatements
    {
        if ($graph->opArrayId !== $opArray->id) {
            throw new StatementException(
                'Control-flow graph ' . $graph->opArrayId . ' does not match op array ' . $opArray->id
            );
        }
        $regions = ExceptionRegions::analyze($opArray, $graph);
        if ($regions->regions === []) {
            return (new LoopStructurer())->structure($graph, $analysis, $statements);
        }

        $analysis = (new LoopAnalyzer())->includeHandlers(
            $graph,
            $opArray,
            $analysis,
            $this->handlers($graph, $regions),
        );
        $this->loops = new LoopStructurer();

        return $this->loops->structure($graph, $analysis, $statements, $regions, $this);
    }

    /**
     * @param list<string> $stops
     * @return list<Statement>
     */
    public function emit(ExceptionRegion $region, array $stops): array
    {
        if (!$region->isStructured() || $region->clauses === null || $region->catch === null) {
            return $this->leaveUnstructured(
                $region,
                $stops,
                $region->reason ?? 'The exception region is not structured.',
            );
        }

        $saved = $this->loops->capture();
        $this->loops->open($region->index);
        try {
            return $this->assemble($region, $stops, $region->clauses, $region->catch);
        } catch (UnstructuredFlow $failure) {
            $this->loops->recall($saved);

            return $this->leaveUnstructured($region, $stops, $failure->getMessage());
        } finally {
            $this->loops->close($region->index);
        }
    }

    /**
     * @param list<CatchDispatch> $clauses
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function assemble(ExceptionRegion $region, array $stops, array $clauses, int $catch): array
    {
        $entry = $this->block($region->try);
        $continuation = $this->block($region->spanEnd);
        $catchBlock = $this->block($catch);
        $this->markDispatch($region, $clauses);
        $body = $this->loops->emitGuarded($entry, [$continuation->id, $catchBlock->id]);
        $this->cover($region->try, $catch);

        $catches = [];
        foreach ($clauses as $clause) {
            $catches[] = $this->clause($clause, $continuation->id);
        }
        $this->cover($catch, $region->spanEnd);

        $statement = new TryStatement(
            $region->try,
            $this->line($entry, $region->try),
            $body,
            $catches,
            $catch,
            $region->spanEnd,
        );

        return $this->loops->appendFrom([$statement], $continuation, $stops);
    }

    /**
     * @param list<CatchDispatch> $clauses
     */
    private function markDispatch(ExceptionRegion $region, array $clauses): void
    {
        $catch = $region->catch;
        if ($catch === null) {
            return;
        }
        foreach ($this->loops->blocks() as $block) {
            if ($block->start < $catch || $block->start >= $region->spanEnd) {
                continue;
            }
            foreach ($block->instructions as $instruction) {
                if ($instruction->opcode === 'CATCH') {
                    $this->loops->consume($instruction->index);
                }
            }
            if ($this->overlapsBody($block, $clauses)) {
                continue;
            }
            $this->loops->visit($block->id);
        }
    }

    private function clause(CatchDispatch $clause, string $continuationId): CatchClause
    {
        $stops = [$continuationId];
        $end = $this->loops->blockByStart($clause->bodyEnd);
        if ($end instanceof Block && $end->id !== $continuationId) {
            $stops[] = $end->id;
        }
        $body = [];
        if ($clause->bodyStart < $clause->bodyEnd) {
            $entry = $this->loops->blockByStart($clause->bodyStart);
            $from = 0;
            if (!$entry instanceof Block) {
                $entry = $this->loops->blockCovering($clause->bodyStart);
                $from = $clause->bodyStart;
            }
            if (!$entry instanceof Block) {
                throw new UnstructuredFlow('', $clause->opline, $clause->line, 'CATCH', 'Catch body at opline ' . $clause->bodyStart . ' is not in a block.');
            }
            $body = $this->loops->emitGuarded($entry, $stops, $from);
        }

        return new CatchClause(
            $clause->opline,
            $clause->line,
            $clause->types,
            $clause->catchOplines,
            $clause->variable,
            $body,
        );
    }

    /**
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function leaveUnstructured(ExceptionRegion $region, array $stops, string $reason): array
    {
        $statements = [];
        $reported = false;
        foreach ($this->loops->blocks() as $block) {
            if ($block->start < $region->try || $block->start >= $region->spanEnd) {
                continue;
            }
            $this->loops->visit($block->id);
            if (!$reported) {
                $instruction = $this->instruction($block, $region->try);
                $this->loops->diagnose(new Diagnostic(
                    'unstructured',
                    $block->id,
                    $instruction->index ?? $region->try,
                    $instruction->line ?? 0,
                    $instruction->opcode ?? 'TRY',
                    $reason,
                ));
                $reported = true;
            }
            foreach ($this->loops->statementsOf($block) as $statement) {
                $statements[] = $statement;
            }
        }
        if (!$reported) {
            $this->loops->diagnose(new Diagnostic(
                'unstructured',
                '',
                $region->try,
                0,
                'TRY',
                $reason,
            ));
        }
        $next = $this->loops->blockByStart($region->spanEnd);
        if (!$next instanceof Block) {
            return $statements;
        }

        return $this->loops->appendFrom($statements, $next, $stops);
    }

    private function cover(int $start, int $end): void
    {
        foreach ($this->loops->blocks() as $block) {
            if ($block->start < $start || $block->start >= $end || $this->loops->visited($block->id)) {
                continue;
            }
            $statements = $this->loops->statementsOf($block);
            if ($statements !== []) {
                $first = $statements[0];
                throw new UnstructuredFlow(
                    $block->id,
                    $first->opline,
                    $first->line,
                    'STMT',
                    'A statement in the exception region was not structured.',
                );
            }
            $this->loops->visit($block->id);
        }
    }

    /**
     * @param list<CatchDispatch> $clauses
     */
    /**
     * @param list<CatchDispatch> $clauses
     */
    private function overlapsBody(Block $block, array $clauses): bool
    {
        foreach ($clauses as $clause) {
            if ($block->start < $clause->bodyEnd && $block->end > $clause->bodyStart) {
                return true;
            }
        }

        return false;
    }

    private function block(int $opline): Block
    {
        $block = $this->loops->blockByStart($opline);
        if (!$block instanceof Block) {
            throw new UnstructuredFlow('', $opline, 0, 'TRY', 'Opline ' . $opline . ' is not a block boundary.');
        }

        return $block;
    }

    private function line(Block $block, int $opline): int
    {
        return $this->instruction($block, $opline)->line ?? 0;
    }

    private function instruction(Block $block, int $opline): Instruction
    {
        foreach ($block->instructions as $instruction) {
            if ($instruction->index === $opline) {
                return $instruction;
            }
        }

        return $block->instructions[0];
    }

    /**
     * @return list<array{entry: string, end: int}>
     */
    private function handlers(Graph $graph, ExceptionRegions $regions): array
    {
        $handlers = [];
        foreach ($regions->handlerRanges() as $range) {
            $entry = $graph->blockStartingAt($range['entry']) ?? $graph->blockContaining($range['entry']);
            if ($entry === null) {
                continue;
            }
            $handlers[] = ['entry' => $entry->id, 'end' => $range['end']];
        }

        return $handlers;
    }
}
