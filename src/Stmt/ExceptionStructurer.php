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
 * Rebuilds try, catch, and finally statements over bounded regions.
 *
 * The walk is the existing loop and conditional structuring. A block that
 * starts a try region is handed here instead of being read as ordinary
 * flow. Try, catch, and finally bodies are structured by that same walk,
 * stopped at the region's boundaries. FAST_CALL and FAST_RET stay compiler
 * transfers: one finally body is emitted however many calls target it.
 * The control-flow graph is not modified, and exception edges are not turned
 * into successors.
 */
final class ExceptionStructurer
{
    private LoopStructurer $loops;

    /** @var array<int, ExceptionRegion> */
    private array $open = [];

    /** @var list<string> */
    private const COMPLETION = ['RETURN', 'RETURN_BY_REF', 'BRK', 'CONT', 'THROW', 'FAST_CALL'];

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
        $this->open = [];

        return $this->loops->structure($graph, $analysis, $statements, $regions, $this);
    }

    /**
     * @param list<string> $stops
     * @return list<Statement>
     */
    public function emit(ExceptionRegion $region, array $stops): array
    {
        if (!$region->isStructured() || $region->clauses === null) {
            return $this->leaveUnstructured(
                $region,
                $stops,
                $region->reason ?? 'The exception region is not structured.',
            );
        }
        if ($region->hasFinally) {
            return $this->guarded($region, $stops, fn (): array => $this->assembleFinally($region, $stops));
        }
        if ($region->catch === null) {
            return $this->leaveUnstructured(
                $region,
                $stops,
                $region->reason ?? 'The exception region is not structured.',
            );
        }

        return $this->guarded(
            $region,
            $stops,
            fn (): array => $this->assemble($region, $stops, $region->clauses, $region->catch),
        );
    }

    /**
     * A FAST_CALL or FAST_RET of an open finally is compiler machinery.
     * The finally body is emitted once, from the finally entry.
     *
     * @param list<string> $stops
     * @return list<Statement>|null
     */
    public function finishInstruction(Block $block, Instruction $last, array $stops): ?array
    {
        $this->hideDiscards($block);
        if ($last->opcode === 'FAST_RET') {
            if ($this->openEnding($last->index) === null) {
                return null;
            }
            $this->loops->visit($block->id);

            return $this->loops->statementsOf($block);
        }
        if ($last->opcode !== 'FAST_CALL') {
            return null;
        }
        $region = $this->openCalled($last);
        if ($region === null) {
            return null;
        }
        $normal = $this->instructionAt($region->finallyCall ?? -1);
        if (
            !$normal instanceof Instruction
            || $last->result->kind !== $normal->result->kind
            || $last->result->index !== $normal->result->index
        ) {
            throw new UnstructuredFlow(
                $block->id,
                $last->index,
                $last->line,
                'FAST_CALL',
                'FAST_CALL at opline ' . $last->index . ' does not share the finally return address.',
            );
        }
        $this->loops->visit($block->id);
        $statements = $this->loops->statementsOf($block);
        if ($last->index === $region->finallyCall) {
            return $statements;
        }
        $next = $this->loops->blockByStart($last->index + 1);
        $first = $next?->instructions[0] ?? null;
        if (!$next instanceof Block || !$first instanceof Instruction || !in_array($first->opcode, self::COMPLETION, true)) {
            $followed = $first instanceof Instruction ? $first->opcode : 'nothing';
            throw new UnstructuredFlow(
                $block->id,
                $last->index,
                $last->line,
                'FAST_CALL',
                'FAST_CALL at opline ' . $last->index . ' is followed by ' . $followed . ', which is not a return, throw, break, continue, or finally call.',
            );
        }
        foreach ($this->loops->emitGuarded($next, $stops) as $statement) {
            $statements[] = $statement;
        }

        return $statements;
    }

    public function keeps(Instruction $instruction): bool
    {
        if ($instruction->opcode === 'FAST_CALL') {
            return $this->openCalled($instruction) !== null;
        }
        if ($instruction->opcode === 'FAST_RET') {
            return $this->openEnding($instruction->index) !== null;
        }

        return false;
    }

    /**
     * @param callable(): list<Statement> $assemble
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function guarded(ExceptionRegion $region, array $stops, callable $assemble): array
    {
        $saved = $this->loops->capture();
        $this->open[$region->index] = $region;
        $this->loops->open($region->index);
        try {
            return $assemble();
        } catch (UnstructuredFlow $failure) {
            $this->loops->recall($saved);

            return $this->leaveUnstructured($region, $stops, $failure->getMessage());
        } finally {
            unset($this->open[$region->index]);
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
        $this->markDispatch($region, $clauses, $region->spanEnd);
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
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function assembleFinally(ExceptionRegion $region, array $stops): array
    {
        $finallyOpline = $region->finally;
        $finallyEnd = $region->finallyEnd;
        $call = $region->finallyCall;
        if ($finallyOpline === null || $finallyEnd === null || $call === null || $region->clauses === null) {
            throw new UnstructuredFlow('', $region->try, 0, 'TRY', 'The finally region is incomplete.');
        }
        $entry = $this->block($region->try);
        $continuation = $this->block($region->spanEnd);
        $finally = $this->block($finallyOpline);
        $this->hideMachinery($region);

        $bodyStops = $stops;
        $bodyStops[] = $continuation->id;
        $bodyStops[] = $finally->id;
        $callBlock = $this->loops->blockByStart($call);
        if ($callBlock instanceof Block && $callBlock->start === $call && $callBlock->id !== $entry->id) {
            $bodyStops[] = $callBlock->id;
        }
        $catchStops = $bodyStops;
        if ($region->catch !== null) {
            $bodyStops[] = $this->block($region->catch)->id;
        }

        [$body, $catches] = $this->loops->withBoundary(
            [$finally->id, $continuation->id],
            function () use ($region, $entry, $continuation, $bodyStops, $catchStops, $finallyOpline): array {
                $this->markDispatch($region, $region->clauses ?? [], $finallyOpline);
                $body = $this->loops->emitGuarded($entry, $bodyStops);
                $catches = [];
                foreach ($region->clauses ?? [] as $clause) {
                    $catches[] = $this->clause($clause, $continuation->id, $catchStops);
                }

                return [$body, $catches];
            },
        );
        $this->cover($region->try, $finallyOpline);

        $finallyStops = $stops;
        $finallyStops[] = $continuation->id;
        $finallyBody = [];
        if ($finallyOpline < $finallyEnd) {
            $finallyBody = $this->loops->withBoundary(
                [$continuation->id],
                fn (): array => $this->loops->emitGuarded($finally, $finallyStops),
            );
        }
        $this->cover($finallyOpline, $region->spanEnd);

        $callBlock = $this->loops->blockCovering($call);
        $statement = new TryStatement(
            $region->try,
            $this->line($entry, $region->try),
            $body,
            $catches,
            $region->catch,
            $region->spanEnd,
            new FinallyClause(
                $finallyOpline,
                $callBlock instanceof Block ? $this->line($callBlock, $call) : 0,
                $finallyEnd,
                $call,
                $finallyBody,
            ),
        );

        return $this->loops->appendFrom([$statement], $continuation, $stops);
    }

    private function hideMachinery(ExceptionRegion $region): void
    {
        $call = $region->finallyCall;
        $end = $region->finallyEnd;
        $finally = $region->finally;
        if ($call === null || $end === null || $finally === null) {
            return;
        }
        foreach ($this->loops->blocks() as $block) {
            if ($block->end <= $region->try || $block->start >= $region->spanEnd) {
                continue;
            }
            foreach ($block->instructions as $instruction) {
                $ownedCall = $instruction->opcode === 'FAST_CALL'
                    && $instruction->op1->kind === 'try_catch'
                    && $instruction->op1->index === $region->index;
                $ownedReturn = $instruction->opcode === 'FAST_RET' && $instruction->index === $end;
                $ownedDiscard = $instruction->opcode === 'DISCARD_EXCEPTION'
                    && $instruction->index >= $finally
                    && $instruction->index < $end;
                $ownedJump = $instruction->opcode === 'JMP' && $instruction->index === $call + 1;
                if ($ownedCall || $ownedReturn || $ownedDiscard || $ownedJump) {
                    $this->loops->hide($instruction->index);
                }
            }
        }
    }

    private function hideDiscards(Block $block): void
    {
        foreach ($block->instructions as $instruction) {
            if ($instruction->opcode !== 'DISCARD_EXCEPTION') {
                continue;
            }
            foreach ($this->open as $region) {
                if (
                    $region->finally !== null
                    && $region->finallyEnd !== null
                    && $instruction->index >= $region->finally
                    && $instruction->index < $region->finallyEnd
                ) {
                    $this->loops->hide($instruction->index);
                }
            }
        }
    }

    /**
     * @param list<CatchDispatch> $clauses
     */
    private function markDispatch(ExceptionRegion $region, array $clauses, int $end): void
    {
        $catch = $region->catch;
        if ($catch === null) {
            return;
        }
        foreach ($this->loops->blocks() as $block) {
            if ($block->start < $catch || $block->start >= $end) {
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

    /**
     * @param list<string> $extraStops
     */
    private function clause(CatchDispatch $clause, string $continuationId, array $extraStops = []): CatchClause
    {
        $stops = array_values(array_unique(array_merge($extraStops, [$continuationId])));
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
    private function overlapsBody(Block $block, array $clauses): bool
    {
        foreach ($clauses as $clause) {
            if ($block->start < $clause->bodyEnd && $block->end > $clause->bodyStart) {
                return true;
            }
        }

        return false;
    }

    private function openCalled(Instruction $instruction): ?ExceptionRegion
    {
        if ($instruction->op1->kind !== 'try_catch' || !is_int($instruction->op1->index)) {
            return null;
        }
        $region = $this->open[$instruction->op1->index] ?? null;
        if (!$region instanceof ExceptionRegion || !$region->hasFinally) {
            return null;
        }

        return $region;
    }

    private function openEnding(int $opline): ?ExceptionRegion
    {
        $found = null;
        foreach ($this->open as $region) {
            if ($region->finallyEnd === $opline) {
                $found = $region;
            }
        }

        return $found;
    }

    private function instructionAt(int $opline): ?Instruction
    {
        $block = $this->loops->blockCovering($opline);
        if (!$block instanceof Block) {
            return null;
        }
        foreach ($block->instructions as $instruction) {
            if ($instruction->index === $opline) {
                return $instruction;
            }
        }

        return null;
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
