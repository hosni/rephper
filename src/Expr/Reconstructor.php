<?php

declare(strict_types=1);

namespace Rephper\Expr;

use Rephper\Cfg\Block;
use Rephper\Cfg\Graph;
use Rephper\Ir\Instruction;
use Rephper\Ir\Literal as IrLiteral;
use Rephper\Ir\OpArray;
use Rephper\Ir\Operand;

/**
 * Reconstructs expressions inside one basic block at a time.
 *
 * Temporary slots do not carry across blocks. A value defined on another path
 * stays unresolved instead of being copied from a different block.
 * MatchReconstructor then rebuilds a jump-table match whose arms meet again
 * after the branch. Blocks that contain no MATCH are unchanged.
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

    /** @var list<string> */
    private const NAMED_INIT = ['INIT_FCALL', 'INIT_FCALL_BY_NAME'];

    /**
     * Call initiators this pass does not turn into a FunctionCall.
     * They still open a frame so their sends are not attached to an outer call.
     *
     * @var list<string>
     */
    private const OPAQUE_INIT = [
        'NEW',
        'INIT_DYNAMIC_CALL',
        'INIT_METHOD_CALL',
        'INIT_STATIC_METHOD_CALL',
        'INIT_NS_FCALL_BY_NAME',
        'INIT_USER_CALL',
    ];

    /** @var list<string> */
    private const EXECUTE = ['DO_ICALL', 'DO_UCALL', 'DO_FCALL', 'DO_FCALL_BY_NAME'];

    /** @var list<string> */
    private const VALUE_SEND = ['SEND_VAL', 'SEND_VAR', 'SEND_VAL_EX', 'SEND_VAR_EX'];

    /**
     * @var list<array{
     *     kind: string,
     *     init: Instruction,
     *     name: string,
     *     positionalExpected: int,
     *     arguments: list<Argument>,
     *     instructions: list<Instruction>,
     *     checksUndefinedArguments: bool,
     *     sawNamedOrUnpack: bool
     * }>
     */
    private array $calls = [];

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

        return (new MatchReconstructor())->reconstruct(
            $opArray,
            $graph,
            new Reconstruction($blocks, $this->diagnostics),
        );
    }

    private function block(OpArray $opArray, Block $block): BlockExpressions
    {
        $this->defined = [];
        $this->pending = [];
        $this->roots = [];
        $this->calls = [];
        $this->blockId = $block->id;

        foreach ($block->instructions as $instruction) {
            $this->instruction($opArray, $instruction);
        }
        $this->abandonCalls($opArray, 'function call does not finish in this block');
        foreach ($this->pending as $slot) {
            $this->roots[] = $this->defined[$slot];
        }

        return new BlockExpressions($block->id, $block->start, $block->end, $this->roots);
    }

    private function instruction(OpArray $opArray, Instruction $instruction): void
    {
        if ($instruction->opcode === 'CALLABLE_CONVERT') {
            $this->releaseCallable($opArray, $instruction);

            return;
        }
        if ($this->isInitiator($instruction->opcode)) {
            $this->openCall($opArray, $instruction);

            return;
        }
        if ($this->calls !== []) {
            if ($this->isCallStep($instruction->opcode)) {
                $this->continueCall($opArray, $instruction);

                return;
            }
            if ($this->slot($instruction->result) === null) {
                $this->abandonCalls($opArray, 'function call was interrupted by ' . $instruction->opcode);
            }
        }

        $this->ordinary($opArray, $instruction);
    }

    private function ordinary(OpArray $opArray, Instruction $instruction): void
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
            default => $this->opaque($opArray, $instruction),
        };
    }

    private function assign(OpArray $opArray, Instruction $instruction, ?string $operator): void
    {
        if ($instruction->op1->kind !== 'cv' || $instruction->op1->name === null) {
            $this->opaque($opArray, $instruction, 'assignment target is not a compiled variable');

            return;
        }

        $this->store($instruction, new Assignment(
            $instruction->index,
            $instruction->line,
            $instruction->op1->name,
            $this->value($opArray, $instruction, $instruction->op2),
            $operator,
        ));
    }

    private function assignOp(OpArray $opArray, Instruction $instruction): void
    {
        $operator = self::COMPOUND[$instruction->extendedValue] ?? null;
        if ($operator === null) {
            $this->opaque(
                $opArray,
                $instruction,
                'ASSIGN_OP extended_value ' . $instruction->extendedValue . ' is not a supported compound operator'
            );

            return;
        }

        $this->assign($opArray, $instruction, $operator);
    }

    private function isInitiator(string $opcode): bool
    {
        return in_array($opcode, self::NAMED_INIT, true) || in_array($opcode, self::OPAQUE_INIT, true);
    }

    private function isCallStep(string $opcode): bool
    {
        return str_starts_with($opcode, 'SEND_')
            || $opcode === 'CHECK_UNDEF_ARGS'
            || in_array($opcode, self::EXECUTE, true);
    }

    private function openCall(OpArray $opArray, Instruction $instruction): void
    {
        $name = null;
        if (in_array($instruction->opcode, self::NAMED_INIT, true)) {
            $name = $this->literalString($opArray, $instruction, $instruction->op2);
        }
        $named = $name !== null;
        $this->calls[] = [
            'kind' => $named ? 'named' : 'opaque',
            'init' => $instruction,
            'name' => $name ?? '',
            'positionalExpected' => $named ? $instruction->extendedValue : 0,
            'arguments' => [],
            'instructions' => [$instruction],
            'checksUndefinedArguments' => false,
            'sawNamedOrUnpack' => false,
        ];
    }

    private function continueCall(OpArray $opArray, Instruction $instruction): void
    {
        $index = array_key_last($this->calls);
        if ($index === null) {
            $this->ordinary($opArray, $instruction);

            return;
        }
        if (str_starts_with($instruction->opcode, 'SEND_')) {
            if ($this->calls[$index]['kind'] === 'opaque') {
                $this->calls[$index]['instructions'][] = $instruction;

                return;
            }
            $this->namedSend($opArray, $index, $instruction);

            return;
        }
        if ($instruction->opcode === 'CHECK_UNDEF_ARGS') {
            if ($this->calls[$index]['checksUndefinedArguments']) {
                $this->failFrame($opArray, $index, $instruction, 'function call has a repeated CHECK_UNDEF_ARGS');

                return;
            }
            $this->calls[$index]['checksUndefinedArguments'] = true;
            $this->calls[$index]['instructions'][] = $instruction;

            return;
        }
        $this->finishFrame($opArray, $index, $instruction);
    }

    private function namedSend(OpArray $opArray, int $index, Instruction $instruction): void
    {
        $shape = $this->sendShape($opArray, $instruction);
        if ($shape === null || !$this->accepts($this->calls[$index], $shape)) {
            $this->failFrame($opArray, $index, $instruction, 'function call argument could not be reconstructed');

            return;
        }
        $argument = new Argument(
            $instruction->index,
            $instruction->line,
            $instruction->opcode,
            $this->value($opArray, $instruction, $instruction->op1),
            $shape['name'],
            $shape['mode'],
            $shape['unpack'],
            $shape['position'],
        );
        $this->calls[$index]['arguments'][] = $argument;
        $this->calls[$index]['instructions'][] = $instruction;
        if ($argument->name !== null || $argument->unpack) {
            $this->calls[$index]['sawNamedOrUnpack'] = true;
        }
    }

    /**
     * @return array{name: ?string, mode: string, unpack: bool, position: ?int}|null
     */
    private function sendShape(OpArray $opArray, Instruction $instruction): ?array
    {
        $unpack = $instruction->opcode === 'SEND_UNPACK';
        $mode = Argument::VALUE;
        if (in_array($instruction->opcode, self::VALUE_SEND, true)) {
            $mode = Argument::VALUE;
        } elseif ($instruction->opcode === 'SEND_REF') {
            $mode = Argument::REFERENCE;
        } elseif ($unpack) {
            $mode = Argument::VALUE;
        } else {
            return null;
        }

        if ($unpack) {
            if ($instruction->op2->kind !== 'unused') {
                return null;
            }

            return ['name' => null, 'mode' => $mode, 'unpack' => true, 'position' => null];
        }
        if ($instruction->op2->kind === 'const') {
            $name = $this->literalString($opArray, $instruction, $instruction->op2);
            if ($name === null) {
                return null;
            }

            return ['name' => $name, 'mode' => $mode, 'unpack' => false, 'position' => null];
        }
        if ($instruction->op2->kind === 'unused' && $instruction->op2->value !== null && $instruction->op2->value >= 1) {
            return ['name' => null, 'mode' => $mode, 'unpack' => false, 'position' => $instruction->op2->value];
        }

        return null;
    }

    /**
     * @param array{sawNamedOrUnpack: bool, positionalExpected: int, arguments: list<Argument>} $frame
     * @param array{name: ?string, mode: string, unpack: bool, position: ?int} $shape
     */
    private function accepts(array $frame, array $shape): bool
    {
        $namedOrUnpack = $shape['name'] !== null || $shape['unpack'];
        if (!$namedOrUnpack) {
            if ($frame['sawNamedOrUnpack'] || $shape['position'] === null) {
                return false;
            }
            $next = $this->positionalCount($frame) + 1;
            if ($shape['position'] !== $next) {
                return false;
            }
            if ($frame['positionalExpected'] > 0 && $shape['position'] > $frame['positionalExpected']) {
                return false;
            }

            return true;
        }

        return $frame['positionalExpected'] === 0
            || $this->positionalCount($frame) === $frame['positionalExpected'];
    }

    /**
     * @param array{arguments: list<Argument>} $frame
     */
    private function positionalCount(array $frame): int
    {
        $count = 0;
        foreach ($frame['arguments'] as $argument) {
            if ($argument->name === null && !$argument->unpack) {
                $count++;
            }
        }

        return $count;
    }

    private function finishFrame(OpArray $opArray, int $index, Instruction $instruction): void
    {
        $frame = $this->calls[$index];
        if ($frame['kind'] === 'opaque') {
            $this->failFrame($opArray, $index, $instruction, null);

            return;
        }
        if (!$this->executorMatches($frame['init']->opcode, $instruction->opcode) || !$this->argumentsComplete($frame)) {
            $this->failFrame($opArray, $index, $instruction, 'function call could not be reconstructed');

            return;
        }
        array_splice($this->calls, $index);
        $this->store($instruction, new FunctionCall(
            $instruction->index,
            $instruction->line,
            $frame['name'],
            $frame['arguments'],
            $frame['init']->opcode,
            $frame['init']->index,
            $frame['init']->extendedValue,
            $instruction->opcode,
            $instruction->extendedValue,
            $frame['checksUndefinedArguments'],
        ));
    }

    /**
     * @param array{positionalExpected: int, arguments: list<Argument>} $frame
     */
    private function argumentsComplete(array $frame): bool
    {
        return $frame['positionalExpected'] === 0 || $this->positionalCount($frame) === $frame['positionalExpected'];
    }

    private function executorMatches(string $init, string $execute): bool
    {
        return match ($init) {
            'INIT_FCALL' => $execute === 'DO_ICALL' || $execute === 'DO_UCALL',
            'INIT_FCALL_BY_NAME' => $execute === 'DO_FCALL_BY_NAME',
            default => false,
        };
    }

    private function releaseCallable(OpArray $opArray, Instruction $instruction): void
    {
        $index = array_key_last($this->calls);
        if ($index !== null) {
            $this->failFrame($opArray, $index, null, 'first-class callable is not a function call');
        }
        $this->ordinary($opArray, $instruction);
    }

    private function abandonCalls(OpArray $opArray, string $reason): void
    {
        $frames = $this->calls;
        $this->calls = [];
        foreach ($frames as $frame) {
            $this->flushFrame($opArray, $frame, null, $reason);
        }
    }

    /**
     * @param array{instructions: list<Instruction>} $frame
     */
    private function failFrame(OpArray $opArray, int $index, ?Instruction $extra, ?string $reason): void
    {
        $removed = array_splice($this->calls, $index);
        $last = array_key_last($removed);
        foreach ($removed as $position => $frame) {
            $this->flushFrame($opArray, $frame, $position === $last ? $extra : null, $reason);
        }
    }

    /**
     * @param array{instructions: list<Instruction>} $frame
     */
    private function flushFrame(OpArray $opArray, array $frame, ?Instruction $extra, ?string $reason): void
    {
        foreach ($frame['instructions'] as $instruction) {
            $this->opaque($opArray, $instruction, $reason);
        }
        if ($extra !== null) {
            $this->opaque($opArray, $extra, $reason);
        }
    }

    private function literal(int $opline, int $line, IrLiteral $literal): Literal
    {
        return new Literal(
            $opline,
            $line,
            $literal->type,
            $literal->value,
            $this->literalElements($opline, $line, $literal),
            $literal->binary,
        );
    }

    /**
     * @return list<array{key: Literal, value: Literal}>|null
     */
    private function literalElements(int $opline, int $line, IrLiteral $literal): ?array
    {
        if ($literal->type !== 'array' || $literal->recursive) {
            return null;
        }

        $elements = [];
        foreach ($literal->elements as $element) {
            $elements[] = [
                'key' => $this->literal($opline, $line, $element['key']),
                'value' => $this->literal($opline, $line, $element['value']),
            ];
        }

        return $elements;
    }

    private function literalString(OpArray $opArray, Instruction $instruction, Operand $operand): ?string
    {
        if ($operand->kind !== 'const') {
            return null;
        }
        if ($operand->index === null) {
            throw new ExpressionException($instruction->opcode . ' at ' . $instruction->index . ' const operand is missing an index');
        }
        $literal = $opArray->literal($operand->index);
        if ($literal === null) {
            throw new ExpressionException(
                $instruction->opcode . ' at ' . $instruction->index . ' const index ' . $operand->index . ' is outside the literal table'
            );
        }
        if ($literal->type !== 'string' || !is_string($literal->value)) {
            return null;
        }

        return $literal->value;
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

            return $this->literal($instruction->index, $instruction->line, $literal);
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

    private function opaque(OpArray $opArray, Instruction $instruction, ?string $reason = null): void
    {
        $recorded = in_array($instruction->opcode, self::RECORDED, true);
        $reason ??= $recorded
            ? 'recorded because it is not an expression'
            : 'opcode is not reconstructed as an expression';
        $expression = new Opaque(
            $instruction->index,
            $instruction->line,
            $instruction->opcode,
            $instruction->opcodeNumber,
            $instruction->extendedValue,
            $instruction->jumpTarget,
            $this->operand($opArray, $instruction, $instruction->op1, true),
            $this->operand($opArray, $instruction, $instruction->op2, true),
            $this->operand($opArray, $instruction, $instruction->result, false),
            $instruction->extra,
            $reason,
        );
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

    private function operand(OpArray $opArray, Instruction $instruction, Operand $operand, bool $resolve): OperandUse
    {
        $resolved = null;
        if ($resolve && in_array($operand->kind, ['const', 'cv', 'tmp', 'var'], true)) {
            $resolved = $this->value($opArray, $instruction, $operand);
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

    private function slot(Operand $operand): ?string
    {
        if (($operand->kind !== 'tmp' && $operand->kind !== 'var') || $operand->index === null) {
            return null;
        }

        return $operand->kind . ':' . $operand->index;
    }
}
