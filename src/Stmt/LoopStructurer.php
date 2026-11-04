<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Cfg\Block;
use Rephper\Cfg\Edge;
use Rephper\Cfg\Graph;
use Rephper\Cfg\LoopAnalysis;
use Rephper\Cfg\LoopControl;
use Rephper\Cfg\NaturalLoop;
use Rephper\Expr\Diagnostic;
use Rephper\Expr\Expression;
use Rephper\Expr\Opaque;
use Rephper\Expr\Unary;
use Rephper\Expr\Unresolved;
use Rephper\Ir\Instruction;
use Rephper\Ir\Operand;

/**
 * Rebuilds while and do-while loops from a CFG, natural-loop analysis, and
 * statement lists.
 *
 * Expressions and straight-line statements are reused. for, foreach, and
 * unknown loops stay in their original statements with a diagnostic. The
 * graph and the per-block reconstruction are not modified.
 *
 * ConditionalStructurer::structure() is unchanged and still refuses a loop.
 * This walk structures an if inside a supported loop, and a supported loop
 * inside an if, by stopping at the loop header and at the block after the loop.
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

    private int $loopDepth = 0;

    /**
     * @var list<array{
     *     boundary: array<string, true>,
     *     post: array<string, array<string, true>>,
     *     active: ?string,
     *     latch: ?string,
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
        $this->originalDiagnostics = $statements->diagnostics;
        $this->diagnostics = $statements->diagnostics;
        $this->boundary = [];
        $this->activeHeader = null;
        $this->latchId = null;
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

        $this->refuse($block);
        $last = $this->last($block);
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
            $this->fail($header, $branch, 'while condition block contains a statement before the branch.');
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
        $this->loopDepth = $frame['depth'];
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
                if ($next->id === $stop->id || isset($this->boundary[$next->id])) {
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
                if ($edge->to === $stop->id || isset($region[$edge->to]) || isset($this->boundary[$edge->to])) {
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
        return $this->statementBlocks[$block->id]->statements;
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
            $reason = self::REFUSED[$instruction->opcode] ?? null;
            if ($reason !== null) {
                $this->fail($block, $instruction, $reason);
            }
        }
    }

    /**
     * @param list<string> $stops
     */
    private function stopped(string $id, array $stops): bool
    {
        return in_array($id, $stops, true);
    }

    /**
     * @return array{0: array<string, true>, 1: list<int>, 2: list<Diagnostic>}
     */
    private function snapshot(): array
    {
        return [$this->visited, $this->structuredOplines, $this->diagnostics];
    }

    /**
     * @param array{0: array<string, true>, 1: list<int>, 2: list<Diagnostic>} $snapshot
     */
    private function restore(array $snapshot): void
    {
        $this->visited = $snapshot[0];
        $this->structuredOplines = $snapshot[1];
        $this->diagnostics = $snapshot[2];
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
                in_array($diagnostic->opcode, ['JMPZ', 'JMPNZ'], true)
                && in_array($diagnostic->opline, $this->structuredOplines, true)
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
