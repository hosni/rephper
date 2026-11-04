<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Expr;

use PHPUnit\Framework\TestCase;
use Rephper\Cfg\Builder;
use Rephper\Expr\Argument;
use Rephper\Expr\Assignment;
use Rephper\Expr\Binary;
use Rephper\Expr\ExpressionException;
use Rephper\Expr\FunctionCall;
use Rephper\Expr\Literal;
use Rephper\Expr\Opaque;
use Rephper\Expr\Reconstructor;
use Rephper\Expr\ReturnValue;
use Rephper\Expr\Unary;
use Rephper\Expr\Unresolved;
use Rephper\Expr\VariableRead;
use Rephper\Ir\Document;
use Rephper\Ir\OpArray;
use Rephper\Ir\Reader;

final class ReconstructorTest extends TestCase
{
    public function testValuesKeepTheirLiteralTypes(): void
    {
        $expressions = $this->reconstruct('values')->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);
        $this->assertEquals('a', $this->assignment($expressions, 0)->variable);
        $this->assertLiteral($this->assignment($expressions, 0)->value, 'int', 10);
        $this->assertLiteral($this->assignment($expressions, 1)->value, 'int', 20);
        $this->assertLiteral($this->assignment($expressions, 2)->value, 'null', null);
        $this->assertLiteral($this->assignment($expressions, 3)->value, 'bool', true);
        $return = $expressions[4];
        $this->assertInstanceOf(ReturnValue::class, $return);
        $this->assertLiteral($return->value, 'null', null);
    }

    public function testArithmeticUsesTheCompiledOperators(): void
    {
        $expressions = $this->reconstruct('arithmetic')->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);
        $this->assertBinary($this->assignmentNamed($expressions, 'result', 0)->value, '+', '$a', '$b');
        $this->assertBinary($this->assignmentNamed($expressions, 'result', 1)->value, '-', '$a', '$b');
        $this->assertBinary($this->assignmentNamed($expressions, 'result', 2)->value, '*', '$a', '$b');
        $this->assertBinary($this->assignmentNamed($expressions, 'result', 3)->value, '/', '$a', '$b');
        $this->assertBinary($this->assignmentNamed($expressions, 'result', 4)->value, '%', '$a', '$b');
        $this->assertBinary($this->assignmentNamed($expressions, 'result', 5)->value, '*', '$a', 'int:-1');
    }

    public function testPrecedenceFollowsTheCompiledGrouping(): void
    {
        $expressions = $this->reconstruct('precedence')->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);
        $first = $this->assignmentNamed($expressions, 'result', 0)->value;
        $second = $this->assignmentNamed($expressions, 'result', 1)->value;
        $this->assertInstanceOf(Binary::class, $first);
        $this->assertSame('+', $first->operator);
        $this->assertVariable($first->left, 'a');
        $this->assertBinary($first->right, '*', '$b', '$c');
        $this->assertInstanceOf(Binary::class, $second);
        $this->assertSame('*', $second->operator);
        $this->assertBinary($second->left, '+', '$a', '$b');
        $this->assertVariable($second->right, 'c');
    }

    public function testStringConcatenationKeepsTheLiteralOnTheLeft(): void
    {
        $expressions = $this->reconstruct('strings')->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);
        $this->assertLiteral($this->assignmentNamed($expressions, 'name', 0)->value, 'string', 'John');
        $this->assertBinary($this->assignmentNamed($expressions, 'message', 0)->value, '.', 'string:"Hello "', '$name');
    }

    public function testComparisonsKeepCompiledOperandOrder(): void
    {
        $expressions = $this->reconstruct('comparisons')->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);
        $this->assertBinary($this->assignmentNamed($expressions, 'result', 0)->value, '===', '$a', '$b');
        $this->assertBinary($this->assignmentNamed($expressions, 'result', 1)->value, '!==', '$a', '$b');
        $this->assertBinary($this->assignmentNamed($expressions, 'result', 2)->value, '==', '$a', '$b');
        $this->assertBinary($this->assignmentNamed($expressions, 'result', 3)->value, '!=', '$a', '$b');
        $this->assertBinary($this->assignmentNamed($expressions, 'result', 4)->value, '<', '$a', '$b');
        $this->assertBinary($this->assignmentNamed($expressions, 'result', 5)->value, '<=', '$b', '$a');
    }

    public function testBooleanNotIsUnaryAndShortCircuitStaysInSeparateBlocks(): void
    {
        $result = $this->reconstruct('booleans');
        $not = $result->blockStartingAt(0)?->expressions;
        $this->assertNotNull($not);
        $assignment = $this->assignmentNamed($not, 'result', 0);
        $this->assertInstanceOf(Unary::class, $assignment->value);
        $this->assertSame('!', $assignment->value->operator);
        $this->assertVariable($assignment->value->operand, 'a');

        $cast = $result->blockStartingAt(5)?->expressions[0] ?? null;
        $this->assertInstanceOf(Unary::class, $cast);
        $this->assertSame('bool', $cast->operator);
        $this->assertVariable($cast->operand, 'b');

        $join = $result->blockStartingAt(6)?->expressions[0] ?? null;
        $this->assertInstanceOf(Assignment::class, $join);
        $this->assertInstanceOf(Unresolved::class, $join->value);
        $this->assertSame('tmp', $join->value->kind);
        $this->assertSame(2, $join->value->index);

        $kinds = array_map(static fn ($diagnostic) => $diagnostic->kind . ' ' . $diagnostic->opcode, $result->diagnostics);
        $this->assertContains('unsupported JMPZ_EX', $kinds);
        $this->assertContains('unsupported JMPNZ_EX', $kinds);
        $this->assertContains('unresolved ASSIGN', $kinds);
        foreach ($result->diagnostics as $diagnostic) {
            $this->assertNotSame('', $diagnostic->blockId);
            $this->assertGreaterThanOrEqual(0, $diagnostic->line);
        }
    }

    public function testNestedAdditionAndCompoundAssignment(): void
    {
        $expressions = $this->reconstruct('assignments')->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);
        $total = $this->assignmentNamed($expressions, 'total', 0)->value;
        $this->assertInstanceOf(Binary::class, $total);
        $this->assertSame('+', $total->operator);
        $this->assertBinary($total->left, '*', '$price', '$quantity');
        $this->assertVariable($total->right, 'tax');

        $compound = $this->assignmentNamed($expressions, 'count', 1);
        $this->assertSame('+', $compound->operator);
        $this->assertLiteral($compound->value, 'int', 1);
    }

    public function testReturnCarriesTheMultiplicationAndAVoidReturnIsNull(): void
    {
        $value = $this->reconstruct('returnsValue')->blockStartingAt(0)?->expressions;
        $this->assertNotNull($value);
        $return = $value[array_key_last($value)];
        $this->assertInstanceOf(ReturnValue::class, $return);
        $this->assertBinary($return->value, '*', '$price', '$quantity');

        $nothing = $this->reconstruct('returnsNothing')->blockStartingAt(0)?->expressions[0] ?? null;
        $this->assertInstanceOf(ReturnValue::class, $nothing);
        $this->assertLiteral($nothing->value, 'null', null);
    }

    public function testStrlenStaysInsideTheAdditionInOpcodeOrder(): void
    {
        $result = $this->reconstruct('callThenAdd');
        $expressions = $result->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);
        $return = $expressions[array_key_last($expressions)];
        $this->assertInstanceOf(ReturnValue::class, $return);
        $this->assertInstanceOf(Binary::class, $return->value);
        $this->assertSame('+', $return->value->operator);
        $this->assertInstanceOf(Opaque::class, $return->value->left);
        $this->assertSame('STRLEN', $return->value->left->opcode);
        $this->assertSame('cv', $return->value->left->op1->kind);
        $this->assertSame('text', $return->value->left->op1->name);
        $this->assertInstanceOf(VariableRead::class, $return->value->left->op1->resolved);
        $this->assertSame('text', $return->value->left->op1->resolved->name);
        $this->assertSame('unused', $return->value->left->op2->kind);
        $this->assertNull($return->value->left->op2->resolved);
        $this->assertSame('tmp', $return->value->left->result->kind);
        $this->assertSame(0, $return->value->left->result->index);
        $this->assertNull($return->value->left->result->resolved);
        $this->assertVariable($return->value->right, 'n');
        $this->assertLessThan($return->value->opline, $return->value->left->opline);
        $this->assertSame('unsupported', $result->diagnostics[0]->kind);
        $this->assertSame(2, $result->diagnostics[0]->opline);
        $this->assertSame('STRLEN', $result->diagnostics[0]->opcode);
    }

    public function testBitwiseNotIsUnaryAndSignedMultiplicationStaysBinary(): void
    {
        $expressions = $this->reconstruct('signs')->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);
        $this->assertBinary($this->assignmentNamed($expressions, 'minus', 0)->value, '*', '$a', 'int:-1');
        $this->assertBinary($this->assignmentNamed($expressions, 'plus', 0)->value, '*', '$a', 'int:1');
        $bits = $this->assignmentNamed($expressions, 'bits', 0)->value;
        $this->assertInstanceOf(Unary::class, $bits);
        $this->assertSame('~', $bits->operator);
        $this->assertVariable($bits->operand, 'a');
    }

    public function testAnUnsupportedOpcodeKeepsItsOneOperand(): void
    {
        $echo = $this->opaqueNamed($this->opaqueForms(), 'ECHO', 0);

        $this->assertSame(3, $echo->opline);
        $this->assertSame(136, $echo->opcodeNumber);
        $this->assertSame('cv', $echo->op1->kind);
        $this->assertSame(0, $echo->op1->index);
        $this->assertSame('text', $echo->op1->name);
        $this->assertInstanceOf(VariableRead::class, $echo->op1->resolved);
        $this->assertSame('text', $echo->op1->resolved->name);
        $this->assertSame(0, $echo->op1->resolved->index);
        $this->assertSame('unused', $echo->op2->kind);
        $this->assertNull($echo->op2->resolved);
        $this->assertSame('unused', $echo->result->kind);
        $this->assertNull($echo->result->resolved);
    }

    public function testAnUnsupportedOpcodeKeepsBothOperands(): void
    {
        $echo = $this->opaqueNamed($this->opaqueForms(), 'ECHO', 1);
        $fetch = $echo->op1->resolved;
        $this->assertInstanceOf(Opaque::class, $fetch);
        $this->assertSame('FETCH_DIM_R', $fetch->opcode);
        $this->assertSame(81, $fetch->opcodeNumber);
        $this->assertSame('cv', $fetch->op1->kind);
        $this->assertSame('items', $fetch->op1->name);
        $this->assertInstanceOf(VariableRead::class, $fetch->op1->resolved);
        $this->assertSame('items', $fetch->op1->resolved->name);
        $this->assertSame('cv', $fetch->op2->kind);
        $this->assertSame('index', $fetch->op2->name);
        $this->assertInstanceOf(VariableRead::class, $fetch->op2->resolved);
        $this->assertSame('index', $fetch->op2->resolved->name);
        $this->assertSame('tmp', $fetch->result->kind);
        $this->assertSame(0, $fetch->result->index);
        $this->assertNull($fetch->result->resolved);
    }

    public function testAnUnsupportedOpcodeConsumesATemporary(): void
    {
        $expressions = $this->opaqueForms();
        $echo = $this->opaqueNamed($expressions, 'ECHO', 3);
        $concat = $echo->op1->resolved;

        $this->assertSame('tmp', $echo->op1->kind);
        $this->assertSame(2, $echo->op1->index);
        $this->assertNull($echo->op1->name);
        $this->assertInstanceOf(Binary::class, $concat);
        $this->assertSame('.', $concat->operator);
        $this->assertVariable($concat->left, 'text');
        $this->assertVariable($concat->right, 'text');
        $this->assertNotSame($concat->left, $concat->right);
        foreach ($expressions as $expression) {
            $this->assertNotSame($concat, $expression);
        }
    }

    public function testAnUnsupportedOpcodeConsumesAnOpaqueOperand(): void
    {
        $expressions = $this->opaqueForms();
        $echo = $this->opaqueNamed($expressions, 'ECHO', 2);
        $strlen = $echo->op1->resolved;

        $this->assertSame('tmp', $echo->op1->kind);
        $this->assertSame(1, $echo->op1->index);
        $this->assertInstanceOf(Opaque::class, $strlen);
        $this->assertSame('STRLEN', $strlen->opcode);
        $this->assertSame(121, $strlen->opcodeNumber);
        $this->assertSame('cv', $strlen->op1->kind);
        $this->assertSame('text', $strlen->op1->name);
        $this->assertInstanceOf(VariableRead::class, $strlen->op1->resolved);
        $this->assertSame('text', $strlen->op1->resolved->name);
        $this->assertLessThan($echo->opline, $strlen->opline);
        foreach ($expressions as $expression) {
            $this->assertNotSame($strlen, $expression);
        }
    }

    public function testAnUnsupportedOpcodeKeepsInstructionMetadata(): void
    {
        $expressions = $this->opaqueForms();
        $echo = $this->opaqueNamed($expressions, 'ECHO', 1);
        $fetch = $echo->op1->resolved;

        $this->assertInstanceOf(Opaque::class, $fetch);
        $this->assertSame('FETCH_DIM_R', $fetch->opcode);
        $this->assertSame(81, $fetch->opcodeNumber);
        $this->assertSame(0, $fetch->extendedValue);
        $this->assertNull($fetch->jumpTarget);
        $this->assertSame([], $fetch->extra);
        $this->assertSame('cv', $fetch->op1->kind);
        $this->assertSame(1, $fetch->op1->index);
        $this->assertSame('items', $fetch->op1->name);
        $this->assertInstanceOf(VariableRead::class, $fetch->op1->resolved);
        $this->assertSame('cv', $fetch->op2->kind);
        $this->assertSame('index', $fetch->op2->name);
        $this->assertInstanceOf(VariableRead::class, $fetch->op2->resolved);
        $this->assertSame('tmp', $fetch->result->kind);
        $this->assertSame(0, $fetch->result->index);
        $this->assertNull($fetch->result->resolved);

        $document = $this->mutated(function (array $document): array {
            foreach ($document['op_arrays'] as &$opArray) {
                if (($opArray['name'] ?? null) !== 'opaqueForms') {
                    continue;
                }
                foreach ($opArray['instructions'] as &$instruction) {
                    if ($instruction['opcode'] === 'FETCH_DIM_R') {
                        $instruction['jump_target'] = 12;
                        $instruction['trace'] = ['kept' => true];
                        $instruction['op2']['note'] = 'dimension';
                    }
                }
            }

            return $document;
        });
        $roots = $this->reconstruct('opaqueForms', $document)->blockStartingAt(0)?->expressions ?? [];
        $kept = $this->opaqueNamed($roots, 'ECHO', 1)->op1->resolved;
        $this->assertInstanceOf(Opaque::class, $kept);
        $this->assertSame(12, $kept->jumpTarget);
        $this->assertSame(['kept' => true], $kept->extra['trace'] ?? null);
        $this->assertSame('dimension', $kept->op2->extra['note'] ?? null);
        $this->assertInstanceOf(VariableRead::class, $kept->op2->resolved);
        $this->assertSame('index', $kept->op2->resolved->name);
    }

    public function testAByReferenceCallBecomesOneFunctionCall(): void
    {
        $result = $this->reconstruct('opaqueForms');
        $expressions = $result->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);
        $call = null;
        foreach ($expressions as $expression) {
            if ($expression instanceof FunctionCall) {
                $call = $expression;
            }
        }
        $this->assertInstanceOf(FunctionCall::class, $call);
        $this->assertSame('sort', $call->name);
        $this->assertSame('INIT_FCALL', $call->initOpcode);
        $this->assertSame(1, $call->initExtendedValue);
        $this->assertSame('DO_ICALL', $call->executeOpcode);
        $this->assertSame(0, $call->executeExtendedValue);
        $this->assertFalse($call->checksUndefinedArguments);
        $this->assertLessThan($call->opline, $call->initOpline);
        $this->assertCount(1, $call->arguments);
        $argument = $call->arguments[0];
        $this->assertSame('SEND_REF', $argument->opcode);
        $this->assertSame(Argument::REFERENCE, $argument->mode);
        $this->assertFalse($argument->unpack);
        $this->assertNull($argument->name);
        $this->assertSame(1, $argument->position);
        $this->assertInstanceOf(VariableRead::class, $argument->value);
        $this->assertSame('items', $argument->value->name);
        $this->assertLessThan($argument->opline, $call->initOpline);
        $this->assertLessThan($call->opline, $argument->opline);
        $opcodes = array_map(static fn ($diagnostic) => $diagnostic->opcode, $result->diagnostics);
        $this->assertNotContains('INIT_FCALL', $opcodes);
        $this->assertNotContains('SEND_REF', $opcodes);
        $this->assertNotContains('DO_ICALL', $opcodes);
    }

    public function testACallFeedsStrlenAndTheAdditionKeepsItsArgument(): void
    {
        $result = $this->reconstruct('calledValue');
        $expressions = $result->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);
        $return = $expressions[array_key_last($expressions)];

        $this->assertInstanceOf(ReturnValue::class, $return);
        $this->assertInstanceOf(Binary::class, $return->value);
        $this->assertSame('+', $return->value->operator);
        $this->assertVariable($return->value->right, 'n');
        $strlen = $return->value->left;
        $this->assertInstanceOf(Opaque::class, $strlen);
        $this->assertSame('STRLEN', $strlen->opcode);
        $this->assertSame('var', $strlen->op1->kind);
        $this->assertSame(0, $strlen->op1->index);
        $call = $strlen->op1->resolved;
        $this->assertInstanceOf(FunctionCall::class, $call);
        $this->assertSame('strtolower', $call->name);
        $this->assertSame('DO_ICALL', $call->executeOpcode);
        $this->assertSame('INIT_FCALL', $call->initOpcode);
        $this->assertCount(1, $call->arguments);
        $this->assertSame('SEND_VAR', $call->arguments[0]->opcode);
        $this->assertInstanceOf(VariableRead::class, $call->arguments[0]->value);
        $this->assertSame('text', $call->arguments[0]->value->name);
        $this->assertSame($call, $strlen->op1->resolved);
        $this->assertLessThan($call->arguments[0]->opline, $call->initOpline);
        $this->assertLessThan($call->opline, $call->arguments[0]->opline);
        $this->assertLessThan($strlen->opline, $call->opline);
        $this->assertLessThan($return->value->opline, $strlen->opline);
        $this->assertLessThan($return->opline, $return->value->opline);
        $opcodes = array_map(static fn ($diagnostic) => $diagnostic->opcode, $result->diagnostics);
        $this->assertNotContains('INIT_FCALL', $opcodes);
        $this->assertNotContains('SEND_VAR', $opcodes);
        $this->assertNotContains('DO_ICALL', $opcodes);
        foreach ($expressions as $expression) {
            $this->assertNotSame($call, $expression);
            $this->assertNotSame($strlen, $expression);
        }
    }

    public function testASideEffectingCallKeepsTheArgumentAndTheEcho(): void
    {
        $result = $this->reconstruct('calledSideEffect');
        $expressions = $result->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);
        $echo = $this->opaqueNamed($expressions, 'ECHO', 0);
        $call = $echo->op1->resolved;

        $this->assertSame('var', $echo->op1->kind);
        $this->assertSame(1, $echo->op1->index);
        $this->assertSame('unused', $echo->result->kind);
        $this->assertNull($echo->result->resolved);
        $this->assertInstanceOf(FunctionCall::class, $call);
        $this->assertSame('strtolower', $call->name);
        $this->assertSame('DO_ICALL', $call->executeOpcode);
        $this->assertCount(1, $call->arguments);
        $this->assertSame('SEND_VAL', $call->arguments[0]->opcode);
        $concat = $call->arguments[0]->value;
        $this->assertInstanceOf(Binary::class, $concat);
        $this->assertSame('.', $concat->operator);
        $this->assertVariable($concat->left, 'text');
        $this->assertVariable($concat->right, 'n');
        $this->assertSame($call, $echo->op1->resolved);
        $this->assertLessThan($concat->opline, $call->initOpline);
        $this->assertLessThan($call->arguments[0]->opline, $concat->opline);
        $this->assertLessThan($call->opline, $call->arguments[0]->opline);
        $this->assertLessThan($echo->opline, $call->opline);
        $opcodes = array_map(static fn ($diagnostic) => $diagnostic->opcode, $result->diagnostics);
        $this->assertNotContains('INIT_FCALL', $opcodes);
        $this->assertNotContains('SEND_VAL', $opcodes);
        $this->assertNotContains('DO_ICALL', $opcodes);
        foreach ($expressions as $expression) {
            $this->assertNotSame($concat, $expression);
            $this->assertNotSame($call, $expression);
        }
    }

    public function testATemporaryUsedTwiceIsTheSameExpression(): void
    {
        $document = $this->mutated(function (array $document): array {
            foreach ($document['op_arrays'] as &$opArray) {
                if (($opArray['name'] ?? null) !== 'arithmetic') {
                    continue;
                }
                $opArray['instructions'][4]['op1'] = ['kind' => 'tmp', 'index' => 0];
            }

            return $document;
        });
        $expressions = $this->reconstruct('arithmetic', $document)->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);
        $first = $this->assignmentNamed($expressions, 'result', 0)->value;
        $second = $this->assignmentNamed($expressions, 'result', 1)->value;
        $this->assertInstanceOf(Binary::class, $first);
        $this->assertInstanceOf(Binary::class, $second);
        $this->assertSame($first, $second->left);
    }

    public function testAConstIndexOutsideTheLiteralTableIsInvalid(): void
    {
        $document = $this->mutated(function (array $document): array {
            foreach ($document['op_arrays'] as &$opArray) {
                if (($opArray['name'] ?? null) === 'values') {
                    $opArray['instructions'][0]['op2']['index'] = 99;
                }
            }

            return $document;
        });

        $this->expectException(ExpressionException::class);
        $this->expectExceptionMessage('outside the literal table');
        $this->reconstruct('values', $document);
    }

    public function testAGraphForAnotherOpArrayIsRejected(): void
    {
        $document = $this->document();
        $values = $document->opArrayNamed('values');
        $arithmetic = $document->opArrayNamed('arithmetic');
        $this->assertInstanceOf(OpArray::class, $values);
        $this->assertInstanceOf(OpArray::class, $arithmetic);

        $this->expectException(ExpressionException::class);
        (new Reconstructor())->reconstruct($values, (new Builder())->build($arithmetic));
    }

    private function reconstruct(string $name, ?Document $document = null): \Rephper\Expr\Reconstruction
    {
        $document ??= $this->document();
        $opArray = $document->opArrayNamed($name);
        $this->assertInstanceOf(OpArray::class, $opArray);
        $graph = null;
        foreach ((new Builder())->buildDocument($document) as $candidate) {
            if ($candidate->name === $name) {
                $graph = $candidate;
            }
        }
        $this->assertNotNull($graph);

        return (new Reconstructor())->reconstruct($opArray, $graph);
    }

    private function document(): Document
    {
        $json = (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Expressions.json');
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);

        return (new Reader())->readString($json);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    private function mutated(callable $change): Document
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Expressions.json'), true);
        $this->assertIsArray($decoded);
        $decoded['php']['version'] = '8.4.27';
        $decoded['php']['version_id'] = 80427;

        return (new Reader())->readString((string) json_encode($change($decoded)));
    }

    /**
     * @param list<\Rephper\Expr\Expression> $expressions
     */
    private function assignment(array $expressions, int $index): Assignment
    {
        $expression = $expressions[$index];
        $this->assertInstanceOf(Assignment::class, $expression);

        return $expression;
    }

    /**
     * @param list<\Rephper\Expr\Expression> $expressions
     */
    private function assignmentNamed(array $expressions, string $name, int $occurrence): Assignment
    {
        $seen = 0;
        foreach ($expressions as $expression) {
            if ($expression instanceof Assignment && $expression->variable === $name) {
                if ($seen === $occurrence) {
                    return $expression;
                }
                $seen++;
            }
        }

        $this->fail('Missing assignment to ' . $name);

        return new Assignment(0, 0, '', new Literal(0, 0, 'null', null), null);
    }

    /**
     * @return list<\Rephper\Expr\Expression>
     */
    private function opaqueForms(): array
    {
        $expressions = $this->reconstruct('opaqueForms')->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);

        return $expressions;
    }

    /**
     * @param list<\Rephper\Expr\Expression> $expressions
     */
    private function opaqueNamed(array $expressions, string $opcode, int $occurrence): Opaque
    {
        $seen = 0;
        foreach ($expressions as $expression) {
            if ($expression instanceof Opaque && $expression->opcode === $opcode) {
                if ($seen === $occurrence) {
                    return $expression;
                }
                $seen++;
            }
        }

        $this->fail('Missing opaque ' . $opcode);
    }

    private function assertLiteral(mixed $expression, string $type, mixed $value): void
    {
        $this->assertInstanceOf(Literal::class, $expression);
        $this->assertSame($type, $expression->type);
        $this->assertSame($value, $expression->value);
    }

    private function assertVariable(mixed $expression, string $name): void
    {
        $this->assertInstanceOf(VariableRead::class, $expression);
        $this->assertSame($name, $expression->name);
    }

    private function assertBinary(mixed $expression, string $operator, string $left, string $right): void
    {
        $this->assertInstanceOf(Binary::class, $expression);
        $this->assertSame($operator, $expression->operator);
        $this->assertSame($left, $this->text($expression->left));
        $this->assertSame($right, $this->text($expression->right));
    }

    private function text(mixed $expression): string
    {
        if ($expression instanceof VariableRead) {
            return '$' . $expression->name;
        }
        if ($expression instanceof Literal) {
            return $expression->type . ':' . json_encode($expression->value);
        }

        return '';
    }
}
