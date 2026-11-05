<?php

declare(strict_types=1);

namespace Rephper\Expr;

use Rephper\Cfg\Block;
use Rephper\Cfg\Edge;
use Rephper\Cfg\Graph;
use Rephper\Ir\Instruction;
use Rephper\Ir\OpArray;

/**
 * Rebuilds a PHP 8.4 jump-table match from the CFG and the per-block expressions.
 *
 * Each arm writes one shared temporary and jumps to the same merge. This pass
 * attaches those arm values to one MatchExpression at the single use of that
 * temporary. A match it cannot prove is left as the original instructions.
 */
final class MatchReconstructor
{
    /** @var list<string> */
    private const SEND = ['SEND_VAL', 'SEND_VAR', 'SEND_VAL_EX', 'SEND_VAR_EX'];

    /** @var array<string, BlockExpressions> */
    private array $blocks = [];

    /** @var array<string, list<Expression>> */
    private array $roots = [];

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    /** @var array<string, Block> */
    private array $byId = [];

    /** @var array<int, Block> */
    private array $merges = [];

    /** @var array<string, MatchExpression> */
    private array $completed = [];

    /** @var array<string, true> */
    private array $dirty = [];

    /** @var array<int, Expression> */
    private array $memo = [];

    private OpArray $opArray;

    private Graph $graph;

    public function reconstruct(OpArray $opArray, Graph $graph, Reconstruction $reconstruction): Reconstruction
    {
        $matches = [];
        foreach ($opArray->instructions as $instruction) {
            if ($instruction->opcode === 'MATCH') {
                $matches[] = $instruction;
            }
        }
        if ($matches === []) {
            return $reconstruction;
        }

        usort($matches, static fn (Instruction $left, Instruction $right): int => $right->index <=> $left->index);

        $this->opArray = $opArray;
        $this->graph = $graph;
        $this->blocks = [];
        $this->roots = [];
        $this->dirty = [];
        $this->merges = [];
        $this->completed = [];
        $this->byId = [];
        foreach ($graph->blocks as $block) {
            $this->byId[$block->id] = $block;
        }
        foreach ($reconstruction->blocks as $block) {
            $this->blocks[$block->blockId] = $block;
            $this->roots[$block->blockId] = $block->expressions;
        }
        $this->diagnostics = $reconstruction->diagnostics;

        foreach ($matches as $instruction) {
            try {
                $this->commit($this->plan($instruction));
            } catch (MatchFallback $failure) {
                $block = $graph->blockContaining($instruction->index);
                $this->diagnostics[] = new Diagnostic(
                    'unstructured',
                    $block->id ?? '',
                    $instruction->index,
                    $instruction->line,
                    'MATCH',
                    $failure->getMessage(),
                );
            }
        }

        $blocks = [];
        foreach ($reconstruction->blocks as $block) {
            if (!isset($this->dirty[$block->blockId])) {
                $blocks[] = $block;
                continue;
            }
            $blocks[] = new BlockExpressions(
                $block->blockId,
                $block->start,
                $block->end,
                $this->roots[$block->blockId],
            );
        }

        return new Reconstruction($blocks, $this->diagnostics);
    }

    /**
     * @return array{
     *     expression: MatchExpression,
     *     kind: string,
     *     index: int,
     *     merge: Block,
     *     roots: array<int, true>,
     *     diagnostics: array<int, true>,
     *     use: Unresolved,
     *     reads: int,
     *     stitch: bool,
     *     init: Opaque|null,
     *     send: Opaque|null,
     *     execute: Instruction|null,
     *     executeOpaque: Opaque|null,
     *     checks: bool
     * }
     */
    private function plan(Instruction $instruction): array
    {
        $matchBlock = $this->graph->blockContaining($instruction->index);
        if (!$matchBlock instanceof Block) {
            $this->fail('match subject expression is missing.');
        }
        $opaque = $this->matchOpaque($matchBlock, $instruction);
        $subject = $opaque->op1->resolved;
        if (!$subject instanceof Expression || $subject instanceof Unresolved) {
            $this->fail('match subject expression is missing.');
        }

        $switches = [];
        $defaultEdge = null;
        foreach ($matchBlock->successors as $edge) {
            if ($edge->kind === 'switch') {
                if (!is_int($edge->case) && !is_string($edge->case)) {
                    $this->fail('match condition is missing.');
                }
                $switches[] = $edge;
                continue;
            }
            if ($edge->kind === 'default') {
                if ($defaultEdge instanceof Edge) {
                    $this->fail('match default edge is missing.');
                }
                $defaultEdge = $edge;
            }
        }
        if ($switches === []) {
            $this->fail('match jump table is missing.');
        }
        if (!$defaultEdge instanceof Edge) {
            $this->fail('match default edge is missing.');
        }
        $defaultBlock = $this->block($defaultEdge->to);
        if ($defaultBlock->start !== $instruction->extendedValue) {
            $this->fail('match default edge is missing.');
        }

        $error = $this->unhandled($defaultBlock, $instruction);
        $exits = [];
        foreach ($switches as $edge) {
            $exits[] = $this->armExit($this->block($edge->to), $instruction->index, $edge->case, false);
        }
        if (!$error instanceof Instruction) {
            $exits[] = $this->armExit($defaultBlock, $instruction->index, null, true);
        }
        if ($exits === []) {
            $this->fail('match jump table is missing.');
        }

        $merge = $exits[0]['merge'];
        $slot = $this->writtenSlot($exits[0]['qm']);
        if ($slot === null) {
            $this->fail('match arm result is incomplete.');
        }
        foreach ($exits as $exit) {
            if ($exit['merge']->id !== $merge->id || $this->writtenSlot($exit['qm']) !== $slot) {
                $this->fail('match arms do not share a result.');
            }
            $this->assertArmBlock($exit['block'], $exit['qm']);
        }

        $arms = $this->arms($exits, $error instanceof Instruction);
        $this->assertNoExtraArm($instruction, $merge, $slot, $exits);
        $uses = $this->uses($slot['kind'], $slot['index']);
        $value = $this->valueUse($uses);
        if (!$value instanceof Unresolved) {
            $this->fail('match result is not used.');
        }
        $use = ['unresolved' => $value, 'root' => $this->valueRoot($uses, $value)];

        $consumed = [$instruction->index => true];
        if ($error instanceof Instruction) {
            $consumed[$error->index] = true;
        }
        foreach ($exits as $exit) {
            $consumed[$exit['qm']->opline] = true;
            $consumed[$exit['jump']->index] = true;
        }
        $consumed += $this->subjectFree($merge, $instruction);

        $stitch = false;
        $init = null;
        $send = null;
        $execute = null;
        $executeOpaque = null;
        $checks = false;
        if ($this->directSend($use['root'], $use['unresolved'])) {
            $send = $use['root'];
            $stitched = $this->stitch($matchBlock, $instruction, $merge, $send);
            $stitch = true;
            $init = $stitched['init'];
            $execute = $stitched['execute'];
            $executeOpaque = $stitched['opaque'];
            $checks = $stitched['checks'];
            $consumed[$init->opline] = true;
            $consumed[$use['root']->opline] = true;
            if ($stitched['check'] instanceof Instruction) {
                $consumed[$stitched['check']->index] = true;
            }
            $consumed[$execute->index] = true;
        } elseif ($use['root'] instanceof Opaque && str_starts_with($use['root']->opcode, 'SEND_')) {
            $this->fail('match result call is incomplete.');
        }

        return [
            'expression' => new MatchExpression(
                $instruction->index,
                $instruction->line,
                $subject,
                $arms,
                $error instanceof Instruction ? $error->index : null,
                $instruction->extendedValue,
            ),
            'kind' => $slot['kind'],
            'index' => $slot['index'],
            'merge' => $merge,
            'roots' => $consumed,
            'diagnostics' => $consumed,
            'use' => $use['unresolved'],
            'reads' => count($uses),
            'stitch' => $stitch,
            'init' => $init,
            'send' => $send,
            'execute' => $execute,
            'executeOpaque' => $executeOpaque,
            'checks' => $checks,
        ];
    }

    /**
     * @param array{
     *     expression: MatchExpression,
     *     kind: string,
     *     index: int,
     *     merge: Block,
     *     roots: array<int, true>,
     *     diagnostics: array<int, true>,
     *     use: Unresolved,
     *     reads: int,
     *     stitch: bool,
     *     init: Opaque|null,
     *     send: Opaque|null,
     *     execute: Instruction|null,
     *     executeOpaque: Opaque|null,
     *     checks: bool
     * } $plan
     */
    private function commit(array $plan): void
    {
        $updated = $this->roots;
        $keep = null;
        if ($plan['stitch']) {
            $init = $plan['init'];
            $send = $plan['send'];
            $execute = $plan['execute'];
            $executeOpaque = $plan['executeOpaque'];
            if (
                !$init instanceof Opaque
                || !$send instanceof Opaque
                || !$execute instanceof Instruction
                || !$executeOpaque instanceof Opaque
            ) {
                $this->fail('match result call is incomplete.');
            }
            $call = $this->functionCall($plan['expression'], $init, $send, $execute, $plan['checks']);
            $count = 0;
            $this->memo = [];
            foreach ($updated as $blockId => $roots) {
                foreach ($roots as $index => $root) {
                    $updated[$blockId][$index] = $this->replaceObject($root, $executeOpaque, $call, $count);
                }
            }
            if ($count !== 1) {
                $this->fail('match result call is incomplete.');
            }
            $keep = $call;
        } else {
            $count = 0;
            $this->memo = [];
            foreach ($updated as $blockId => $roots) {
                foreach ($roots as $index => $root) {
                    $updated[$blockId][$index] = $this->replaceSlot(
                        $root,
                        $plan['kind'],
                        $plan['index'],
                        $plan['expression'],
                        $count,
                    );
                }
            }
            if ($count !== $plan['reads']) {
                $this->fail($count === 0 ? 'match result is not used.' : 'match result is used more than once.');
            }
        }

        foreach ($updated as $blockId => $roots) {
            $next = [];
            foreach ($roots as $root) {
                if ($keep !== null && $root === $keep) {
                    $next[] = $root;
                    continue;
                }
                if (isset($plan['roots'][$root->opline])) {
                    continue;
                }
                $next[] = $root;
            }
            $updated[$blockId] = $next;
        }

        foreach ($updated as $blockId => $roots) {
            $this->write($blockId, $roots);
        }
        $this->dropDiagnostics($plan['diagnostics'], $plan['use'], $plan['kind'], $plan['index']);
        $this->completed[$plan['kind'] . ':' . $plan['index']] = $plan['expression'];
        $this->merges[$plan['expression']->opline] = $plan['merge'];
    }

    private function matchOpaque(Block $block, Instruction $instruction): Opaque
    {
        $found = null;
        foreach ($this->roots[$block->id] as $expression) {
            if (!$expression instanceof Opaque || $expression->opcode !== 'MATCH' || $expression->opline !== $instruction->index) {
                continue;
            }
            if ($found instanceof Opaque) {
                $this->fail('match subject expression is missing.');
            }
            $found = $expression;
        }
        if (!$found instanceof Opaque) {
            $this->fail('match subject expression is missing.');
        }

        return $found;
    }

    private function unhandled(Block $block, Instruction $match): ?Instruction
    {
        if (count($block->instructions) !== 1 || $block->instructions[0]->opcode !== 'MATCH_ERROR') {
            return null;
        }
        $error = $block->instructions[0];
        if (!$this->sameSubject($match->op1->kind, $match->op1->index, $error)) {
            $this->fail('match error does not use the match subject.');
        }

        return $error;
    }

    private function sameSubject(string $kind, ?int $index, Instruction $error): bool
    {
        $operand = $error->op1;

        return $operand->kind === $kind
            && $operand->index === $index
            && in_array($kind, ['const', 'cv', 'tmp', 'var'], true);
    }

    /**
     * @return array{entry: Block, block: Block, qm: Opaque, jump: Instruction, merge: Block, case: int|string|null, default: bool, value: Expression}
     */
    private function armExit(Block $entry, int $matchOpline, int|string|null $case, bool $default): array
    {
        $seen = [];
        $block = $entry;
        while (true) {
            if (isset($seen[$block->id])) {
                $this->fail('match arm result is ambiguous.');
            }
            $seen[$block->id] = true;
            $last = $this->last($block);
            $qm = $this->qmAssign($block);
            if (
                $qm instanceof Opaque
                && $last->opcode === 'JMP'
                && count($block->successors) === 1
                && $block->successors[0]->kind === 'jump'
            ) {
                return [
                    'entry' => $entry,
                    'block' => $block,
                    'qm' => $qm,
                    'jump' => $last,
                    'merge' => $this->block($block->successors[0]->to),
                    'case' => $case,
                    'default' => $default,
                    'value' => $this->armValue($qm),
                ];
            }
            if ($last->opcode === 'MATCH' && $last->index !== $matchOpline && isset($this->merges[$last->index])) {
                $block = $this->merges[$last->index];
                continue;
            }
            if (count($block->successors) !== 1) {
                $this->fail('match arm result is ambiguous.');
            }
            $edge = $block->successors[0];
            if ($edge->kind !== 'fallthrough' && $edge->kind !== 'jump') {
                $this->fail('match arm result is ambiguous.');
            }
            $block = $this->block($edge->to);
        }
    }

    private function qmAssign(Block $block): ?Opaque
    {
        $found = null;
        foreach ($this->roots[$block->id] as $expression) {
            if (!$expression instanceof Opaque || $expression->opcode !== 'QM_ASSIGN') {
                continue;
            }
            if ($found instanceof Opaque) {
                $this->fail('match arm result is ambiguous.');
            }
            $found = $expression;
        }

        return $found;
    }

    private function armValue(Opaque $assignment): Expression
    {
        $value = $assignment->op1->resolved;
        if ($value instanceof Unresolved) {
            $completed = $this->completed[$value->kind . ':' . $value->index] ?? null;
            if ($completed instanceof MatchExpression) {
                return $completed;
            }
            $this->fail('match arm result is incomplete.');
        }
        if (!$value instanceof Expression) {
            $this->fail('match arm result is incomplete.');
        }

        return $value;
    }

    private function assertArmBlock(Block $block, Opaque $assignment): void
    {
        foreach ($this->roots[$block->id] as $expression) {
            if ($expression === $assignment) {
                continue;
            }
            if ($expression instanceof Opaque && $expression->opcode === 'JMP') {
                continue;
            }
            $this->fail('match arm has an instruction this pass cannot place.');
        }
    }

    /**
     * @param list<array{entry: Block, block: Block, qm: Opaque, jump: Instruction, merge: Block, case: int|string|null, default: bool, value: Expression}> $exits
     * @return list<MatchArm>
     */
    private function arms(array $exits, bool $unhandled): array
    {
        $groups = [];
        foreach ($exits as $exit) {
            $key = $exit['qm']->opline;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'opline' => $exit['entry']->start,
                    'line' => $exit['value']->line,
                    'conditions' => [],
                    'result' => $exit['value'],
                    'default' => false,
                ];
            }
            if ($exit['default']) {
                $groups[$key]['default'] = true;
            }
            if ($exit['case'] !== null) {
                $groups[$key]['conditions'][] = new Literal(
                    $exit['entry']->start,
                    $exit['value']->line,
                    is_int($exit['case']) ? 'int' : 'string',
                    $exit['case'],
                );
            }
        }

        uasort($groups, static fn (array $left, array $right): int => $left['opline'] <=> $right['opline']);
        $arms = [];
        $defaults = 0;
        foreach ($groups as $group) {
            if ($group['conditions'] === [] && $group['default'] !== true) {
                $this->fail('match condition is missing.');
            }
            if ($group['default'] === true) {
                $defaults++;
            }
            $arms[] = new MatchArm(
                $group['opline'],
                $group['line'],
                $group['conditions'],
                $group['result'],
                $group['default'] === true,
            );
        }
        if ($unhandled && $defaults !== 0) {
            $this->fail('match default edge is missing.');
        }
        if (!$unhandled && $defaults !== 1) {
            $this->fail('match default edge is missing.');
        }

        return $arms;
    }

    /**
     * @param array{kind: string, index: int} $slot
     * @param list<array{qm: Opaque}> $exits
     */
    private function assertNoExtraArm(Instruction $match, Block $merge, array $slot, array $exits): void
    {
        $known = [];
        foreach ($exits as $exit) {
            $known[$exit['qm']->opline] = true;
        }
        foreach ($this->opArray->instructions as $instruction) {
            if ($instruction->index <= $match->index || $instruction->index >= $merge->start) {
                continue;
            }
            if ($instruction->opcode !== 'QM_ASSIGN' || isset($known[$instruction->index])) {
                continue;
            }
            $written = $this->instructionSlot($instruction);
            if ($written !== null && $written['kind'] === $slot['kind'] && $written['index'] === $slot['index']) {
                $this->fail('match result has an extra arm.');
            }
        }
    }

    /**
     * @return list<array{unresolved: Unresolved, roots: list<Expression>}>
     */
    private function uses(string $kind, int $index): array
    {
        $found = [];
        $ids = [];
        foreach ($this->roots as $roots) {
            foreach ($roots as $root) {
                $this->collectUse($root, $root, $kind, $index, $found, $ids);
            }
        }

        return $found;
    }

    /**
     * @param list<array{unresolved: Unresolved, roots: list<Expression>}> $found
     * @param array<int, int> $ids
     */
    private function collectUse(
        Expression $expression,
        Expression $root,
        string $kind,
        int $index,
        array &$found,
        array &$ids,
    ): void {
        if ($expression instanceof Unresolved && $expression->kind === $kind && $expression->index === $index) {
            $id = spl_object_id($expression);
            if (!isset($ids[$id])) {
                $ids[$id] = count($found);
                $found[] = ['unresolved' => $expression, 'roots' => []];
            }
            $position = $ids[$id];
            foreach ($found[$position]['roots'] as $existing) {
                if ($existing === $root) {
                    return;
                }
            }
            $found[$position]['roots'][] = $root;

            return;
        }
        foreach ($this->children($expression) as $child) {
            $this->collectUse($child, $root, $kind, $index, $found, $ids);
        }
    }

    /**
     * @param list<array{unresolved: Unresolved, roots: list<Expression>}> $uses
     */
    private function valueUse(array $uses): ?Unresolved
    {
        $value = null;
        foreach ($uses as $use) {
            $roots = $this->valueRoots($use['roots']);
            if ($roots === []) {
                continue;
            }
            if (count($roots) !== 1 || $value instanceof Unresolved) {
                $this->fail('match result is used more than once.');
            }
            $value = $use['unresolved'];
        }

        return $value;
    }

    /**
     * @param list<array{unresolved: Unresolved, roots: list<Expression>}> $uses
     */
    private function valueRoot(array $uses, Unresolved $value): Expression
    {
        foreach ($uses as $use) {
            if ($use['unresolved'] !== $value) {
                continue;
            }
            $roots = $this->valueRoots($use['roots']);
            if (count($roots) === 1) {
                return $roots[0];
            }
        }
        $this->fail('match result is not used.');
    }

    /**
     * @param list<Expression> $roots
     * @return list<Expression>
     */
    private function valueRoots(array $roots): array
    {
        $value = [];
        foreach ($roots as $root) {
            if ($root instanceof Opaque && ($root->opcode === 'VERIFY_RETURN_TYPE' || $root->opcode === 'FREE')) {
                if ($root->opcode === 'FREE') {
                    $this->fail('match result is not used.');
                }
                continue;
            }
            $value[] = $root;
        }

        return $value;
    }

    private function directSend(Expression $root, Unresolved $use): bool
    {
        return $root instanceof Opaque
            && in_array($root->opcode, self::SEND, true)
            && $root->op1->resolved === $use
            && $root->op2->kind === 'unused'
            && $root->op2->value !== null;
    }

    /**
     * @return array{init: Opaque, execute: Instruction, opaque: Opaque, checks: bool, check: Instruction|null}
     */
    private function stitch(Block $matchBlock, Instruction $match, Block $merge, Opaque $send): array
    {
        $inits = [];
        foreach ($this->roots[$matchBlock->id] as $expression) {
            if (
                $expression instanceof Opaque
                && ($expression->opcode === 'INIT_FCALL' || $expression->opcode === 'INIT_FCALL_BY_NAME')
                && $expression->opline < $match->index
                && $expression->reason === 'function call was interrupted by MATCH'
            ) {
                $inits[] = $expression;
            }
        }
        if (count($inits) !== 1) {
            $this->fail('match result call is missing its init.');
        }
        if ($inits[0]->extendedValue !== 1 || $send->op2->value !== 1) {
            $this->fail('match result call has more than one argument.');
        }
        $init = $inits[0];
        $name = $init->op2->resolved;
        if (!$name instanceof Literal || $name->type !== 'string' || !is_string($name->value)) {
            $this->fail('match result call is missing its name.');
        }

        $sendBlock = $this->graph->blockContaining($send->opline);
        if (!$sendBlock instanceof Block || $sendBlock->id !== $merge->id) {
            $this->fail('match result call is not at the merge.');
        }
        $position = null;
        foreach ($sendBlock->instructions as $index => $instruction) {
            if ($instruction->index === $send->opline) {
                $position = $index;
                break;
            }
        }
        if ($position === null) {
            $this->fail('match result call is missing its send.');
        }
        $next = $sendBlock->instructions[$position + 1] ?? null;
        $check = null;
        $checks = false;
        if ($next instanceof Instruction && $next->opcode === 'CHECK_UNDEF_ARGS') {
            $checks = true;
            $check = $next;
            $next = $sendBlock->instructions[$position + 2] ?? null;
        }
        if (!$next instanceof Instruction || !$this->executorMatches($init->opcode, $next->opcode)) {
            $this->fail('match result call does not finish.');
        }
        $opaque = $this->findOpaque($next->index);
        if (!$opaque instanceof Opaque || $opaque->opcode !== $next->opcode) {
            $this->fail('match result call is missing its execute.');
        }

        return [
            'init' => $init,
            'execute' => $next,
            'opaque' => $opaque,
            'checks' => $checks,
            'check' => $check,
        ];
    }

    private function executorMatches(string $init, string $execute): bool
    {
        return match ($init) {
            'INIT_FCALL' => $execute === 'DO_ICALL' || $execute === 'DO_UCALL',
            'INIT_FCALL_BY_NAME' => $execute === 'DO_FCALL_BY_NAME',
            default => false,
        };
    }

    private function functionCall(
        MatchExpression $match,
        Opaque $init,
        Opaque $send,
        Instruction $execute,
        bool $checks,
    ): FunctionCall {
        $name = $init->op2->resolved;
        if (!$name instanceof Literal || !is_string($name->value) || $send->op2->value === null) {
            $this->fail('match result call is incomplete.');
        }

        return new FunctionCall(
            $execute->index,
            $execute->line,
            $name->value,
            [new Argument(
                $send->opline,
                $send->line,
                $send->opcode,
                $match,
                null,
                Argument::VALUE,
                false,
                $send->op2->value,
            )],
            $init->opcode,
            $init->opline,
            $init->extendedValue,
            $execute->opcode,
            $execute->extendedValue,
            $checks,
        );
    }

    /**
     * @return array<int, true>
     */
    private function subjectFree(Block $merge, Instruction $match): array
    {
        $kind = $match->op1->kind;
        if (($kind !== 'tmp' && $kind !== 'var') || $match->op1->index === null) {
            return [];
        }
        $consumed = [];
        foreach ($this->roots[$merge->id] as $expression) {
            if (
                $expression instanceof Opaque
                && $expression->opcode === 'FREE'
                && ($expression->extendedValue & 2) !== 0
                && $expression->op1->kind === $kind
                && $expression->op1->index === $match->op1->index
            ) {
                $consumed[$expression->opline] = true;
            }
        }

        return $consumed;
    }

    /**
     * @return array{kind: string, index: int}|null
     */
    private function writtenSlot(Opaque $assignment): ?array
    {
        $result = $assignment->result;
        if (($result->kind !== 'tmp' && $result->kind !== 'var') || $result->index === null) {
            return null;
        }

        return ['kind' => $result->kind, 'index' => $result->index];
    }

    /**
     * @return array{kind: string, index: int}|null
     */
    private function instructionSlot(Instruction $instruction): ?array
    {
        $result = $instruction->result;
        if (($result->kind !== 'tmp' && $result->kind !== 'var') || $result->index === null) {
            return null;
        }

        return ['kind' => $result->kind, 'index' => $result->index];
    }

    private function findOpaque(int $opline): ?Opaque
    {
        $found = null;
        foreach ($this->roots as $roots) {
            foreach ($roots as $root) {
                $this->findOpaqueIn($root, $opline, $found);
            }
        }

        return $found;
    }

    private function findOpaqueIn(Expression $expression, int $opline, ?Opaque &$found): void
    {
        if ($expression instanceof Opaque && $expression->opline === $opline) {
            if ($found instanceof Opaque && $found !== $expression) {
                $this->fail('match result call is incomplete.');
            }
            $found = $expression;
        }
        foreach ($this->children($expression) as $child) {
            $this->findOpaqueIn($child, $opline, $found);
        }
    }

    private function replaceSlot(Expression $expression, string $kind, int $index, MatchExpression $match, int &$count): Expression
    {
        $id = spl_object_id($expression);
        if (isset($this->memo[$id])) {
            return $this->memo[$id];
        }
        if ($expression instanceof Unresolved && $expression->kind === $kind && $expression->index === $index) {
            $count++;
            $this->memo[$id] = $match;

            return $match;
        }
        if ($expression instanceof Binary) {
            $left = $this->replaceSlot($expression->left, $kind, $index, $match, $count);
            $right = $this->replaceSlot($expression->right, $kind, $index, $match, $count);
            if ($left === $expression->left && $right === $expression->right) {
                return $expression;
            }

            $rebuilt = new Binary($expression->opline, $expression->line, $expression->operator, $left, $right);
            $this->memo[$id] = $rebuilt;

            return $rebuilt;
        }
        if ($expression instanceof Unary) {
            $operand = $this->replaceSlot($expression->operand, $kind, $index, $match, $count);
            if ($operand === $expression->operand) {
                return $expression;
            }

            return new Unary($expression->opline, $expression->line, $expression->operator, $operand);
        }
        if ($expression instanceof Assignment) {
            $value = $this->replaceSlot($expression->value, $kind, $index, $match, $count);
            if ($value === $expression->value) {
                return $expression;
            }

            return new Assignment($expression->opline, $expression->line, $expression->variable, $value, $expression->operator);
        }
        if ($expression instanceof ReturnValue) {
            $value = $expression->value === null
                ? null
                : $this->replaceSlot($expression->value, $kind, $index, $match, $count);
            if ($value === $expression->value) {
                return $expression;
            }

            return new ReturnValue($expression->opline, $expression->line, $value);
        }
        if ($expression instanceof FunctionCall) {
            $arguments = [];
            $same = true;
            foreach ($expression->arguments as $argument) {
                $value = $this->replaceSlot($argument->value, $kind, $index, $match, $count);
                if ($value === $argument->value) {
                    $arguments[] = $argument;
                    continue;
                }
                $same = false;
                $arguments[] = new Argument(
                    $argument->opline,
                    $argument->line,
                    $argument->opcode,
                    $value,
                    $argument->name,
                    $argument->mode,
                    $argument->unpack,
                    $argument->position,
                );
            }
            if ($same) {
                return $expression;
            }

            return new FunctionCall(
                $expression->opline,
                $expression->line,
                $expression->name,
                $arguments,
                $expression->initOpcode,
                $expression->initOpline,
                $expression->initExtendedValue,
                $expression->executeOpcode,
                $expression->executeExtendedValue,
                $expression->checksUndefinedArguments,
            );
        }
        if ($expression instanceof MatchExpression) {
            return $this->replaceMatch($expression, $kind, $index, $match, $count);
        }
        if ($expression instanceof Opaque) {
            $op1 = $this->replaceOperand($expression->op1, $kind, $index, $match, $count);
            $op2 = $this->replaceOperand($expression->op2, $kind, $index, $match, $count);
            $result = $this->replaceOperand($expression->result, $kind, $index, $match, $count);
            if ($op1 === $expression->op1 && $op2 === $expression->op2 && $result === $expression->result) {
                return $expression;
            }

            return new Opaque(
                $expression->opline,
                $expression->line,
                $expression->opcode,
                $expression->opcodeNumber,
                $expression->extendedValue,
                $expression->jumpTarget,
                $op1,
                $op2,
                $result,
                $expression->extra,
                $expression->reason,
            );
        }

        return $expression;
    }

    private function replaceMatch(MatchExpression $expression, string $kind, int $index, MatchExpression $match, int &$count): MatchExpression
    {
        $subject = $this->replaceSlot($expression->subject, $kind, $index, $match, $count);
        $arms = [];
        $same = $subject === $expression->subject;
        foreach ($expression->arms as $arm) {
            $result = $this->replaceSlot($arm->result, $kind, $index, $match, $count);
            if ($result === $arm->result) {
                $arms[] = $arm;
                continue;
            }
            $same = false;
            $arms[] = new MatchArm($arm->opline, $arm->line, $arm->conditions, $result, $arm->isDefault);
        }
        if ($same) {
            return $expression;
        }

        return new MatchExpression(
            $expression->opline,
            $expression->line,
            $subject,
            $arms,
            $expression->unhandledOpline,
            $expression->fallbackOpline,
        );
    }

    private function replaceOperand(OperandUse $operand, string $kind, int $index, MatchExpression $match, int &$count): OperandUse
    {
        if (!$operand->resolved instanceof Expression) {
            return $operand;
        }
        $resolved = $this->replaceSlot($operand->resolved, $kind, $index, $match, $count);
        if ($resolved === $operand->resolved) {
            return $operand;
        }

        return new OperandUse(
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
            $resolved,
        );
    }

    private function replaceObject(Expression $expression, object $from, Expression $to, int &$count): Expression
    {
        $id = spl_object_id($expression);
        if (isset($this->memo[$id])) {
            return $this->memo[$id];
        }
        if ($expression === $from) {
            $count++;
            $this->memo[$id] = $to;

            return $to;
        }
        if ($expression instanceof Binary) {
            $left = $this->replaceObject($expression->left, $from, $to, $count);
            $right = $this->replaceObject($expression->right, $from, $to, $count);
            if ($left === $expression->left && $right === $expression->right) {
                return $expression;
            }

            return new Binary($expression->opline, $expression->line, $expression->operator, $left, $right);
        }
        if ($expression instanceof Unary) {
            $operand = $this->replaceObject($expression->operand, $from, $to, $count);
            if ($operand === $expression->operand) {
                return $expression;
            }

            return new Unary($expression->opline, $expression->line, $expression->operator, $operand);
        }
        if ($expression instanceof Assignment) {
            $value = $this->replaceObject($expression->value, $from, $to, $count);
            if ($value === $expression->value) {
                return $expression;
            }

            return new Assignment($expression->opline, $expression->line, $expression->variable, $value, $expression->operator);
        }
        if ($expression instanceof ReturnValue) {
            $value = $expression->value === null ? null : $this->replaceObject($expression->value, $from, $to, $count);
            if ($value === $expression->value) {
                return $expression;
            }

            return new ReturnValue($expression->opline, $expression->line, $value);
        }
        if ($expression instanceof FunctionCall) {
            $arguments = [];
            $same = true;
            foreach ($expression->arguments as $argument) {
                $value = $this->replaceObject($argument->value, $from, $to, $count);
                if ($value === $argument->value) {
                    $arguments[] = $argument;
                    continue;
                }
                $same = false;
                $arguments[] = new Argument(
                    $argument->opline,
                    $argument->line,
                    $argument->opcode,
                    $value,
                    $argument->name,
                    $argument->mode,
                    $argument->unpack,
                    $argument->position,
                );
            }
            if ($same) {
                return $expression;
            }

            return new FunctionCall(
                $expression->opline,
                $expression->line,
                $expression->name,
                $arguments,
                $expression->initOpcode,
                $expression->initOpline,
                $expression->initExtendedValue,
                $expression->executeOpcode,
                $expression->executeExtendedValue,
                $expression->checksUndefinedArguments,
            );
        }
        if ($expression instanceof MatchExpression) {
            $subject = $this->replaceObject($expression->subject, $from, $to, $count);
            $arms = [];
            $same = $subject === $expression->subject;
            foreach ($expression->arms as $arm) {
                $result = $this->replaceObject($arm->result, $from, $to, $count);
                if ($result === $arm->result) {
                    $arms[] = $arm;
                    continue;
                }
                $same = false;
                $arms[] = new MatchArm($arm->opline, $arm->line, $arm->conditions, $result, $arm->isDefault);
            }
            if ($same) {
                return $expression;
            }

            return new MatchExpression(
                $expression->opline,
                $expression->line,
                $subject,
                $arms,
                $expression->unhandledOpline,
                $expression->fallbackOpline,
            );
        }
        if ($expression instanceof Opaque) {
            $op1 = $this->replaceObjectOperand($expression->op1, $from, $to, $count);
            $op2 = $this->replaceObjectOperand($expression->op2, $from, $to, $count);
            $result = $this->replaceObjectOperand($expression->result, $from, $to, $count);
            if ($op1 === $expression->op1 && $op2 === $expression->op2 && $result === $expression->result) {
                return $expression;
            }

            return new Opaque(
                $expression->opline,
                $expression->line,
                $expression->opcode,
                $expression->opcodeNumber,
                $expression->extendedValue,
                $expression->jumpTarget,
                $op1,
                $op2,
                $result,
                $expression->extra,
                $expression->reason,
            );
        }

        return $expression;
    }

    private function replaceObjectOperand(OperandUse $operand, object $from, Expression $to, int &$count): OperandUse
    {
        if (!$operand->resolved instanceof Expression) {
            return $operand;
        }
        $resolved = $this->replaceObject($operand->resolved, $from, $to, $count);
        if ($resolved === $operand->resolved) {
            return $operand;
        }

        return new OperandUse(
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
            $resolved,
        );
    }

    /**
     * @return list<Expression>
     */
    private function children(Expression $expression): array
    {
        if ($expression instanceof Binary) {
            return [$expression->left, $expression->right];
        }
        if ($expression instanceof Unary) {
            return [$expression->operand];
        }
        if ($expression instanceof Assignment) {
            return [$expression->value];
        }
        if ($expression instanceof ReturnValue) {
            return $expression->value === null ? [] : [$expression->value];
        }
        if ($expression instanceof FunctionCall) {
            $children = [];
            foreach ($expression->arguments as $argument) {
                $children[] = $argument->value;
            }

            return $children;
        }
        if ($expression instanceof MatchExpression) {
            $children = [$expression->subject];
            foreach ($expression->arms as $arm) {
                $children[] = $arm->result;
            }

            return $children;
        }
        if ($expression instanceof Opaque) {
            $children = [];
            foreach ([$expression->op1, $expression->op2, $expression->result] as $operand) {
                if ($operand->resolved instanceof Expression) {
                    $children[] = $operand->resolved;
                }
            }

            return $children;
        }

        return [];
    }

    /**
     * @param array<int, true> $consumed
     */
    private function dropDiagnostics(array $consumed, Unresolved $use, string $kind, int $index): void
    {
        $reason = $kind . ' ' . $index . ' is not defined in this block';
        $kept = [];
        foreach ($this->diagnostics as $diagnostic) {
            if (isset($consumed[$diagnostic->opline]) && ($diagnostic->kind === 'unsupported' || $diagnostic->kind === 'unresolved')) {
                continue;
            }
            if ($diagnostic->kind === 'unresolved' && $diagnostic->reason === $reason) {
                continue;
            }
            if (
                $diagnostic->kind === 'unresolved'
                && $diagnostic->opline === $use->opline
                && $diagnostic->reason === $use->reason
            ) {
                continue;
            }
            $kept[] = $diagnostic;
        }
        $this->diagnostics = $kept;
    }

    /**
     * @param list<Expression> $expressions
     */
    private function write(string $blockId, array $expressions): void
    {
        if ($this->roots[$blockId] === $expressions) {
            return;
        }
        $this->roots[$blockId] = $expressions;
        $this->dirty[$blockId] = true;
    }

    private function block(string $id): Block
    {
        $block = $this->byId[$id] ?? null;
        if (!$block instanceof Block) {
            $this->fail('match arm result is ambiguous.');
        }

        return $block;
    }

    private function last(Block $block): Instruction
    {
        if ($block->instructions === []) {
            $this->fail('match arm result is ambiguous.');
        }

        return $block->instructions[array_key_last($block->instructions)];
    }

    private function fail(string $reason): never
    {
        throw new MatchFallback($reason);
    }
}
