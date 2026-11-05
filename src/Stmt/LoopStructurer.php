<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Cfg\Block;
use Rephper\Cfg\Edge;
use Rephper\Cfg\Graph;
use Rephper\Cfg\LoopAnalysis;
use Rephper\Cfg\LoopControl;
use Rephper\Cfg\NaturalLoop;
use Rephper\Expr\Assignment;
use Rephper\Expr\Binary;
use Rephper\Expr\Diagnostic;
use Rephper\Expr\Expression;
use Rephper\Expr\Literal;
use Rephper\Expr\Opaque;
use Rephper\Expr\Unary;
use Rephper\Expr\Unresolved;
use Rephper\Expr\VariableRead;
use Rephper\Ir\BreakContinue;
use Rephper\Ir\Instruction;
use Rephper\Ir\Operand;

/**
 * Rebuilds while, do-while, for, foreach, and jump-table switch statements
 * from a CFG, natural-loop analysis, and statement lists.
 *
 * Expressions and straight-line statements are reused. A foreach whose value
 * is one compiled variable becomes a ForeachStatement. SWITCH_LONG and
 * SWITCH_STRING become a SwitchStatement when the type-mismatch path restates
 * the jump table. Destructuring, match, a comparison-chain switch, and an
 * unknown loop stay in their original statements with a diagnostic. The graph
 * and the per-block reconstruction are not modified.
 *
 * ConditionalStructurer::structure() is unchanged. It still refuses a loop
 * and a jump-table switch. This walk structures an if inside a supported
 * region, and a supported region inside an if, by stopping at that region's
 * boundary.
 */
final class LoopStructurer
{
    private const EXIT = 'EXIT';

    /**
     * @var array<string, string>
     */
    private const REFUSED = [
        'JMPZ_EX' => 'JMPZ_EX participates in a short-circuit expression and is not structured as a conditional.',
        'JMPNZ_EX' => 'JMPNZ_EX participates in a short-circuit expression and is not structured as a conditional.',
        'JMP_SET' => 'JMP_SET participates in a short-circuit expression and is not structured as a conditional.',
        'COALESCE' => 'COALESCE participates in a short-circuit expression and is not structured as a conditional.',
        'JMP_NULL' => 'JMP_NULL participates in a short-circuit expression and is not structured as a conditional.',
        'SWITCH_LONG' => 'SWITCH_LONG is not structured as a conditional.',
        'SWITCH_STRING' => 'SWITCH_STRING is not structured as a conditional.',
        'MATCH' => 'MATCH is not structured as a conditional.',
        'MATCH_ERROR' => 'MATCH_ERROR is not structured as a conditional.',
        'FE_RESET_R' => 'FE_RESET_R is not structured as a conditional.',
        'FE_RESET_RW' => 'FE_RESET_RW is not structured as a conditional.',
        'FE_FETCH_R' => 'FE_FETCH_R is not structured as a conditional.',
        'FE_FETCH_RW' => 'FE_FETCH_RW is not structured as a conditional.',
        'GOTO' => 'GOTO is not structured as a conditional.',
        'FAST_CALL' => 'FAST_CALL is not structured as a conditional.',
        'FAST_RET' => 'FAST_RET is not structured as a conditional.',
        'CATCH' => 'CATCH is not structured as a conditional.',
        'ASSERT_CHECK' => 'ASSERT_CHECK is not structured as a conditional.',
        'BIND_INIT_STATIC_OR_JMP' => 'BIND_INIT_STATIC_OR_JMP is not structured as a conditional.',
        'JMP_FRAMELESS' => 'JMP_FRAMELESS is not structured as a conditional.',
    ];

    private Graph $graph;

    private Reconstruction $statements;

    private LoopAnalysis $analysis;

    /** @var array<string, Block> */
    private array $byId = [];

    /** @var array<int, Block> */
    private array $byStart = [];

    /** @var array<string, BlockStatements> */
    private array $statementBlocks = [];

    /** @var array<string, NaturalLoop> */
    private array $loopsByHeader = [];

    /** @var array<int, LoopControl> */
    private array $controls = [];

    /** @var array<string, true> */
    private array $visited = [];

    /** @var list<int> */
    private array $structuredOplines = [];

    /**
     * Oplines of foreach machinery that must not appear as statements.
     * A key assignment and each FE_FREE of the iterator being structured.
     *
     * @var array<int, true>
     */
    private array $hiddenOplines = [];

    /**
     * Switch ids in opline order. The key is the SWITCH_* opline.
     *
     * @var array<int, string>
     */
    private array $switchIds = [];

    /**
     * Break-target opline of a switch that was structured, keyed to its id.
     *
     * @var array<int, string>
     */
    private array $switchBreakTargets = [];

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    /** @var list<Diagnostic> */
    private array $originalDiagnostics = [];

    /** @var array<string, true> */
    private array $boundary = [];

    /** @var array<string, array<string, true>> */
    private array $post = [];

    private ?string $activeHeader = null;

    private ?string $latchId = null;

    private ?string $updateBlockId = null;

    private ?int $updateOpline = null;

    private int $loopDepth = 0;

    /**
     * @var list<array{
     *     boundary: array<string, true>,
     *     post: array<string, array<string, true>>,
     *     active: ?string,
     *     latch: ?string,
     *     updateBlock: ?string,
     *     updateOpline: ?int,
     *     depth: int
     * }>
     */
    private array $stack = [];

    public function structure(Graph $graph, LoopAnalysis $analysis, Reconstruction $statements): StructuredStatements
    {
        $this->bind($graph, $analysis, $statements);
        $entry = $graph->blockStartingAt(0);
        if ($entry === null) {
            return new StructuredStatements([], $this->diagnostics);
        }

        try {
            $structured = $this->emit($entry, []);
        } catch (UnstructuredFlow $failure) {
            $diagnostics = $this->originalDiagnostics;
            $diagnostics[] = $this->flowDiagnostic($failure);

            return new StructuredStatements($this->allStatements(), $diagnostics);
        }

        $this->appendMissed($structured);

        return new StructuredStatements($structured, $this->keptDiagnostics());
    }

    private function bind(Graph $graph, LoopAnalysis $analysis, Reconstruction $statements): void
    {
        if (count($graph->blocks) !== count($statements->blocks)) {
            throw new StatementException(
                'Statement reconstruction has ' . count($statements->blocks)
                . ' blocks and the graph has ' . count($graph->blocks)
            );
        }

        $this->graph = $graph;
        $this->statements = $statements;
        $this->analysis = $analysis;
        $this->byId = [];
        $this->byStart = [];
        $this->statementBlocks = [];
        foreach ($graph->blocks as $index => $block) {
            $source = $statements->blocks[$index];
            if ($source->blockId !== $block->id || $source->start !== $block->start || $source->end !== $block->end) {
                throw new StatementException(
                    'Statement block ' . $source->blockId . ' does not match graph block ' . $block->id
                );
            }
            $this->byId[$block->id] = $block;
            $this->byStart[$block->start] = $block;
            $this->statementBlocks[$block->id] = $source;
        }

        $this->loopsByHeader = [];
        foreach ($analysis->loops as $loop) {
            if (!isset($this->byId[$loop->headerId])) {
                throw new StatementException('Loop ' . $loop->id . ' is not in this graph');
            }
            if (isset($this->loopsByHeader[$loop->headerId])) {
                throw new StatementException('Two loops share header ' . $loop->headerId);
            }
            foreach ($loop->blocks as $id) {
                if (!isset($this->byId[$id])) {
                    throw new StatementException('Loop ' . $loop->id . ' names missing block ' . $id);
                }
            }
            $this->loopsByHeader[$loop->headerId] = $loop;
        }

        $this->controls = [];
        foreach ($analysis->controls as $control) {
            $this->controls[$control->opline] = $control;
        }

        $this->visited = [];
        $this->structuredOplines = [];
        $this->hiddenOplines = [];
        $this->switchBreakTargets = [];
        $this->switchIds = [];
        $switchCount = 0;
        foreach ($graph->blocks as $block) {
            foreach ($block->instructions as $instruction) {
                if ($instruction->opcode === 'SWITCH_LONG' || $instruction->opcode === 'SWITCH_STRING') {
                    $this->switchIds[$instruction->index] = 'S' . $switchCount;
                    $switchCount++;
                }
            }
        }
        $this->originalDiagnostics = $statements->diagnostics;
        $this->diagnostics = $statements->diagnostics;
        $this->boundary = [];
        $this->activeHeader = null;
        $this->latchId = null;
        $this->updateBlockId = null;
        $this->updateOpline = null;
        $this->loopDepth = 0;
        $this->stack = [];
        $this->post = $this->postDominators();
    }

    /**
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function emit(Block $block, array $stops): array
    {
        if ($this->stopped($block->id, $stops)) {
            return [];
        }
        if ($this->updateBlockId !== null && $block->id === $this->updateBlockId) {
            return $this->updatePrefix($block);
        }
        if ($this->latchId !== null && $block->id === $this->latchId) {
            return $this->latchStatements($block);
        }

        $loop = $this->loopsByHeader[$block->id] ?? null;
        if ($loop instanceof NaturalLoop && $block->id !== $this->activeHeader) {
            if (isset($this->visited[$block->id])) {
                $this->fail($block, $this->last($block), 'The block is reached by more than one structured path.');
            }

            return $this->emitLoop($loop, $stops);
        }
        if (isset($this->visited[$block->id])) {
            $this->fail($block, $this->last($block), 'The block is reached by more than one structured path.');
        }

        $resetLoop = $this->foreachByReset($block);
        if (
            $resetLoop instanceof NaturalLoop
            && $resetLoop->headerId !== $this->activeHeader
            && !isset($this->visited[$resetLoop->headerId])
        ) {
            return $this->enterForeach($block, $resetLoop, $stops);
        }
        if ($this->isSwitchBlock($block)) {
            return $this->enterSwitch($block, $stops);
        }

        $this->refuse($block);
        $last = $this->last($block);
        if ($last->opcode === 'JMP' && count($block->successors) === 1) {
            $entered = $this->loopsByHeader[$block->successors[0]->to] ?? null;
            if (
                $entered instanceof NaturalLoop
                && ($entered->kind === NaturalLoop::FOR || $entered->kind === NaturalLoop::WHILE)
                && $entered->headerId !== $this->activeHeader
                && !isset($this->visited[$entered->headerId])
                && $this->isPrologue($block, $entered)
            ) {
                return $this->enterLoop($block, $entered, $stops);
            }
        }
        if ($last->opcode === 'JMPZ' || $last->opcode === 'JMPNZ') {
            return $this->emitConditional($block, $last, $stops);
        }
        if ($last->opcode === 'BRK' || $last->opcode === 'CONT') {
            $this->visited[$block->id] = true;

            return $this->controlStatements($block, $last);
        }
        if ($block->exceptions !== []) {
            $this->fail($block, $last, 'The region contains an exception edge.');
        }
        if (count($block->successors) > 1) {
            $this->fail($block, $last, $last->opcode . ' has multiple successors and is not structured as a conditional.');
        }

        $next = null;
        $follow = false;
        if (count($block->successors) === 1) {
            $next = $this->block($block->successors[0]->to);
            $follow = !$this->stopped($next->id, $stops) && !isset($this->boundary[$next->id]);
            if ($follow && $next->start <= $block->start) {
                $this->fail($block, $last, 'The branch target is not a forward edge.');
            }
        }

        $this->visited[$block->id] = true;
        $statements = $this->blockStatements($block);
        if ($follow && $next instanceof Block) {
            $statements = $this->appendWalk($statements, $next, $stops);
        }

        return $statements;
    }

    /**
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function emitConditional(Block $header, Instruction $branch, array $stops): array
    {
        $saved = $this->snapshot();
        try {
            [$conditional, $merge] = $this->buildConditional($header, $branch);
        } catch (UnstructuredFlow $failure) {
            $this->restore($saved);
            throw $failure;
        }

        $this->visited[$header->id] = true;
        $statements = $this->statementsBefore($header, $branch->index);
        $statements[] = $conditional;
        if (!$this->stopped($merge->id, $stops)) {
            $statements = $this->appendWalk($statements, $merge, $stops);
        }

        return $statements;
    }

    /**
     * @param list<string> $stops
     * @param list<string> $extra
     * @return list<string>
     */
    private function withStops(array $stops, array $extra): array
    {
        return array_values(array_unique(array_merge($stops, $extra)));
    }

    /**
     * @param list<Statement> $statements
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function appendWalk(array $statements, Block $next, array $stops): array
    {
        try {
            foreach ($this->emit($next, $stops) as $statement) {
                $statements[] = $statement;
            }
        } catch (UnstructuredFlow $failure) {
            if ($this->loopDepth > 0 || $stops !== []) {
                throw $failure;
            }
            $this->diagnostics[] = $this->flowDiagnostic($failure);
            foreach ($this->unvisitedStatements() as $statement) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function emitLoop(NaturalLoop $loop, array $stops): array
    {
        if ($loop->kind === NaturalLoop::WHILE || $loop->kind === NaturalLoop::DO_WHILE) {
            return $this->emitSupported($loop, $stops);
        }
        if ($loop->kind === NaturalLoop::FOR) {
            $prologue = $this->prologue($loop);
            if ($prologue instanceof Block && !isset($this->visited[$prologue->id])) {
                return $this->enterLoop($prologue, $loop, $stops);
            }
        }
        if ($loop->kind === NaturalLoop::FOREACH) {
            $reset = $this->resetBlock($loop);
            if ($reset instanceof Block && !isset($this->visited[$reset->id])) {
                return $this->enterForeach($reset, $loop, $stops);
            }
        }

        $this->diagnostics[] = $this->loopDiagnostic($loop, $loop->reason ?? $this->unsupportedReason($loop));

        return $this->emitOriginal($loop, $stops);
    }

    /**
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function emitSupported(NaturalLoop $loop, array $stops): array
    {
        $saved = $this->snapshot();
        try {
            if ($loop->kind === NaturalLoop::WHILE) {
                [$statement, $continuation] = $this->buildWhile($loop, $stops);
            } else {
                [$statement, $continuation] = $this->buildDoWhile($loop, $stops);
            }
        } catch (UnstructuredFlow $failure) {
            $this->restore($saved);
            $this->diagnostics[] = $this->flowDiagnostic($failure);

            return $this->emitOriginal($loop, $stops);
        }

        return $this->appendContinuation([$statement], $continuation, $stops);
    }

    /**
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function enterLoop(Block $prologue, NaturalLoop $loop, array $stops): array
    {
        if ($loop->kind === NaturalLoop::FOR || $this->presentAsFor($prologue, $loop)) {
            return $this->emitFor($prologue, $loop, $stops);
        }

        $this->visited[$prologue->id] = true;
        $header = $this->block($loop->headerId);

        return $this->appendWalk($this->blockStatements($prologue), $header, $stops);
    }

    /**
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function emitFor(Block $prologue, NaturalLoop $loop, array $stops): array
    {
        $saved = $this->snapshot();
        try {
            if ($loop->kind === NaturalLoop::FOR) {
                [$leading, $statement, $continuation] = $this->buildFor($prologue, $loop, $stops);
            } else {
                [$leading, $statement, $continuation] = $this->buildPromotedFor($prologue, $loop, $stops);
            }
        } catch (UnstructuredFlow $failure) {
            $this->restore($saved);
            $this->diagnostics[] = $this->flowDiagnostic($failure);
            $this->visited[$prologue->id] = true;

            return array_merge($this->blockStatements($prologue), $this->emitOriginal($loop, $stops));
        }
        $this->visited[$prologue->id] = true;

        return $this->appendContinuation(array_merge($leading, [$statement]), $continuation, $stops);
    }

    /**
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function enterForeach(Block $reset, NaturalLoop $loop, array $stops): array
    {
        $saved = $this->snapshot();
        try {
            [$leading, $statement, $continuation] = $this->buildForeach($reset, $loop, $stops);
        } catch (UnstructuredFlow $failure) {
            $this->restore($saved);
            $this->diagnostics[] = $this->flowDiagnostic($failure);
            $this->visited[$reset->id] = true;

            return array_merge($this->blockStatements($reset), $this->emitOriginal($loop, $stops));
        }
        $this->visited[$reset->id] = true;

        return $this->appendContinuation(array_merge($leading, [$statement]), $continuation, $stops);
    }

    /**
     * @param list<string> $stops
     * @return array{list<Statement>, ForeachStatement, Block}
     */
    private function buildForeach(Block $reset, NaturalLoop $loop, array $stops): array
    {
        $resetInstruction = $this->last($reset);
        if (!$this->isReset($resetInstruction) || $this->foreachByReset($reset) !== $loop) {
            $this->fail($reset, $resetInstruction, 'foreach is not entered from FE_RESET.');
        }
        if (
            count($loop->entries) !== 1
            || $loop->entries[0]->from !== $reset->id
            || $loop->entries[0]->to !== $loop->headerId
            || $loop->entries[0]->kind !== 'fallthrough'
        ) {
            $this->fail($reset, $resetInstruction, 'foreach is not entered from FE_RESET.');
        }

        $header = $this->block($loop->headerId);
        $fetch = $this->last($header);
        $expectedFetch = $resetInstruction->opcode === 'FE_RESET_RW' ? 'FE_FETCH_RW' : 'FE_FETCH_R';
        if ($fetch->opcode !== $expectedFetch) {
            $this->fail($header, $fetch, 'foreach reset and fetch disagree about by-reference iteration.');
        }
        $iterator = $resetInstruction->result->index;
        if ($resetInstruction->result->kind !== 'var' || $iterator === null) {
            $this->fail($reset, $resetInstruction, 'foreach reset does not define an iterator.');
        }
        if (!$this->sameSlot($fetch->op1, 'var', $iterator)) {
            $this->fail($header, $fetch, 'foreach fetch does not use the reset iterator.');
        }
        foreach ($this->blockStatements($header) as $statement) {
            if ($statement->opline !== $fetch->index) {
                $this->fail($header, $fetch, 'foreach header contains a statement before the fetch.');
            }
        }

        $iterable = $this->iterableExpression($reset, $resetInstruction);
        $value = $this->valueTarget($header, $fetch);
        $bodyEdge = $this->bodyEdge($header, $loop);
        if ($bodyEdge->kind !== 'fallthrough') {
            $this->fail($header, $fetch, 'foreach body is not the fetch fallthrough.');
        }
        $exit = $this->exitEdge($header, $loop);
        if ($exit->kind !== 'foreach_exit') {
            $this->fail($header, $fetch, 'foreach exit is not the fetch exit.');
        }
        $exitBlock = $this->block($exit->to);
        $key = null;
        if ($fetch->result->kind === 'unused') {
            $key = null;
        } elseif (
            ($fetch->result->kind === 'tmp' || $fetch->result->kind === 'var')
            && $fetch->result->index !== null
        ) {
            if ($bodyEdge->to === $header->id) {
                $this->fail($header, $fetch, 'foreach key is not assigned to a compiled variable.');
            }
            $key = $this->keyTarget($this->block($bodyEdge->to), $fetch);
        } else {
            $this->fail($header, $fetch, 'foreach key result is not a temporary.');
        }
        $exitFree = $this->exitFree($exitBlock, $iterator);

        $leading = [];
        foreach ($this->blockStatements($reset) as $statement) {
            if ($statement->opline === $resetInstruction->index && $statement instanceof OpaqueStatement) {
                continue;
            }
            $leading[] = $statement;
        }

        $this->structuredOplines[] = $resetInstruction->index;
        $this->structuredOplines[] = $fetch->index;
        $this->hideIteratorFrees($reset, $iterator, $exitFree);
        if ($key instanceof CompiledVariableTarget) {
            $this->hiddenOplines[$key->opline] = true;
            $this->structuredOplines[] = $key->opline;
        }

        $this->push($header->id, $exitBlock->id, null);
        try {
            $this->visited[$header->id] = true;
            $bodyStops = $this->withStops($stops, [$header->id, $exitBlock->id]);
            if ($bodyEdge->to === $header->id) {
                $body = [];
            } else {
                $body = $this->emit($this->block($bodyEdge->to), $bodyStops);
            }
            $this->assertVisited($loop);
        } finally {
            $this->pop();
        }

        return [
            $leading,
            new ForeachStatement(
                $fetch->index,
                $fetch->line,
                $iterable,
                $key,
                $value,
                $fetch->opcode === 'FE_FETCH_RW',
                $body,
                $loop->id,
            ),
            $exitBlock,
        ];
    }

    private function presentAsFor(Block $prologue, NaturalLoop $loop): bool
    {
        if ($loop->kind !== NaturalLoop::WHILE || !$this->isPrologue($prologue, $loop)) {
            return false;
        }
        try {
            [, $initializers] = $this->splitInitializers($prologue);
        } catch (UnstructuredFlow) {
            return false;
        }

        return $initializers !== [] || $this->omittedCondition($prologue, $loop);
    }

    /**
     * @param list<string> $stops
     * @return array{list<Statement>, ForStatement, Block}
     */
    private function buildFor(Block $prologue, NaturalLoop $loop, array $stops): array
    {
        if (!$this->isPrologue($prologue, $loop)) {
            $header = $this->block($loop->headerId);
            $this->fail($header, $this->last($header), 'for is not entered through its prologue jump.');
        }
        $header = $this->block($loop->headerId);
        $branch = $this->last($header);
        if ($branch->opcode !== 'JMPZ' && $branch->opcode !== 'JMPNZ') {
            $this->fail($header, $branch, 'for header does not end with a condition branch.');
        }
        $bodyEdge = $this->bodyEdge($header, $loop);
        $continuation = $this->block($this->exitEdge($header, $loop)->to);
        if ($this->statementsBefore($header, $branch->index) !== []) {
            $this->fail($header, $branch, 'The condition block contains a statement before the branch.');
        }
        $condition = $this->loopCondition($header, $branch, $bodyEdge->kind);
        if ($this->synthesizedTrue($prologue, $header, $condition)) {
            $condition = null;
        }
        if ($loop->continueOpline === null) {
            $this->fail($header, $branch, 'for has no continue target.');
        }
        [$leading, $initializers] = $this->splitInitializers($prologue);
        $updates = $this->updateExpressions($loop, $loop->continueOpline);
        $updateBlock = $this->blockContaining($loop, $loop->continueOpline);

        $this->push($header->id, $continuation->id, null);
        try {
            $this->updateBlockId = $updateBlock->id;
            $this->updateOpline = $loop->continueOpline;
            $this->visited[$header->id] = true;
            $bodyStops = $this->withStops($stops, [$header->id, $continuation->id]);
            foreach ($this->pureUpdateBlocks($loop, $updateBlock, $loop->continueOpline) as $id) {
                $this->visited[$id] = true;
                $bodyStops[] = $id;
            }
            if ($bodyEdge->to === $header->id) {
                $body = [];
            } else {
                $body = $this->emit($this->block($bodyEdge->to), $bodyStops);
            }
            $this->assertVisited($loop);
        } finally {
            $this->pop();
        }

        return [
            $leading,
            new ForStatement($branch->index, $branch->line, $initializers, $condition, $updates, $body, $loop->id),
            $continuation,
        ];
    }

    /**
     * A while shape whose prologue owns initializers, or whose true test was
     * synthesized for an omitted condition, is the compiler's empty-update
     * or empty-condition for.
     *
     * @param list<string> $stops
     * @return array{list<Statement>, ForStatement, Block}
     */
    private function buildPromotedFor(Block $prologue, NaturalLoop $loop, array $stops): array
    {
        [$leading, $initializers] = $this->splitInitializers($prologue);
        $omitted = $this->omittedCondition($prologue, $loop);
        [$while, $continuation] = $this->buildWhile($loop, $stops);

        return [
            $leading,
            new ForStatement(
                $while->opline,
                $while->line,
                $initializers,
                $omitted ? null : $while->condition,
                [],
                $while->body,
                $while->loopId,
            ),
            $continuation,
        ];
    }

    private function isPrologue(Block $block, NaturalLoop $loop): bool
    {
        return count($loop->entries) === 1
            && $loop->entries[0]->kind === 'jump'
            && $loop->entries[0]->from === $block->id
            && $loop->entries[0]->to === $loop->headerId
            && $this->last($block)->opcode === 'JMP';
    }

    private function prologue(NaturalLoop $loop): ?Block
    {
        if (count($loop->entries) !== 1 || $loop->entries[0]->kind !== 'jump' || $loop->entries[0]->to !== $loop->headerId) {
            return null;
        }
        $block = $this->block($loop->entries[0]->from);

        return $this->last($block)->opcode === 'JMP' ? $block : null;
    }

    /**
     * Statements in the prologue that share the jump's line are the for
     * initializers. An earlier statement on another line stays before the loop.
     *
     * @return array{list<Statement>, list<Expression>}
     */
    private function splitInitializers(Block $prologue): array
    {
        $line = $this->last($prologue)->line;
        $statements = $this->statementsBefore($prologue, $this->last($prologue)->index);
        $initializers = [];
        while ($statements !== [] && $statements[array_key_last($statements)]->line === $line) {
            array_unshift($initializers, $this->expressionOf($prologue, array_pop($statements)));
        }

        return [$statements, $initializers];
    }

    /**
     * @return list<Expression>
     */
    private function updateExpressions(NaturalLoop $loop, int $continueAt): array
    {
        $block = $this->blockContaining($loop, $continueAt);
        if ($block->id === $loop->headerId) {
            $this->fail($block, $this->last($block), 'for update target is the condition.');
        }
        $expressions = [];
        $guard = 0;
        while ($block->id !== $loop->headerId) {
            if (++$guard > count($loop->blocks)) {
                $this->fail($block, $this->last($block), 'for update path does not reach the condition.');
            }
            foreach ($this->blockStatements($block) as $statement) {
                if ($statement->opline < $continueAt) {
                    continue;
                }
                $expressions[] = $this->expressionOf($block, $statement);
            }
            if (count($block->successors) !== 1) {
                $this->fail($block, $this->last($block), 'for update is not a straight path to the condition.');
            }
            $next = $this->block($block->successors[0]->to);
            if ($next->id !== $loop->headerId && !$loop->contains($next->id)) {
                $this->fail($block, $this->last($block), 'for update leaves the loop before the condition.');
            }
            $block = $next;
        }

        return $expressions;
    }

    /**
     * @return list<string>
     */
    private function pureUpdateBlocks(NaturalLoop $loop, Block $first, int $continueAt): array
    {
        $ids = [];
        $block = $first;
        $guard = 0;
        while ($block->id !== $loop->headerId) {
            if (++$guard > count($loop->blocks)) {
                break;
            }
            $shared = false;
            foreach ($this->blockStatements($block) as $statement) {
                if ($statement->opline < $continueAt) {
                    $shared = true;
                    break;
                }
            }
            if (!$shared) {
                $ids[] = $block->id;
            }
            if (count($block->successors) !== 1) {
                break;
            }
            $block = $this->block($block->successors[0]->to);
        }

        return $ids;
    }

    private function blockContaining(NaturalLoop $loop, int $opline): Block
    {
        foreach ($loop->blocks as $id) {
            $block = $this->block($id);
            if ($block->start <= $opline && $opline < $block->end) {
                return $block;
            }
        }
        $header = $this->block($loop->headerId);
        $this->fail($header, $this->last($header), 'for continue target is not in the loop.');
    }

    /**
     * @return list<Statement>
     */
    private function updatePrefix(Block $block): array
    {
        if (isset($this->visited[$block->id])) {
            $this->fail($block, $this->last($block), 'The block is reached by more than one structured path.');
        }
        $this->visited[$block->id] = true;
        $statements = [];
        foreach ($this->blockStatements($block) as $statement) {
            if ($statement->opline < ($this->updateOpline ?? $block->end)) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    private function expressionOf(Block $block, Statement $statement): Expression
    {
        if ($statement instanceof ExpressionStatement) {
            return $statement->expression;
        }
        if ($statement instanceof OpaqueStatement) {
            return $statement->instruction;
        }
        $this->fail($block, $this->last($block), 'for initializer or update is not an expression.');
    }

    private function omittedCondition(Block $prologue, NaturalLoop $loop): bool
    {
        $header = $this->block($loop->headerId);
        $branch = $this->last($header);
        if (($branch->opcode !== 'JMPZ' && $branch->opcode !== 'JMPNZ') || $this->statementsBefore($header, $branch->index) !== []) {
            return false;
        }
        $resolved = null;
        foreach ($this->blockStatements($header) as $statement) {
            if ($statement instanceof OpaqueStatement && $statement->opline === $branch->index) {
                $resolved = $statement->instruction->op1->resolved;
                break;
            }
        }
        if (!$resolved instanceof Expression || $resolved instanceof Unresolved) {
            return false;
        }
        try {
            if ($this->bodyEdge($header, $loop)->kind !== 'true') {
                return false;
            }
        } catch (UnstructuredFlow) {
            return false;
        }

        return $this->synthesizedTrue($prologue, $header, $resolved);
    }

    private function synthesizedTrue(Block $prologue, Block $header, Expression $condition): bool
    {
        if (!$condition instanceof Literal || $condition->type !== 'bool' || $condition->value !== true) {
            return false;
        }
        $branch = $this->last($header);
        $previous = $this->instructionBefore($branch->index);
        if ($previous === null || $previous->line !== $branch->line) {
            return false;
        }

        return $branch->line !== $this->last($prologue)->line;
    }

    private function instructionBefore(int $index): ?Instruction
    {
        foreach ($this->graph->blocks as $block) {
            foreach ($block->instructions as $instruction) {
                if ($instruction->index === $index - 1) {
                    return $instruction;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $stops
     * @return array{WhileStatement, Block}
     */
    private function buildWhile(NaturalLoop $loop, array $stops): array
    {
        $header = $this->block($loop->headerId);
        $branch = $this->last($header);
        if ($branch->opcode !== 'JMPZ' && $branch->opcode !== 'JMPNZ') {
            $this->fail($header, $branch, 'while header does not end with a condition branch.');
        }
        $bodyEdge = $this->bodyEdge($header, $loop);
        $continuation = $this->block($this->exitEdge($header, $loop)->to);
        if ($this->statementsBefore($header, $branch->index) !== []) {
            $this->fail($header, $branch, 'The condition block contains a statement before the branch.');
        }
        $condition = $this->loopCondition($header, $branch, $bodyEdge->kind);

        $this->push($header->id, $continuation->id, null);
        try {
            $this->visited[$header->id] = true;
            if ($bodyEdge->to === $header->id) {
                $body = [];
            } else {
                $body = $this->emit($this->block($bodyEdge->to), $this->withStops($stops, [$header->id, $continuation->id]));
            }
            $this->assertVisited($loop);
        } finally {
            $this->pop();
        }

        return [
            new WhileStatement($branch->index, $branch->line, $condition, $body, $loop->id),
            $continuation,
        ];
    }

    /**
     * @param list<string> $stops
     * @return array{DoWhileStatement, Block}
     */
    private function buildDoWhile(NaturalLoop $loop, array $stops): array
    {
        [$latch, $back] = $this->latch($loop);
        $branch = $this->last($latch);
        $continuation = $this->block($this->exitEdge($latch, $loop)->to);
        $condition = $this->loopCondition($latch, $branch, $back->kind);
        $header = $this->block($loop->headerId);

        $this->push($header->id, $continuation->id, $latch->id);
        try {
            if ($latch->id === $header->id) {
                $this->visited[$header->id] = true;
                $body = $this->statementsBefore($header, $branch->index);
            } else {
                $body = $this->emit($header, $this->withStops($stops, [$continuation->id]));
            }
            $this->assertVisited($loop);
        } finally {
            $this->pop();
        }

        return [
            new DoWhileStatement($branch->index, $branch->line, $condition, $body, $loop->id),
            $continuation,
        ];
    }

    /**
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function emitOriginal(NaturalLoop $loop, array $stops): array
    {
        $statements = [];
        foreach ($loop->blocks as $id) {
            $block = $this->block($id);
            $this->visited[$block->id] = true;
            foreach ($this->blockStatements($block) as $statement) {
                $statements[] = $statement;
            }
        }

        return $this->appendContinuation($statements, $this->continuationOf($loop), $stops);
    }

    /**
     * @param list<Statement> $statements
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function appendContinuation(array $statements, ?Block $continuation, array $stops): array
    {
        if (!$continuation instanceof Block || isset($this->visited[$continuation->id]) || $this->stopped($continuation->id, $stops)) {
            return $statements;
        }

        return $this->appendWalk($statements, $continuation, $stops);
    }

    /**
     * @return list<Statement>
     */
    private function latchStatements(Block $block): array
    {
        if (isset($this->visited[$block->id])) {
            $this->fail($block, $this->last($block), 'The block is reached by more than one structured path.');
        }
        $branch = $this->last($block);
        if ($branch->opcode !== 'JMPZ' && $branch->opcode !== 'JMPNZ') {
            $this->fail($block, $branch, 'do-while condition does not end with a condition branch.');
        }
        $this->visited[$block->id] = true;

        return $this->statementsBefore($block, $branch->index);
    }

    private function push(string $headerId, string $continuationId, ?string $latchId): void
    {
        $this->stack[] = [
            'boundary' => $this->boundary,
            'post' => $this->post,
            'active' => $this->activeHeader,
            'latch' => $this->latchId,
            'updateBlock' => $this->updateBlockId,
            'updateOpline' => $this->updateOpline,
            'depth' => $this->loopDepth,
        ];
        $this->boundary = $this->boundary + [$headerId => true, $continuationId => true];
        $this->post = $this->postDominators();
        $this->activeHeader = $headerId;
        $this->latchId = $latchId;
        $this->loopDepth++;
    }

    private function pop(): void
    {
        $frame = array_pop($this->stack);
        if (!is_array($frame)) {
            throw new StatementException('Loop structuring stack is empty');
        }
        $this->boundary = $frame['boundary'];
        $this->post = $frame['post'];
        $this->activeHeader = $frame['active'];
        $this->latchId = $frame['latch'];
        $this->updateBlockId = $frame['updateBlock'];
        $this->updateOpline = $frame['updateOpline'];
        $this->loopDepth = $frame['depth'];
    }

    private function foreachByReset(Block $block): ?NaturalLoop
    {
        if ($block->instructions === []) {
            return null;
        }
        $last = $block->instructions[array_key_last($block->instructions)];
        if (!$this->isReset($last)) {
            return null;
        }
        $target = null;
        foreach ($block->successors as $edge) {
            if ($edge->kind === 'fallthrough') {
                $target = $edge->to;
                break;
            }
        }
        if ($target === null) {
            return null;
        }
        $loop = $this->loopsByHeader[$target] ?? null;
        if (!$loop instanceof NaturalLoop || $loop->kind !== NaturalLoop::FOREACH || $loop->headerId !== $target) {
            return null;
        }
        $fetch = $this->last($this->block($loop->headerId));
        if ($fetch->opcode !== 'FE_FETCH_R' && $fetch->opcode !== 'FE_FETCH_RW') {
            return null;
        }

        return $loop;
    }

    private function resetBlock(NaturalLoop $loop): ?Block
    {
        if ($loop->kind !== NaturalLoop::FOREACH || count($loop->entries) !== 1) {
            return null;
        }
        $entry = $loop->entries[0];
        if ($entry->to !== $loop->headerId || $entry->kind !== 'fallthrough') {
            return null;
        }
        $block = $this->block($entry->from);
        if (!$this->isReset($this->last($block)) || $this->foreachByReset($block) !== $loop) {
            return null;
        }

        return $block;
    }

    private function isReset(Instruction $instruction): bool
    {
        return $instruction->opcode === 'FE_RESET_R' || $instruction->opcode === 'FE_RESET_RW';
    }

    private function iterableExpression(Block $reset, Instruction $instruction): Expression
    {
        foreach ($this->blockStatements($reset) as $statement) {
            if (
                !$statement instanceof OpaqueStatement
                || $statement->opline !== $instruction->index
                || $statement->instruction->opcode !== $instruction->opcode
            ) {
                continue;
            }
            $resolved = $statement->instruction->op1->resolved;
            if (!$resolved instanceof Expression || $resolved instanceof Unresolved) {
                $this->fail($reset, $instruction, 'foreach iterable expression is missing.');
            }

            return $resolved;
        }

        $this->fail($reset, $instruction, 'foreach iterable expression is missing.');
    }

    private function valueTarget(Block $header, Instruction $fetch): CompiledVariableTarget
    {
        if ($fetch->op2->kind !== 'cv' || $fetch->op2->name === null || $fetch->op2->index === null) {
            $this->fail($header, $fetch, 'foreach value target is not a compiled variable.');
        }

        return new CompiledVariableTarget($fetch->op2->name, $fetch->op2->index, $fetch->index, $fetch->line);
    }

    private function keyTarget(Block $body, Instruction $fetch): CompiledVariableTarget
    {
        $statements = $this->blockStatements($body);
        $first = $statements[0] ?? null;
        if (
            !$first instanceof ExpressionStatement
            || !$first->expression instanceof Assignment
            || $first->expression->operator !== null
        ) {
            $this->fail($body, $this->last($body), 'foreach key is not assigned to a compiled variable.');
        }
        $value = $first->expression->value;
        if (
            !$value instanceof Unresolved
            || $value->kind !== $fetch->result->kind
            || $value->index !== $fetch->result->index
        ) {
            $this->fail($body, $this->last($body), 'foreach key is not the fetch result.');
        }
        $assignment = null;
        foreach ($body->instructions as $instruction) {
            if ($instruction->index === $first->opline) {
                $assignment = $instruction;
                break;
            }
        }
        if (
            !$assignment instanceof Instruction
            || $assignment->opcode !== 'ASSIGN'
            || $assignment->op1->kind !== 'cv'
            || $assignment->op1->name !== $first->expression->variable
            || $assignment->op1->index === null
        ) {
            $this->fail($body, $assignment ?? $this->last($body), 'foreach key is not assigned to a compiled variable.');
        }

        return new CompiledVariableTarget(
            $assignment->op1->name,
            $assignment->op1->index,
            $assignment->index,
            $assignment->line,
        );
    }

    private function exitFree(Block $exit, int $iterator): Instruction
    {
        foreach ($exit->instructions as $instruction) {
            if ($instruction->opcode === 'FE_FREE' && $this->sameSlot($instruction->op1, 'var', $iterator)) {
                return $instruction;
            }
        }
        $this->fail($exit, $this->last($exit), 'foreach exit does not free its iterator.');
    }

    private function hideIteratorFrees(Block $reset, int $iterator, Instruction $exitFree): void
    {
        $found = false;
        foreach ($this->graph->blocks as $block) {
            foreach ($block->instructions as $instruction) {
                if ($instruction->opcode !== 'FE_FREE' || !$this->sameSlot($instruction->op1, 'var', $iterator)) {
                    continue;
                }
                if ($instruction->index < $this->last($reset)->index || $instruction->index > $exitFree->index) {
                    continue;
                }
                $this->hiddenOplines[$instruction->index] = true;
                $this->structuredOplines[] = $instruction->index;
                $found = true;
            }
        }
        if (!$found) {
            $this->fail($reset, $this->last($reset), 'foreach exit does not free its iterator.');
        }
    }

    private function sameSlot(Operand $operand, string $kind, int $index): bool
    {
        return $operand->kind === $kind && $operand->index === $index;
    }

    private function isSwitchBlock(Block $block): bool
    {
        if ($block->instructions === []) {
            return false;
        }
        $opcode = $this->last($block)->opcode;

        return $opcode === 'SWITCH_LONG' || $opcode === 'SWITCH_STRING';
    }

    private function keepsSwitchOpcode(Instruction $instruction): bool
    {
        return $instruction->opcode === 'SWITCH_LONG' || $instruction->opcode === 'SWITCH_STRING';
    }

    /**
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function enterSwitch(Block $block, array $stops): array
    {
        $saved = $this->snapshot();
        try {
            [$leading, $statement, $end] = $this->buildSwitch($block, $stops);
        } catch (UnstructuredFlow $failure) {
            $this->restore($saved);
            $end = $this->switchEnd($block);
            if (!$end instanceof Block) {
                throw $failure;
            }
            $this->diagnostics[] = $this->flowDiagnostic($failure);

            return $this->emitSwitchFallback($block, $end, $stops);
        }
        $this->visited[$block->id] = true;

        return $this->appendContinuation(array_merge($leading, [$statement]), $end, $stops);
    }

    /**
     * @param list<string> $stops
     * @return array{list<Statement>, SwitchStatement, Block}
     */
    private function buildSwitch(Block $block, array $stops): array
    {
        $switch = $this->last($block);
        if (!$this->keepsSwitchOpcode($switch)) {
            $this->fail($block, $switch, 'switch opcode is not a jump table.');
        }
        $switchId = $this->switchIds[$switch->index] ?? null;
        if (!is_string($switchId)) {
            $this->fail($block, $switch, 'switch identifier is missing.');
        }

        $table = [];
        foreach ($block->successors as $edge) {
            if ($edge->kind !== 'switch') {
                continue;
            }
            if (!is_int($edge->case) && !is_string($edge->case)) {
                $this->fail($block, $switch, 'switch case label is missing.');
            }
            $table[] = $edge;
        }
        if ($table === []) {
            $this->fail($block, $switch, 'switch jump table is missing.');
        }

        $defaultEdge = $this->soleEdge($block, $switch, 'default');
        $defaultBlock = $this->block($defaultEdge->to);
        $targets = [];
        foreach ($table as $edge) {
            $targets[] = $this->block($edge->to)->start;
        }
        $row = $this->switchBreakRow($block, $switch, $targets, $defaultBlock->start);
        $end = $this->graph->blockStartingAt($row->breakTarget);
        if (!$end instanceof Block) {
            $this->fail($block, $switch, 'switch break target is missing.');
        }

        $subject = $this->switchSubject($block, $switch);
        $arms = $this->fallbackArms($block, $switch, $subject, $table, $defaultBlock);

        $groups = [];
        foreach ($arms as $arm) {
            $id = $arm['target']->id;
            if (!isset($groups[$id])) {
                $groups[$id] = [
                    'labels' => [],
                    'block' => $arm['target'],
                    'isDefault' => false,
                ];
            }
            $groups[$id]['labels'][] = $arm['literal'];
        }
        if ($defaultBlock->id !== $end->id) {
            if (!isset($groups[$defaultBlock->id])) {
                $groups[$defaultBlock->id] = [
                    'labels' => [],
                    'block' => $defaultBlock,
                    'isDefault' => true,
                ];
            } else {
                $groups[$defaultBlock->id]['isDefault'] = true;
            }
        }
        uasort($groups, static function (array $left, array $right): int {
            return $left['block']->start <=> $right['block']->start;
        });
        $ordered = array_values($groups);
        $indexOf = [];
        foreach ($ordered as $index => $group) {
            $indexOf[$group['block']->id] = $index;
        }

        $fallback = [];
        foreach ($arms as $arm) {
            $fallback[] = new SwitchFallbackArm(
                $arm['opline'],
                $arm['line'],
                $arm['literal'],
                $indexOf[$arm['target']->id],
            );
        }

        $this->switchBreakTargets[$row->breakTarget] = $switchId;
        $this->structuredOplines[] = $switch->index;
        $this->hideSubjectFrees($switch, $row->breakTarget);

        $cases = [];
        $count = count($ordered);
        for ($i = 0; $i < $count; $i++) {
            $group = $ordered[$i];
            $caseStops = $stops;
            $caseStops[] = $end->id;
            for ($next = $i + 1; $next < $count; $next++) {
                $caseStops[] = $ordered[$next]['block']->id;
            }
            $before = $this->visited;
            $body = $this->emit($group['block'], array_values(array_unique($caseStops)));
            $fallsThrough = false;
            if ($i + 1 < $count) {
                $nextId = $ordered[$i + 1]['block']->id;
                foreach (array_keys($this->visited) as $id) {
                    if (isset($before[$id])) {
                        continue;
                    }
                    foreach ($this->block($id)->successors as $edge) {
                        if ($edge->to === $nextId) {
                            $fallsThrough = true;
                        }
                    }
                }
            }
            $line = $group['labels'] !== []
                ? $group['labels'][0]->line
                : $group['block']->instructions[0]->line;
            $cases[] = new SwitchCase(
                $group['block']->start,
                $line,
                $group['labels'],
                $body,
                $group['isDefault'],
                $fallsThrough,
            );
        }

        $leading = [];
        foreach ($this->blockStatements($block) as $statement) {
            if ($statement instanceof OpaqueStatement && $statement->opline === $switch->index) {
                continue;
            }
            $leading[] = $statement;
        }

        return [
            $leading,
            new SwitchStatement($switch->index, $switch->line, $subject, $cases, $fallback, $switchId),
            $end,
        ];
    }

    /**
     * @param list<int> $targets
     */
    private function switchBreakRow(Block $block, Instruction $switch, array $targets, int $defaultTarget): BreakContinue
    {
        $breaks = $this->analysis->breaks;
        if ($breaks === null) {
            $this->fail($block, $switch, 'switch breaks table is missing.');
        }

        $best = null;
        foreach ($breaks as $row) {
            if (!$row->switch || $row->breakTarget <= $switch->index) {
                continue;
            }
            if ($defaultTarget < $switch->index || $defaultTarget > $row->breakTarget) {
                continue;
            }
            $covers = true;
            foreach ($targets as $target) {
                if ($target <= $switch->index || $target >= $row->breakTarget) {
                    $covers = false;
                    break;
                }
            }
            if (!$covers) {
                continue;
            }
            if ($best === null || $row->breakTarget < $best->breakTarget) {
                $best = $row;
            }
        }
        if (!$best instanceof BreakContinue) {
            $this->fail($block, $switch, 'switch break target is missing.');
        }

        return $best;
    }

    private function switchEnd(Block $block): ?Block
    {
        try {
            $switch = $this->last($block);
            if (!$this->keepsSwitchOpcode($switch)) {
                return null;
            }
            $targets = [];
            foreach ($block->successors as $edge) {
                if ($edge->kind !== 'switch') {
                    continue;
                }
                $targets[] = $this->block($edge->to)->start;
            }
            $default = $this->block($this->soleEdge($block, $switch, 'default')->to);
            $row = $this->switchBreakRow($block, $switch, $targets, $default->start);

            return $this->graph->blockStartingAt($row->breakTarget);
        } catch (UnstructuredFlow) {
            return null;
        }
    }

    private function switchSubject(Block $block, Instruction $switch): Expression
    {
        foreach ($this->blockStatements($block) as $statement) {
            if (!$statement instanceof OpaqueStatement || $statement->opline !== $switch->index) {
                continue;
            }
            $resolved = $statement->instruction->op1->resolved;
            if ($resolved instanceof Expression && !$resolved instanceof Unresolved) {
                return $resolved;
            }
            $this->fail($block, $switch, 'switch subject expression is missing.');
        }

        $this->fail($block, $switch, 'switch subject expression is missing.');
    }

    /**
     * @param list<Edge> $table
     * @return list<array{literal: Literal, target: Block, opline: int, line: int}>
     */
    private function fallbackArms(
        Block $block,
        Instruction $switch,
        Expression $subject,
        array $table,
        Block $defaultBlock,
    ): array {
        $cursor = $this->block($this->soleEdge($block, $switch, 'fallthrough')->to);
        $arms = [];
        $seen = [];
        foreach ($table as $edge) {
            if (isset($seen[$cursor->id])) {
                $this->fail($cursor, $this->last($cursor), 'switch type fallback does not match the jump table.');
            }
            $seen[$cursor->id] = true;
            $jump = $this->last($cursor);
            if ($jump->opcode !== 'JMPNZ') {
                $this->fail($cursor, $jump, 'switch type fallback is not a comparison of the subject.');
            }
            $true = $this->soleEdge($cursor, $jump, 'true');
            $false = $this->soleEdge($cursor, $jump, 'false');
            $target = $this->block($edge->to);
            if ($true->to !== $target->id) {
                $this->fail($cursor, $jump, 'switch type fallback does not match the jump table.');
            }
            $literal = $this->fallbackLiteral($cursor, $jump, $switch, $subject);
            if (!$this->sameLabel($literal, $edge->case, $switch->opcode)) {
                $this->fail($cursor, $jump, 'switch type fallback does not match the jump table.');
            }
            $this->visited[$cursor->id] = true;
            $this->structuredOplines[] = $jump->index;
            $this->structuredOplines[] = $literal->opline;
            $arms[] = [
                'literal' => $literal,
                'target' => $target,
                'opline' => $literal->opline,
                'line' => $literal->line,
            ];
            $cursor = $this->block($false->to);
        }

        $jump = $this->last($cursor);
        if ($jump->opcode !== 'JMP' || count($cursor->successors) !== 1 || $cursor->successors[0]->kind !== 'jump') {
            $this->fail($cursor, $jump, 'switch type fallback does not match the jump table.');
        }
        if ($cursor->successors[0]->to !== $defaultBlock->id) {
            $this->fail($cursor, $jump, 'switch type fallback does not match the jump table.');
        }
        $this->visited[$cursor->id] = true;
        $this->structuredOplines[] = $jump->index;

        return $arms;
    }

    private function fallbackLiteral(Block $block, Instruction $jump, Instruction $switch, Expression $subject): Literal
    {
        $comparison = null;
        foreach ($this->blockStatements($block) as $statement) {
            if ($statement instanceof OpaqueStatement && $statement->opline === $jump->index) {
                $comparison = $statement->instruction->op1->resolved;
                break;
            }
        }
        if ($comparison instanceof Binary && $comparison->operator === '==') {
            if ($comparison->right instanceof Literal && $this->sameSubject($subject, $switch, $comparison->left)) {
                return $comparison->right;
            }
            $this->fail($block, $jump, 'switch type fallback is not a comparison of the subject.');
        }
        if ($comparison instanceof Opaque && $comparison->opcode === 'CASE') {
            $value = $comparison->op2->resolved;
            $operand = $comparison->op1->resolved;
            if (
                $value instanceof Literal
                && $operand instanceof Expression
                && $this->sameSubject($subject, $switch, $operand)
            ) {
                return $value;
            }
            $this->fail($block, $jump, 'switch type fallback is not a comparison of the subject.');
        }

        $this->fail($block, $jump, 'switch type fallback is not a comparison of the subject.');
    }

    private function sameSubject(Expression $subject, Instruction $switch, Expression $operand): bool
    {
        if ($subject instanceof VariableRead && $operand instanceof VariableRead) {
            return $operand->name === $subject->name && $operand->index === $subject->index;
        }
        if (
            $operand instanceof Unresolved
            && $switch->op1->index !== null
            && ($switch->op1->kind === 'tmp' || $switch->op1->kind === 'var')
        ) {
            return $operand->kind === $switch->op1->kind && $operand->index === $switch->op1->index;
        }
        if ($subject instanceof Literal && $operand instanceof Literal) {
            return $subject->type === $operand->type && $subject->value === $operand->value;
        }

        return $operand === $subject;
    }

    private function sameLabel(Literal $literal, mixed $case, string $opcode): bool
    {
        if ($opcode === 'SWITCH_LONG') {
            return $literal->type === 'int' && is_int($case) && $literal->value === $case;
        }

        return $opcode === 'SWITCH_STRING'
            && $literal->type === 'string'
            && is_string($case)
            && $literal->value === $case;
    }

    private function hideSubjectFrees(Instruction $switch, int $breakTarget): void
    {
        if (
            ($switch->op1->kind !== 'tmp' && $switch->op1->kind !== 'var')
            || $switch->op1->index === null
        ) {
            return;
        }
        foreach ($this->graph->blocks as $block) {
            foreach ($block->instructions as $instruction) {
                if ($instruction->opcode !== 'FREE' || $instruction->index <= $switch->index || $instruction->index > $breakTarget) {
                    continue;
                }
                if (!$this->sameSlot($instruction->op1, $switch->op1->kind, $switch->op1->index)) {
                    continue;
                }
                if (($instruction->extendedValue & 3) === 0) {
                    continue;
                }
                $this->hiddenOplines[$instruction->index] = true;
                $this->structuredOplines[] = $instruction->index;
            }
        }
    }

    private function soleEdge(Block $block, Instruction $instruction, string $kind): Edge
    {
        $found = null;
        foreach ($block->successors as $edge) {
            if ($edge->kind !== $kind) {
                continue;
            }
            if ($found instanceof Edge) {
                $this->fail($block, $instruction, 'switch has more than one ' . $kind . ' edge.');
            }
            $found = $edge;
        }
        if (!$found instanceof Edge) {
            $this->fail($block, $instruction, 'switch has no ' . $kind . ' edge.');
        }

        return $found;
    }

    /**
     * @param list<string> $stops
     * @return list<Statement>
     */
    private function emitSwitchFallback(Block $switchBlock, Block $end, array $stops): array
    {
        $statements = [];
        foreach ($this->graph->blocks as $block) {
            if ($block->start < $switchBlock->start || $block->start >= $end->start) {
                continue;
            }
            if (isset($this->visited[$block->id])) {
                continue;
            }
            $this->visited[$block->id] = true;
            $last = $this->last($block);
            if ($last->opcode === 'BRK' || $last->opcode === 'CONT') {
                foreach ($this->controlStatements($block, $last) as $statement) {
                    $statements[] = $statement;
                }
                continue;
            }
            foreach ($this->blockStatements($block) as $statement) {
                $statements[] = $statement;
            }
        }

        return $this->appendContinuation($statements, $end, $stops);
    }

    private function resolvedBreak(Instruction $instruction): ?BreakContinue
    {
        $breaks = $this->analysis->breaks;
        if ($breaks === null || $instruction->op1->kind !== 'level' || !is_int($instruction->op1->value)) {
            return null;
        }
        if (!is_int($instruction->op2->value) || $instruction->op2->value < 1) {
            return null;
        }

        $slot = $instruction->op1->value;
        for ($remaining = $instruction->op2->value; $remaining > 1; $remaining--) {
            $row = $breaks[$slot] ?? null;
            if (!$row instanceof BreakContinue || $row->parent < 0) {
                return null;
            }
            $slot = $row->parent;
        }

        return $breaks[$slot] ?? null;
    }

    private function bodyEdge(Block $header, NaturalLoop $loop): Edge
    {
        $staying = [];
        foreach ($header->successors as $edge) {
            if ($loop->contains($edge->to)) {
                $staying[] = $edge;
            }
        }
        if (count($staying) !== 1) {
            $this->fail($header, $this->last($header), 'while body edge is ambiguous.');
        }

        return $staying[0];
    }

    private function exitEdge(Block $condition, NaturalLoop $loop): Edge
    {
        $leaving = [];
        foreach ($condition->successors as $edge) {
            if (!$loop->contains($edge->to)) {
                $leaving[] = $edge;
            }
        }
        if (count($leaving) !== 1) {
            $this->fail($condition, $this->last($condition), 'loop has no single exit from its condition.');
        }

        return $leaving[0];
    }

    /**
     * @return array{Block, Edge}
     */
    private function latch(NaturalLoop $loop): array
    {
        $found = null;
        $kind = null;
        foreach ($loop->backEdges as $edge) {
            if ($edge->kind !== 'true' && $edge->kind !== 'false') {
                continue;
            }
            $source = $this->block($edge->from);
            $opcode = $this->last($source)->opcode;
            if ($opcode !== 'JMPZ' && $opcode !== 'JMPNZ') {
                continue;
            }
            if ($found instanceof Block) {
                $this->fail($source, $this->last($source), 'do-while has more than one condition back edge.');
            }
            $found = $source;
            $kind = $edge->kind;
        }
        if (!$found instanceof Block || $kind === null) {
            $header = $this->block($loop->headerId);
            $this->fail($header, $this->last($header), 'do-while has no condition back edge.');
        }

        $back = null;
        foreach ($found->successors as $edge) {
            if ($edge->kind === $kind && $edge->to === $loop->headerId) {
                $back = $edge;
                break;
            }
        }
        if (!$back instanceof Edge) {
            $this->fail($found, $this->last($found), 'do-while condition back edge is missing.');
        }

        return [$found, $back];
    }

    private function continuationOf(NaturalLoop $loop): ?Block
    {
        $header = $this->block($loop->headerId);
        $fromHeader = [];
        foreach ($header->successors as $edge) {
            if (!$loop->contains($edge->to)) {
                $fromHeader[$edge->to] = $this->block($edge->to);
            }
        }
        if (count($fromHeader) === 1) {
            return array_values($fromHeader)[0];
        }

        $targets = [];
        foreach ($loop->exits as $edge) {
            $targets[$edge->to] = $this->block($edge->to);
        }
        if (count($targets) === 1) {
            return array_values($targets)[0];
        }

        return null;
    }

    private function loopCondition(Block $block, Instruction $branch, string $edgeKind): Expression
    {
        $resolved = $this->branchCondition($block, $branch);
        if ($edgeKind === 'true') {
            return $resolved;
        }
        if ($edgeKind === 'false') {
            return new Unary($resolved->opline, $resolved->line, '!', $resolved);
        }
        $this->fail($block, $branch, 'loop condition edge is ' . $edgeKind . '.');
    }

    private function assertVisited(NaturalLoop $loop): void
    {
        foreach ($loop->blocks as $id) {
            if (!isset($this->visited[$id])) {
                $block = $this->block($id);
                $this->fail($block, $this->last($block), 'loop body did not include every block.');
            }
        }
    }

    /**
     * @return array{IfStatement, Block}
     */
    private function buildConditional(Block $header, Instruction $branch): array
    {
        if ($header->exceptions !== []) {
            $this->fail($header, $branch, 'The conditional region contains an exception edge.');
        }

        $true = $this->block($this->edge($header, $branch, 'true')->to);
        $false = $this->block($this->edge($header, $branch, 'false')->to);
        if ($true->start <= $header->start || $false->start <= $header->start) {
            $this->fail($header, $branch, 'The branch target is not a forward edge.');
        }

        $condition = $this->branchCondition($header, $branch);
        if ($true->id === $false->id) {
            return [$this->conditional($branch, [new ConditionalBranch($branch->index, $branch->line, $condition, [])], null), $true];
        }

        $ipdom = $this->immediatePostDominator($header->id);
        if ($ipdom === null) {
            $this->fail($header, $branch, 'The conditional merge point is ambiguous.');
        }
        if ($ipdom === $false->id) {
            $then = $this->region($true, $false, $header);

            return [$this->conditional($branch, [new ConditionalBranch($branch->index, $branch->line, $condition, $then)], null), $false];
        }
        if ($ipdom === $true->id) {
            $else = $this->region($false, $true, $header);

            return [$this->withElse($branch, [new ConditionalBranch($branch->index, $branch->line, $condition, [])], $else, $false), $true];
        }
        if ($ipdom !== self::EXIT) {
            $merge = $this->block($ipdom);
            $then = $this->region($true, $merge, $header);
            $else = $this->region($false, $merge, $header);

            return [$this->withElse($branch, [new ConditionalBranch($branch->index, $branch->line, $condition, $then)], $else, $false), $merge];
        }

        $join = $this->deadJoin($header, $branch, $true, $false);
        if ($join !== null) {
            $then = $this->region($true, $join, $header);
            $else = $this->region($false, $join, $header);

            return [$this->withElse($branch, [new ConditionalBranch($branch->index, $branch->line, $condition, $then)], $else, $false), $join];
        }

        $sink = $this->unresolvedSink($true);
        if ($this->activeHeader !== null && $sink === $this->activeHeader) {
            $merge = $this->block($this->activeHeader);
            $then = $this->region($true, $merge, $header);
            $else = $this->region($false, $merge, $header);

            return [$this->withElse($branch, [new ConditionalBranch($branch->index, $branch->line, $condition, $then)], $else, $false), $merge];
        }

        $then = $this->region($true, $false, $header);

        return [$this->conditional($branch, [new ConditionalBranch($branch->index, $branch->line, $condition, $then)], null), $false];
    }

    /**
     * @param list<ConditionalBranch> $branches
     * @param list<Statement> $elseStatements
     */
    private function withElse(Instruction $branch, array $branches, array $elseStatements, Block $elseEntry): IfStatement
    {
        $first = $elseEntry->instructions[0];

        return $this->flatten(new IfStatement(
            $branch->index,
            $branch->line,
            $branches,
            $elseStatements,
            $first->index,
            $first->line,
        ));
    }

    /**
     * @param list<ConditionalBranch> $branches
     * @param list<Statement>|null $elseStatements
     */
    private function conditional(Instruction $branch, array $branches, ?array $elseStatements): IfStatement
    {
        return new IfStatement($branch->index, $branch->line, $branches, $elseStatements, null, null);
    }

    private function flatten(IfStatement $statement): IfStatement
    {
        $else = $statement->elseStatements;
        if ($else === null || count($else) !== 1 || !$else[0] instanceof IfStatement) {
            return $statement;
        }

        $inner = $this->flatten($else[0]);

        return new IfStatement(
            $statement->opline,
            $statement->line,
            array_merge($statement->branches, $inner->branches),
            $inner->elseStatements,
            $inner->elseStatements === null ? null : $inner->elseOpline,
            $inner->elseStatements === null ? null : $inner->elseLine,
        );
    }

    /**
     * @return list<Statement>
     */
    private function region(Block $entry, Block $stop, Block $header): array
    {
        $this->assertRegion($entry, $stop, $header);

        return $this->emit($entry, [$stop->id]);
    }

    private function assertRegion(Block $entry, Block $stop, Block $header): void
    {
        $terminator = $this->last($entry)->opcode;
        $leaves = in_array($terminator, ['BRK', 'CONT', 'RETURN', 'THROW'], true);
        if (
            $stop->start <= $entry->start
            && $stop->id !== $entry->id
            && !$leaves
            && !isset($this->boundary[$stop->id])
        ) {
            $this->fail($entry, $this->last($entry), 'The branch target is not a forward edge.');
        }

        $region = [];
        $queue = [$entry];
        while ($queue !== []) {
            $block = array_shift($queue);
            if (!$block instanceof Block || $block->id === $stop->id || isset($region[$block->id]) || isset($this->boundary[$block->id])) {
                continue;
            }
            $region[$block->id] = $block;
            if ($block->exceptions !== []) {
                $this->fail($block, $this->last($block), 'The conditional region contains an exception edge.');
            }
            $this->refuse($block);
            foreach ($block->successors as $edge) {
                $next = $this->block($edge->to);
                if ($next->id === $stop->id || isset($this->boundary[$next->id]) || $next->id === $this->updateBlockId) {
                    continue;
                }
                if ($next->start <= $entry->start) {
                    $this->fail($block, $this->last($block), 'The branch target is not a forward edge.');
                }
                $queue[] = $next;
            }
        }

        foreach ($region as $block) {
            foreach ($block->predecessors as $predecessor) {
                if ($predecessor === $header->id || isset($region[$predecessor]) || isset($this->boundary[$predecessor])) {
                    continue;
                }
                $this->fail($block, $this->last($block), 'The branch is entered from outside the conditional.');
            }
            foreach ($block->successors as $edge) {
                if (
                    $edge->to === $stop->id
                    || isset($region[$edge->to])
                    || isset($this->boundary[$edge->to])
                    || $edge->to === $this->updateBlockId
                ) {
                    continue;
                }
                $this->fail($block, $this->last($block), 'The branch leaves the conditional without reaching the merge point.');
            }
        }
    }

    private function deadJoin(Block $header, Instruction $branch, Block $true, Block $false): ?Block
    {
        $targets = [];
        $seen = [];
        $queue = [$true];
        while ($queue !== []) {
            $block = array_shift($queue);
            if (!$block instanceof Block || isset($seen[$block->id])) {
                continue;
            }
            $seen[$block->id] = true;
            if ($block->successors === [] || $this->onlyBoundary($block)) {
                $marker = $this->byStart[$block->end] ?? null;
                if (
                    $marker !== null
                    && $marker->predecessors === []
                    && count($marker->instructions) === 1
                    && $marker->instructions[0]->opcode === 'JMP'
                    && count($marker->successors) === 1
                    && $marker->successors[0]->kind === 'jump'
                ) {
                    $target = $this->block($marker->successors[0]->to);
                    if ($target->start > $false->start && $target->id !== $false->id) {
                        $targets[$target->id] = $target;
                    }
                }
                continue;
            }
            foreach ($block->successors as $edge) {
                if (isset($this->boundary[$edge->to])) {
                    continue;
                }
                $queue[] = $this->block($edge->to);
            }
        }

        if (count($targets) > 1) {
            $this->fail($header, $branch, 'The conditional merge point is ambiguous.');
        }

        return count($targets) === 1 ? array_values($targets)[0] : null;
    }

    private function onlyBoundary(Block $block): bool
    {
        if ($block->successors === []) {
            return false;
        }
        foreach ($block->successors as $edge) {
            if (!isset($this->boundary[$edge->to])) {
                return false;
            }
        }

        return true;
    }

    /**
     * A break or continue that stays inside the loop, such as a switch break
     * whose target is the loop header. The other arm is the rest of the body.
     */
    private function unresolvedSink(Block $block): ?string
    {
        $last = $this->last($block);
        if ($last->opcode !== 'BRK' && $last->opcode !== 'CONT') {
            return null;
        }
        $control = $this->controls[$last->index] ?? null;
        if (!$control instanceof LoopControl || $control->loopId !== null || count($block->successors) !== 1) {
            return null;
        }
        $target = $block->successors[0]->to;

        return isset($this->boundary[$target]) ? $target : null;
    }

    private function branchCondition(Block $header, Instruction $branch): Expression
    {
        foreach ($this->blockStatements($header) as $statement) {
            if (!$statement instanceof OpaqueStatement || $statement->opline !== $branch->index) {
                continue;
            }
            if ($statement->instruction->opcode !== $branch->opcode) {
                $this->fail($header, $branch, 'The branch condition is missing.');
            }
            $resolved = $statement->instruction->op1->resolved;
            if (!$resolved instanceof Expression || $resolved instanceof Unresolved) {
                $this->fail($header, $branch, 'The branch condition is missing.');
            }
            $this->structuredOplines[] = $branch->index;

            return $resolved;
        }

        $this->fail($header, $branch, 'The branch condition is missing.');
    }

    /**
     * @return list<Statement>
     */
    private function controlStatements(Block $block, Instruction $instruction): array
    {
        $statements = $this->statementsBefore($block, $instruction->index);
        $row = $this->resolvedBreak($instruction);
        if ($row instanceof BreakContinue && $row->switch) {
            $statements[] = $this->switchControl($block, $instruction, $row);

            return $statements;
        }

        $control = $this->controls[$instruction->index] ?? null;
        if (!$control instanceof LoopControl || $control->opcode !== $instruction->opcode || $control->loopId === null) {
            $this->diagnostics[] = new Diagnostic(
                'unresolved',
                $block->id,
                $instruction->index,
                $instruction->line,
                $instruction->opcode,
                $instruction->opcode . ' does not resolve to a loop.',
            );
            $statements[] = new OpaqueStatement($instruction->index, $instruction->line, $this->opaque($instruction));

            return $statements;
        }

        $statements[] = $instruction->opcode === 'BRK'
            ? new BreakStatement($instruction->index, $instruction->line, $control->level, $control->loopId)
            : new ContinueStatement($instruction->index, $instruction->line, $control->level, $control->loopId);

        return $statements;
    }

    /**
     * A breaks[] row with the switch flag is a switch break or continue.
     * LoopControl may still name a loop when the switch ends on that loop's
     * continue block. The row decides, and an unregistered switch stays opaque.
     */
    private function switchControl(Block $block, Instruction $instruction, BreakContinue $row): Statement
    {
        $target = $instruction->opcode === 'BRK' ? $row->breakTarget : $row->continueTarget;
        $switchId = $this->switchBreakTargets[$row->breakTarget] ?? null;
        $control = $this->controls[$instruction->index] ?? null;
        if (
            is_string($switchId)
            && $control instanceof LoopControl
            && $control->opcode === $instruction->opcode
            && $control->targetOpline === $target
        ) {
            return $instruction->opcode === 'BRK'
                ? new SwitchBreakStatement($instruction->index, $instruction->line, $control->level, $switchId)
                : new SwitchContinueStatement($instruction->index, $instruction->line, $control->level, $switchId);
        }

        $this->diagnostics[] = new Diagnostic(
            'unresolved',
            $block->id,
            $instruction->index,
            $instruction->line,
            $instruction->opcode,
            $instruction->opcode . ' does not resolve to a loop.',
        );

        return new OpaqueStatement($instruction->index, $instruction->line, $this->opaque($instruction));
    }

    private function opaque(Instruction $instruction): Opaque
    {
        return new Opaque(
            $instruction->index,
            $instruction->line,
            $instruction->opcode,
            $instruction->opcodeNumber,
            $instruction->extendedValue,
            $instruction->jumpTarget,
            $this->operandUse($instruction->op1),
            $this->operandUse($instruction->op2),
            $this->operandUse($instruction->result),
            $instruction->extra,
            $instruction->opcode . ' does not resolve to a loop.',
        );
    }

    private function operandUse(Operand $operand): \Rephper\Expr\OperandUse
    {
        return new \Rephper\Expr\OperandUse(
            $operand->kind,
            $operand->index,
            $operand->name,
            $operand->target,
            $operand->value,
            $operand->id,
            $operand->jumpTable,
            $operand->smartBranch,
            $operand->encoded,
            $operand->typeCode,
            $operand->extra,
            null,
        );
    }

    private function edge(Block $header, Instruction $branch, string $kind): Edge
    {
        $found = null;
        foreach ($header->successors as $edge) {
            if ($edge->kind !== $kind) {
                continue;
            }
            if ($found instanceof Edge) {
                $this->fail($header, $branch, 'The conditional has more than one ' . $kind . ' edge.');
            }
            $found = $edge;
        }
        if (!$found instanceof Edge) {
            $this->fail($header, $branch, 'The conditional has no ' . $kind . ' edge.');
        }

        return $found;
    }

    /**
     * @return list<Statement>
     */
    private function statementsBefore(Block $block, int $branchOpline): array
    {
        $statements = [];
        foreach ($this->blockStatements($block) as $statement) {
            if ($statement->opline === $branchOpline && $statement instanceof OpaqueStatement) {
                continue;
            }
            $statements[] = $statement;
        }

        return $statements;
    }

    /**
     * @return list<Statement>
     */
    private function blockStatements(Block $block): array
    {
        $statements = $this->statementBlocks[$block->id]->statements;
        if ($this->hiddenOplines === []) {
            return $statements;
        }
        $visible = [];
        foreach ($statements as $statement) {
            if (isset($this->hiddenOplines[$statement->opline])) {
                continue;
            }
            $visible[] = $statement;
        }

        return $visible;
    }

    private function block(string $id): Block
    {
        $block = $this->byId[$id] ?? null;
        if (!$block instanceof Block) {
            throw new StatementException('Graph edge targets missing block ' . $id);
        }

        return $block;
    }

    private function last(Block $block): Instruction
    {
        if ($block->instructions === []) {
            $this->failEmpty($block);
        }

        return $block->instructions[array_key_last($block->instructions)];
    }

    private function failEmpty(Block $block): never
    {
        throw new UnstructuredFlow($block->id, $block->start, 0, 'EMPTY', 'The block has no instruction.');
    }

    private function refuse(Block $block): void
    {
        foreach ($block->instructions as $instruction) {
            if ($this->keepsForeachOpcode($block, $instruction) || $this->keepsSwitchOpcode($instruction)) {
                continue;
            }
            $reason = self::REFUSED[$instruction->opcode] ?? null;
            if ($reason !== null) {
                $this->fail($block, $instruction, $reason);
            }
        }
    }

    private function keepsForeachOpcode(Block $block, Instruction $instruction): bool
    {
        if ($instruction->opcode === 'FE_FETCH_R' || $instruction->opcode === 'FE_FETCH_RW') {
            $loop = $this->loopsByHeader[$block->id] ?? null;

            return $loop instanceof NaturalLoop && $loop->kind === NaturalLoop::FOREACH;
        }
        if ($this->isReset($instruction)) {
            return $this->foreachByReset($block) instanceof NaturalLoop;
        }

        return false;
    }

    /**
     * @param list<string> $stops
     */
    private function stopped(string $id, array $stops): bool
    {
        return in_array($id, $stops, true);
    }

    /**
     * @return array{0: array<string, true>, 1: list<int>, 2: list<Diagnostic>, 3: array<int, true>, 4: array<int, string>}
     */
    private function snapshot(): array
    {
        return [$this->visited, $this->structuredOplines, $this->diagnostics, $this->hiddenOplines, $this->switchBreakTargets];
    }

    /**
     * @param array{0: array<string, true>, 1: list<int>, 2: list<Diagnostic>, 3: array<int, true>, 4: array<int, string>} $snapshot
     */
    private function restore(array $snapshot): void
    {
        $this->visited = $snapshot[0];
        $this->structuredOplines = $snapshot[1];
        $this->diagnostics = $snapshot[2];
        $this->hiddenOplines = $snapshot[3];
        $this->switchBreakTargets = $snapshot[4];
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function postDominators(): array
    {
        $ids = [];
        $all = [self::EXIT => true];
        $successors = [];
        foreach ($this->graph->blocks as $block) {
            $ids[] = $block->id;
            $all[$block->id] = true;
            $targets = [];
            foreach ($block->successors as $edge) {
                $targets[] = isset($this->boundary[$edge->to]) ? self::EXIT : $edge->to;
            }
            if ($targets === []) {
                $targets[] = self::EXIT;
            }
            $successors[$block->id] = $targets;
        }

        $post = [self::EXIT => [self::EXIT => true]];
        foreach ($ids as $id) {
            $post[$id] = $all;
        }

        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($ids as $id) {
                $next = [$id => true];
                $intersection = null;
                foreach ($successors[$id] as $target) {
                    $intersection = $intersection === null
                        ? $post[$target]
                        : array_intersect_key($post[$target], $intersection);
                }
                foreach ($intersection ?? [] as $candidate => $_) {
                    $next[$candidate] = true;
                }
                if ($next != $post[$id]) {
                    $post[$id] = $next;
                    $changed = true;
                }
            }
        }

        return $post;
    }

    private function immediatePostDominator(string $id): ?string
    {
        $best = null;
        $bestSize = -1;
        $tied = false;
        foreach ($this->post[$id] as $candidate => $_) {
            if ($candidate === $id) {
                continue;
            }
            $size = count($this->post[$candidate]);
            if ($size > $bestSize) {
                $best = $candidate;
                $bestSize = $size;
                $tied = false;
            } elseif ($size === $bestSize) {
                $tied = true;
            }
        }

        return $tied ? null : $best;
    }

    /**
     * @param list<Statement> $structured
     */
    private function appendMissed(array &$structured): void
    {
        if ($this->appendUnvisited($structured, $this->analysis->dominators->reachable)) {
            $entry = $this->graph->blockStartingAt(0);
            $instruction = $entry instanceof Block && $entry->instructions !== [] ? $this->last($entry) : null;
            $this->diagnostics[] = new Diagnostic(
                'unstructured',
                $entry->id ?? '',
                $instruction->index ?? 0,
                $instruction->line ?? 0,
                $instruction->opcode ?? 'EMPTY',
                'A reachable block was left outside the structured statements.',
            );
        }
        $this->appendUnvisited($structured, $this->analysis->dominators->unreachable);
    }

    /**
     * @param list<Statement> $structured
     * @param list<string> $ids
     */
    private function appendUnvisited(array &$structured, array $ids): bool
    {
        $missed = false;
        foreach ($ids as $id) {
            if (isset($this->visited[$id])) {
                continue;
            }
            $this->visited[$id] = true;
            $statements = $this->blockStatements($this->block($id));
            if ($statements === []) {
                continue;
            }
            $missed = true;
            foreach ($statements as $statement) {
                $structured[] = $statement;
            }
        }

        return $missed;
    }

    /**
     * @return list<Statement>
     */
    private function unvisitedStatements(): array
    {
        $statements = [];
        foreach ($this->analysis->dominators->reachable as $id) {
            if (isset($this->visited[$id])) {
                continue;
            }
            $this->visited[$id] = true;
            foreach ($this->blockStatements($this->block($id)) as $statement) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * @return list<Statement>
     */
    private function allStatements(): array
    {
        $statements = [];
        foreach ($this->graph->blocks as $block) {
            foreach ($this->blockStatements($block) as $statement) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * @return list<Diagnostic>
     */
    private function keptDiagnostics(): array
    {
        $diagnostics = [];
        foreach ($this->diagnostics as $diagnostic) {
            if (
                in_array($diagnostic->opline, $this->structuredOplines, true)
                && $diagnostic->kind === 'unsupported'
            ) {
                continue;
            }
            $diagnostics[] = $diagnostic;
        }

        return $diagnostics;
    }

    private function loopDiagnostic(NaturalLoop $loop, string $reason): Diagnostic
    {
        $header = $this->block($loop->headerId);
        $instruction = $header->instructions === [] ? null : $this->last($header);

        return new Diagnostic(
            'unstructured',
            $header->id,
            $instruction->index ?? $header->start,
            $instruction->line ?? $loop->headerLine,
            $instruction->opcode ?? 'EMPTY',
            $reason,
        );
    }

    private function unsupportedReason(NaturalLoop $loop): string
    {
        return match ($loop->kind) {
            NaturalLoop::FOR => 'for is not reconstructed as a statement.',
            NaturalLoop::FOREACH => 'foreach is not reconstructed as a statement.',
            default => 'loop is not reconstructed as a statement.',
        };
    }

    private function flowDiagnostic(UnstructuredFlow $failure): Diagnostic
    {
        return new Diagnostic(
            'unstructured',
            $failure->blockId,
            $failure->opline,
            $failure->sourceLine,
            $failure->opcode,
            $failure->getMessage(),
        );
    }

    private function fail(Block $block, Instruction $instruction, string $reason): never
    {
        throw new UnstructuredFlow($block->id, $instruction->index, $instruction->line, $instruction->opcode, $reason);
    }
}
