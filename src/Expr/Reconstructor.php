<?php

declare(strict_types=1);

namespace Rephper\Expr;

use Rephper\Cfg\Block;
use Rephper\Cfg\Graph;
use Rephper\Ir\Instruction;
use Rephper\Ir\OpArray;
use Rephper\Ir\Operand;

/**
 * Reconstructs expressions inside one basic block at a time.
 *
 * Temporary slots do not carry across blocks. A value defined on another path
 * stays unresolved instead of being copied from a different block.
 */
final class Reconstructor
{
    /** @var array<string, string> */
    private const BINARY = [
        'ADD' => '+',
        'SUB' => '-',
        'MUL' => '*',
        'DIV' => '/',
        'MOD' => '%',
        'CONCAT' => '.',
        'IS_IDENTICAL' => '===',
        'IS_NOT_IDENTICAL' => '!==',
        'IS_EQUAL' => '==',
        'IS_NOT_EQUAL' => '!=',
        'IS_SMALLER' => '<',
        'IS_SMALLER_OR_EQUAL' => '<=',
    ];

    /** @var array<string, string> */
    private const UNARY = [
        'BOOL_NOT' => '!',
        'BW_NOT' => '~',
        'BOOL' => 'bool',
    ];

    /**
     * PHP 8.4 opcode numbers stored in ASSIGN_OP's extended_value.
     *
     * @var array<int, string>
     */
    private const COMPOUND = [
        1 => '+',
        2 => '-',
        3 => '*',
        4 => '/',
        5 => '%',
        8 => '.',
    ];

    /** @var list<string> */
    private const RECORDED = ['RECV', 'RECV_INIT', 'VERIFY_RETURN_TYPE'];

    /** @var array<string, Expression> */
    private array $defined = [];

    /** @var list<string> */
    private array $pending = [];

    /** @var list<Expression> */
    private array $roots = [];

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    private string $blockId = '';

    public function reconstruct(OpArray $opArray, Graph $graph): Reconstruction
    {
        if ($graph->opArrayId !== $opArray->id) {
            throw new ExpressionException(
                'Graph ' . $graph->opArrayId . ' does not belong to op array ' . $opArray->id
            );
        }

        $blocks = [];
        $this->diagnostics = [];
        foreach ($graph->blocks as $block) {
            $blocks[] = $this->block($opArray, $block);
        }

        return new Reconstruction($blocks, $this->diagnostics);
    }

    private function block(OpArray $opArray, Block $block): BlockExpressions
    {
        $this->defined = [];
        $this->pending = [];
        $this->roots = [];
        $this->blockId = $block->id;

        foreach ($block->instructions as $instruction) {
            $this->instruction($opArray, $instruction);
        }
        foreach ($this->pending as $slot) {
            $this->roots[] = $this->defined[$slot];
        }

        return new BlockExpressions($block->id, $block->start, $block->end, $this->roots);
    }

    private function instruction(OpArray $opArray, Instruction $instruction): void
    {
        if (isset(self::BINARY[$instruction->opcode])) {
            $this->store($instruction, new Binary(
                $instruction->index,
                $instruction->line,
                self::BINARY[$instruction->opcode],
                $this->value($opArray, $instruction, $instruction->op1),
                $this->value($opArray, $instruction, $instruction->op2),
            ));

            return;
        }
        if (isset(self::UNARY[$instruction->opcode])) {
            $this->store($instruction, new Unary(
                $instruction->index,
                $instruction->line,
                self::UNARY[$instruction->opcode],
                $this->value($opArray, $instruction, $instruction->op1),
            ));

            return;
        }

        match ($instruction->opcode) {
            'ASSIGN' => $this->assign($opArray, $instruction, null),
            'ASSIGN_OP' => $this->assignOp($opArray, $instruction),
            'RETURN' => $this->roots[] = new ReturnValue(
                $instruction->index,
                $instruction->line,
                $this->value($opArray, $instruction, $instruction->op1),
            ),
            default => $this->opaque($instruction),
        };
    }

    private function assign(OpArray $opArray, Instruction $instruction, ?string $operator): void
    {
        if ($instruction->op1->kind !== 'cv' || $instruction->op1->name === null) {
            $this->opaque($instruction, 'assignment target is not a compiled variable');

            return;
        }

        $this->roots[] = new Assignment(
            $instruction->index,
            $instruction->line,
            $instruction->op1->name,
            $this->value($opArray, $instruction, $instruction->op2),
            $operator,
        );
    }

    private function assignOp(OpArray $opArray, Instruction $instruction): void
    {
        $operator = self::COMPOUND[$instruction->extendedValue] ?? null;
        if ($operator === null) {
            $this->opaque(
                $instruction,
                'ASSIGN_OP extended_value ' . $instruction->extendedValue . ' is not a supported compound operator'
            );

            return;
        }

        $this->assign($opArray, $instruction, $operator);
    }

    private function store(Instruction $instruction, Expression $expression): void
    {
        $slot = $this->slot($instruction->result);
        if ($slot === null) {
            $this->roots[] = $expression;

            return;
        }
        if (in_array($slot, $this->pending, true)) {
            $this->roots[] = $this->defined[$slot];
            $this->pending = array_values(array_filter(
                $this->pending,
                static fn (string $pending): bool => $pending !== $slot
            ));
        }
        $this->defined[$slot] = $expression;
        $this->pending[] = $slot;
    }

    private function value(OpArray $opArray, Instruction $instruction, Operand $operand): Expression
    {
        if ($operand->kind === 'const') {
            if ($operand->index === null) {
                throw new ExpressionException($instruction->opcode . ' at ' . $instruction->index . ' const operand is missing an index');
            }
            $literal = $opArray->literal($operand->index);
            if ($literal === null) {
                throw new ExpressionException(
                    $instruction->opcode . ' at ' . $instruction->index . ' const index ' . $operand->index . ' is outside the literal table'
                );
            }

            return new Literal($instruction->index, $instruction->line, $literal->type, $literal->value);
        }
        if ($operand->kind === 'cv') {
            if ($operand->name === null || $operand->index === null) {
                throw new ExpressionException($instruction->opcode . ' at ' . $instruction->index . ' compiled variable is missing its name');
            }

            return new VariableRead($instruction->index, $instruction->line, $operand->name, $operand->index);
        }
        if ($operand->kind === 'tmp' || $operand->kind === 'var') {
            if ($operand->index === null) {
                throw new ExpressionException($instruction->opcode . ' at ' . $instruction->index . ' ' . $operand->kind . ' operand is missing an index');
            }
            $slot = $operand->kind . ':' . $operand->index;
            if (!isset($this->defined[$slot])) {
                $reason = $operand->kind . ' ' . $operand->index . ' is not defined in this block';
                $this->diagnostics[] = new Diagnostic(
                    'unresolved',
                    $this->blockId,
                    $instruction->index,
                    $instruction->line,
                    $instruction->opcode,
                    $reason,
                );

                return new Unresolved($instruction->index, $instruction->line, $operand->kind, $operand->index, $reason);
            }
            $this->pending = array_values(array_filter(
                $this->pending,
                static fn (string $pending): bool => $pending !== $slot
            ));

            return $this->defined[$slot];
        }

        throw new ExpressionException(
            $instruction->opcode . ' at ' . $instruction->index . ' has operand kind ' . $operand->kind . ' where a value is required'
        );
    }

    private function opaque(Instruction $instruction, ?string $reason = null): void
    {
        $recorded = in_array($instruction->opcode, self::RECORDED, true);
        $reason ??= $recorded
            ? 'recorded because it is not an expression'
            : 'opcode is not reconstructed as an expression';
        $expression = new Opaque($instruction->index, $instruction->line, $instruction->opcode, $reason);
        if (!$recorded) {
            $this->diagnostics[] = new Diagnostic(
                'unsupported',
                $this->blockId,
                $instruction->index,
                $instruction->line,
                $instruction->opcode,
                $reason,
            );
        }
        $slot = $this->slot($instruction->result);
        if ($slot !== null && !$recorded) {
            $this->store($instruction, $expression);

            return;
        }
        $this->roots[] = $expression;
    }

    private function slot(Operand $operand): ?string
    {
        if (($operand->kind !== 'tmp' && $operand->kind !== 'var') || $operand->index === null) {
            return null;
        }

        return $operand->kind . ':' . $operand->index;
    }
}
