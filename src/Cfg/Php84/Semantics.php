<?php

declare(strict_types=1);

namespace Rephper\Cfg\Php84;

use Rephper\Cfg\CfgException;
use Rephper\Cfg\IncompleteMetadataException;
use Rephper\Cfg\Successor;
use Rephper\Cfg\UnsupportedSemanticsException;
use Rephper\Ir\Instruction;
use Rephper\Ir\Literal;
use Rephper\Ir\OpArray;
use Rephper\Ir\Operand;

/**
 * PHP 8.4 control successors at the start of pass_two().
 *
 * Jump operands are absolute opline indexes. BRK and CONT are still pseudo-opcodes.
 * Their op1 is exported as kind "level", but the compiler stores the breaks[] index
 * there and the depth in op2. GOTO's op1 "id" is the unwind count; the label name
 * is the op2 constant.
 */
final class Semantics
{
    private const LAST_CATCH = 1;

    /**
     * @return list<Successor>
     */
    public function successors(OpArray $opArray, Instruction $instruction): array
    {
        $number = Opcodes::NUMBERS[$instruction->opcode] ?? null;
        if ($number === null) {
            throw new UnsupportedSemanticsException(
                'Opcode ' . $instruction->opcode . ' has no PHP 8.4 control-flow semantics'
            );
        }
        if ($instruction->opcodeNumber !== $number) {
            throw new CfgException(
                'Opcode ' . $instruction->opcode . ' at ' . $instruction->index
                . ' has number ' . $instruction->opcodeNumber . ', PHP 8.4 uses ' . $number
            );
        }
        if (in_array($instruction->opcode, Opcodes::UNSUPPORTED, true)) {
            throw new UnsupportedSemanticsException(
                'Opcode ' . $instruction->opcode . ' is not modeled by the PHP 8.4 CFG'
            );
        }

        $next = $instruction->index + 1;

        return match ($instruction->opcode) {
            'JMP' => [$this->jump($instruction, $instruction->op1, 'jump')],
            'JMPZ', 'JMPZ_EX' => [
                $this->step($instruction, 'true', $next),
                $this->jump($instruction, $instruction->op2, 'false'),
            ],
            'JMPNZ', 'JMPNZ_EX', 'JMP_SET' => [
                $this->jump($instruction, $instruction->op2, 'true'),
                $this->step($instruction, 'false', $next),
            ],
            'COALESCE' => [
                $this->jump($instruction, $instruction->op2, 'not_null'),
                $this->step($instruction, 'null', $next),
            ],
            'JMP_NULL' => [
                $this->step($instruction, 'not_null', $next),
                $this->jump($instruction, $instruction->op2, 'null'),
            ],
            'FE_RESET_R', 'FE_RESET_RW' => [
                $this->step($instruction, 'fallthrough', $next),
                $this->jump($instruction, $instruction->op2, 'foreach_exit'),
            ],
            'FE_FETCH_R', 'FE_FETCH_RW' => [
                $this->step($instruction, 'fallthrough', $next),
                $this->extendedJump($instruction, 'foreach_exit'),
            ],
            'SWITCH_LONG', 'SWITCH_STRING' => $this->switchSuccessors($opArray, $instruction, true),
            'MATCH' => $this->switchSuccessors($opArray, $instruction, false),
            'BRK' => [$this->breakContinue($opArray, $instruction, true)],
            'CONT' => [$this->breakContinue($opArray, $instruction, false)],
            'GOTO' => [$this->goto($opArray, $instruction)],
            'RETURN', 'RETURN_BY_REF', 'GENERATOR_RETURN', 'THROW', 'MATCH_ERROR' => [],
            'FAST_CALL' => [$this->fastCall($opArray, $instruction)],
            'FAST_RET' => $this->fastReturn($opArray, $instruction),
            'CATCH' => $this->catch($instruction, $next),
            'ASSERT_CHECK' => [
                $this->jump($instruction, $instruction->op2, 'assert_skip'),
                $this->step($instruction, 'fallthrough', $next),
            ],
            'BIND_INIT_STATIC_OR_JMP' => [
                $this->jump($instruction, $instruction->op2, 'initialized'),
                $this->step($instruction, 'fallthrough', $next),
            ],
            'JMP_FRAMELESS' => [
                $this->jump($instruction, $instruction->op2, 'frameless'),
                $this->step($instruction, 'fallthrough', $next),
            ],
            default => [$this->step($instruction, 'fallthrough', $next)],
        };
    }

    private function step(Instruction $instruction, string $kind, int $target): Successor
    {
        if ($target !== $instruction->index + 1) {
            throw new CfgException('Fallthrough from ' . $instruction->index . ' is not the next opline');
        }

        return new Successor($kind, $target);
    }

    private function jump(Instruction $instruction, Operand $operand, string $kind): Successor
    {
        if ($operand->kind !== 'jump' || $operand->target === null) {
            throw new CfgException(
                $instruction->opcode . ' at ' . $instruction->index . ' is missing a jump operand'
            );
        }

        return new Successor($kind, $operand->target);
    }

    private function extendedJump(Instruction $instruction, string $kind): Successor
    {
        if ($instruction->jumpTarget !== null && $instruction->jumpTarget !== $instruction->extendedValue) {
            throw new CfgException(
                $instruction->opcode . ' at ' . $instruction->index . ' has jump_target '
                . $instruction->jumpTarget . ' and extended_value ' . $instruction->extendedValue
            );
        }

        return new Successor($kind, $instruction->extendedValue);
    }

    /**
     * @return list<Successor>
     */
    private function switchSuccessors(OpArray $opArray, Instruction $instruction, bool $typeFallback): array
    {
        $operand = $instruction->op2;
        if ($operand->kind !== 'const' || !$operand->jumpTable || $operand->index === null) {
            throw new CfgException($instruction->opcode . ' at ' . $instruction->index . ' has no jump table');
        }
        $literal = $opArray->literal($operand->index);
        if ($literal === null || $literal->type !== 'array' || $literal->recursive) {
            throw new CfgException($instruction->opcode . ' at ' . $instruction->index . ' jump table is not an array');
        }

        $successors = [];
        foreach ($literal->elements as $element) {
            $target = $element['value'];
            $key = $element['key'];
            if (!$target instanceof Literal || !is_int($target->value)) {
                throw new CfgException($instruction->opcode . ' jump table value is not an opline');
            }
            $case = $key instanceof Literal ? $key->value : null;
            $successors[] = new Successor('switch', $target->value, $case);
        }

        $successors[] = $this->extendedJump($instruction, 'default');
        if ($typeFallback) {
            $successors[] = $this->step($instruction, 'fallthrough', $instruction->index + 1);
        }

        return $successors;
    }

    private function breakContinue(OpArray $opArray, Instruction $instruction, bool $break): Successor
    {
        if ($opArray->breaks === null) {
            throw new IncompleteMetadataException(
                $instruction->opcode . ' at ' . $instruction->index . ' needs the breaks table, and it is null'
            );
        }
        if ($instruction->op1->kind !== 'level' || $instruction->op1->value === null) {
            throw new CfgException($instruction->opcode . ' at ' . $instruction->index . ' has no breaks index');
        }
        if ($instruction->op2->value === null || $instruction->op2->value < 1) {
            throw new CfgException($instruction->opcode . ' at ' . $instruction->index . ' has no positive depth');
        }

        $slot = $instruction->op1->value;
        $depth = $instruction->op2->value;
        for ($remaining = $depth; $remaining > 1; $remaining--) {
            $row = $opArray->breaks[$slot] ?? null;
            if ($row === null || $row->parent < 0) {
                throw new CfgException(
                    $instruction->opcode . ' at ' . $instruction->index . ' walks off the breaks table'
                );
            }
            $slot = $row->parent;
        }

        $row = $opArray->breaks[$slot] ?? null;
        if ($row === null) {
            throw new CfgException($instruction->opcode . ' at ' . $instruction->index . ' breaks index is out of range');
        }

        return new Successor($break ? 'break' : 'continue', $break ? $row->breakTarget : $row->continueTarget);
    }

    private function goto(OpArray $opArray, Instruction $instruction): Successor
    {
        if ($opArray->labels === null) {
            throw new IncompleteMetadataException(
                'GOTO at ' . $instruction->index . ' needs the labels table, and it is null'
            );
        }
        if ($instruction->op2->kind !== 'const' || $instruction->op2->index === null) {
            throw new CfgException('GOTO at ' . $instruction->index . ' has no label constant');
        }
        $literal = $opArray->literal($instruction->op2->index);
        if ($literal === null || $literal->type !== 'string' || !is_string($literal->value)) {
            throw new CfgException('GOTO at ' . $instruction->index . ' label is not a string');
        }

        $found = null;
        foreach ($opArray->labels as $label) {
            if ($label->name === $literal->value) {
                if ($found !== null) {
                    throw new CfgException('GOTO label ' . $literal->value . ' is defined more than once');
                }
                $found = $label;
            }
        }
        if ($found === null) {
            throw new CfgException('GOTO at ' . $instruction->index . ' has no label named ' . $literal->value);
        }

        return new Successor('goto', $found->opline);
    }

    private function fastCall(OpArray $opArray, Instruction $instruction): Successor
    {
        if ($instruction->op1->kind !== 'try_catch' || $instruction->op1->index === null) {
            throw new CfgException('FAST_CALL at ' . $instruction->index . ' has no try/catch index');
        }
        $region = $opArray->tryCatch[$instruction->op1->index] ?? null;
        if ($region === null || !$region->hasFinally() || $region->finally === null) {
            throw new CfgException('FAST_CALL at ' . $instruction->index . ' does not point at a finally handler');
        }

        return new Successor('finally_call', $region->finally);
    }

    /**
     * @return list<Successor>
     */
    private function fastReturn(OpArray $opArray, Instruction $instruction): array
    {
        $regionIndex = null;
        foreach ($opArray->tryCatch as $index => $region) {
            if ($region->finallyEnd === $instruction->index) {
                $regionIndex = $index;
                break;
            }
        }
        if ($regionIndex === null) {
            throw new IncompleteMetadataException(
                'FAST_RET at ' . $instruction->index . ' is not a try/catch finally_end'
            );
        }

        $successors = [];
        foreach ($opArray->instructions as $candidate) {
            if ($candidate->opcode !== 'FAST_CALL' || $candidate->op1->index !== $regionIndex) {
                continue;
            }
            $successors[] = new Successor('finally_return', $candidate->index + 1);
        }
        if ($successors === []) {
            throw new IncompleteMetadataException(
                'FAST_RET at ' . $instruction->index . ' has no FAST_CALL back to this finally'
            );
        }

        return $successors;
    }

    /**
     * @return list<Successor>
     */
    private function catch(Instruction $instruction, int $next): array
    {
        $last = ($instruction->extendedValue & self::LAST_CATCH) !== 0;
        if ($last) {
            return [$this->step($instruction, 'fallthrough', $next)];
        }
        if ($instruction->op2->kind !== 'jump') {
            throw new CfgException('CATCH at ' . $instruction->index . ' is not the last catch and has no jump');
        }

        return [
            $this->step($instruction, 'fallthrough', $next),
            $this->jump($instruction, $instruction->op2, 'catch_next'),
        ];
    }
}
