<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Cfg\Block;
use Rephper\Cfg\Edge;
use Rephper\Cfg\Graph;
use Rephper\Expr\Diagnostic;
use Rephper\Expr\Expression;
use Rephper\Expr\Unresolved;
use Rephper\Ir\Instruction;

/**
 * Rebuilds if / elseif / else from a control-flow graph and statement lists.
 *
 * The then side of a branch is the successor whose edge kind is true. JMPZ's
 * true edge falls through and its false edge is the jump. JMPNZ is the other
 * way around. Conditions come from the branch instruction's existing expression.
 * The graph and the statement reconstruction are not modified.
 */
final class ConditionalStructurer
{
    private const EXIT = 'EXIT';

    /**
     * Opcodes that must stay unstructured in this pass.
     *
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
        'BRK' => 'BRK is not structured as a conditional.',
        'CONT' => 'CONT is not structured as a conditional.',
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

    /** @var array<string, Block> */
    private array $byId = [];

    /** @var array<int, Block> */
    private array $byStart = [];

    /** @var array<string, BlockStatements> */
    private array $statementBlocks = [];

    /** @var array<string, true> */
    private array $visited = [];

    /** @var list<int> */
    private array $structuredOplines = [];

    /** @var array<string, array<string, true>> */
    private array $post = [];

    public function structure(Graph $graph, Reconstruction $statements): StructuredStatements
    {
        $this->bind($graph, $statements);
        $entry = $graph->blockStartingAt(0);
        if ($entry === null) {
            return new StructuredStatements([], $statements->diagnostics);
        }

        try {
            return new StructuredStatements($this->emit($entry, null), $this->keptDiagnostics());
        } catch (UnstructuredFlow $failure) {
            return $this->flat($failure);
        }
    }

    private function bind(Graph $graph, Reconstruction $statements): void
    {
        if (count($graph->blocks) !== count($statements->blocks)) {
            throw new StatementException(
                'Statement reconstruction has ' . count($statements->blocks)
                . ' blocks and the graph has ' . count($graph->blocks)
            );
        }

        $this->graph = $graph;
        $this->statements = $statements;
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
        $this->visited = [];
        $this->structuredOplines = [];
        $this->post = $this->postDominators();
    }

    /**
     * @return list<Statement>
     */
    private function emit(Block $block, ?Block $stop): array
    {
        if ($stop !== null && $block->id === $stop->id) {
            return [];
        }
        if (isset($this->visited[$block->id])) {
            $this->fail($block, $this->last($block), 'The block is reached by more than one structured path.');
        }
        $this->visited[$block->id] = true;
        $this->refuse($block);

        $last = $this->last($block);
        if ($last->opcode === 'JMPZ' || $last->opcode === 'JMPNZ') {
            [$conditional, $merge] = $this->build($block, $last);
            $statements = $this->statementsBefore($block, $last->index);
            $statements[] = $conditional;
            if ($stop === null || $merge->id !== $stop->id) {
                foreach ($this->emit($merge, $stop) as $statement) {
                    $statements[] = $statement;
                }
            }

            return $statements;
        }

        $statements = $this->blockStatements($block);
        if (count($block->successors) > 1) {
            $this->fail(
                $block,
                $last,
                $last->opcode . ' has multiple successors and is not structured as a conditional.',
            );
        }
        if (count($block->successors) === 1) {
            $next = $this->block($block->successors[0]->to);
            if ($next->start <= $block->start) {
                $this->fail($block, $last, 'The branch target is not a forward edge.');
            }
            foreach ($this->emit($next, $stop) as $statement) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * @return array{IfStatement, Block}
     */
    private function build(Block $header, Instruction $branch): array
    {
        if ($header->exceptions !== []) {
            $this->fail($header, $branch, 'The conditional region contains an exception edge.');
        }

        $true = $this->block($this->edge($header, $branch, 'true')->to);
        $false = $this->block($this->edge($header, $branch, 'false')->to);
        if ($true->start <= $header->start || $false->start <= $header->start) {
            $this->fail($header, $branch, 'The branch target is not a forward edge.');
        }

        $condition = $this->condition($header, $branch);
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

        return $this->emit($entry, $stop);
    }

    private function assertRegion(Block $entry, Block $stop, Block $header): void
    {
        if ($stop->start <= $entry->start) {
            $this->fail($entry, $this->last($entry), 'The branch target is not a forward edge.');
        }

        $region = [];
        $queue = [$entry];
        while ($queue !== []) {
            $block = array_shift($queue);
            if (!$block instanceof Block || $block->id === $stop->id || isset($region[$block->id])) {
                continue;
            }
            $region[$block->id] = $block;
            if ($block->exceptions !== []) {
                $this->fail($block, $this->last($block), 'The conditional region contains an exception edge.');
            }
            $this->refuse($block);
            foreach ($block->successors as $edge) {
                $next = $this->block($edge->to);
                if ($next->id === $stop->id) {
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
                if ($predecessor === $header->id || isset($region[$predecessor])) {
                    continue;
                }
                $this->fail($block, $this->last($block), 'The branch is entered from outside the conditional.');
            }
            foreach ($block->successors as $edge) {
                if ($edge->to === $stop->id || isset($region[$edge->to])) {
                    continue;
                }
                $this->fail($block, $this->last($block), 'The branch leaves the conditional without reaching the merge point.');
            }
        }
    }

    /**
     * An unreachable JMP immediately after a terminating true branch is the
     * compiler's marker for an explicit else whose body does not fall through.
     */
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
            if ($block->successors === []) {
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
                $queue[] = $this->block($edge->to);
            }
        }

        if (count($targets) > 1) {
            $this->fail($header, $branch, 'The conditional merge point is ambiguous.');
        }

        return count($targets) === 1 ? array_values($targets)[0] : null;
    }

    private function condition(Block $header, Instruction $branch): Expression
    {
        foreach ($this->blockStatements($header) as $statement) {
            if (!$statement instanceof OpaqueStatement || $statement->opline !== $branch->index) {
                continue;
            }
            if ($statement->instruction->opcode !== $branch->opcode) {
                $this->fail($header, $branch, 'The branch condition is missing.');
            }
            $resolved = $statement->instruction->op1->resolved;
            if (!$resolved instanceof Expression) {
                $this->fail($header, $branch, 'The branch condition is missing.');
            }
            if ($resolved instanceof Unresolved) {
                $this->fail($header, $branch, 'The branch condition is not defined in this block.');
            }
            $this->structuredOplines[] = $branch->index;

            return $resolved;
        }

        $this->fail($header, $branch, 'The branch condition is missing.');
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
        return $block->instructions[array_key_last($block->instructions)];
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
                $targets[] = $edge->to;
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
     * @return list<Diagnostic>
     */
    private function keptDiagnostics(): array
    {
        $diagnostics = [];
        foreach ($this->statements->diagnostics as $diagnostic) {
            if (
                in_array($diagnostic->opcode, ['JMPZ', 'JMPNZ'], true)
                && in_array($diagnostic->opline, $this->structuredOplines, true)
            ) {
                continue;
            }
            $diagnostics[] = $diagnostic;
        }

        return $diagnostics;
    }

    private function flat(UnstructuredFlow $failure): StructuredStatements
    {
        $statements = [];
        foreach ($this->graph->blocks as $block) {
            foreach ($this->blockStatements($block) as $statement) {
                $statements[] = $statement;
            }
        }
        $diagnostics = $this->statements->diagnostics;
        $diagnostics[] = new Diagnostic(
            'unstructured',
            $failure->blockId,
            $failure->opline,
            $failure->sourceLine,
            $failure->opcode,
            $failure->getMessage(),
        );

        return new StructuredStatements($statements, $diagnostics);
    }

    private function fail(Block $block, Instruction $instruction, string $reason): never
    {
        throw new UnstructuredFlow($block->id, $instruction->index, $instruction->line, $instruction->opcode, $reason);
    }
}
