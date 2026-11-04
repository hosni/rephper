<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Expr;

use PHPUnit\Framework\TestCase;
use Rephper\Cfg\Builder;
use Rephper\Expr\Argument;
use Rephper\Expr\Assignment;
use Rephper\Expr\Binary;
use Rephper\Expr\Expression;
use Rephper\Expr\FunctionCall;
use Rephper\Expr\Literal;
use Rephper\Expr\Opaque;
use Rephper\Expr\Reconstructor;
use Rephper\Expr\ReturnValue;
use Rephper\Expr\VariableRead;
use Rephper\Ir\Document;
use Rephper\Ir\OpArray;
use Rephper\Ir\Reader;
use Rephper\Stmt\ExpressionStatement;
use Rephper\Stmt\Reconstructor as StatementReconstructor;
use Rephper\Stmt\ReturnStatement;

final class FunctionCallTest extends TestCase
{
    public function testASimpleNamedCallIsOneExpression(): void
    {
        $result = $this->reconstruct('sideEffect');
        $expressions = $this->roots('sideEffect');
        $call = $this->rootCall($expressions);

        $this->assertSame('strtolower', $call->name);
        $this->assertSame('INIT_FCALL', $call->initOpcode);
        $this->assertSame(1, $call->initOpline);
        $this->assertSame(1, $call->initExtendedValue);
        $this->assertSame('DO_ICALL', $call->executeOpcode);
        $this->assertSame(3, $call->opline);
        $this->assertSame(0, $call->executeExtendedValue);
        $this->assertSame(104, $call->line);
        $this->assertFalse($call->checksUndefinedArguments);
        $this->assertCount(1, $call->arguments);
        $this->assertArgument($call->arguments[0], 'SEND_VAR', Argument::VALUE, false, null, 1);
        $this->assertVariable($call->arguments[0]->value, 'text');
        $this->assertLessThan($call->arguments[0]->opline, $call->initOpline);
        $this->assertLessThan($call->opline, $call->arguments[0]->opline);
        $this->assertSame([], $this->opcodes($result->diagnostics));
        $this->assertNoOpaqueCall($expressions);
    }

    public function testMultipleVariableArgumentsStayInOrder(): void
    {
        $call = $this->returnedCall('returned');

        $this->assertSame('calculate', $call->name);
        $this->assertSame('DO_UCALL', $call->executeOpcode);
        $this->assertSame(2, $call->initExtendedValue);
        $this->assertCount(2, $call->arguments);
        $this->assertArgument($call->arguments[0], 'SEND_VAR', Argument::VALUE, false, null, 1);
        $this->assertArgument($call->arguments[1], 'SEND_VAR', Argument::VALUE, false, null, 2);
        $this->assertVariable($call->arguments[0]->value, 'price');
        $this->assertVariable($call->arguments[1]->value, 'quantity');
        $this->assertLessThan($call->arguments[1]->opline, $call->arguments[0]->opline);
        $this->assertLessThan($call->opline, $call->arguments[1]->opline);
        $this->assertNotSame($call->arguments[0]->value, $call->arguments[1]->value);
    }

    public function testLiteralArgumentsStayLiterals(): void
    {
        $call = $this->returnedCall('literals');

        $this->assertCount(2, $call->arguments);
        $this->assertArgument($call->arguments[0], 'SEND_VAL', Argument::VALUE, false, null, 1);
        $this->assertArgument($call->arguments[1], 'SEND_VAL', Argument::VALUE, false, null, 2);
        $this->assertLiteral($call->arguments[0]->value, 'int', 10);
        $this->assertLiteral($call->arguments[1]->value, 'int', 20);
        $this->assertSame(34, $call->arguments[0]->line);
        $this->assertSame($call->line, $call->arguments[1]->line);
    }

    public function testAnExpressionArgumentKeepsItsTreeAndIsNotARoot(): void
    {
        $expressions = $this->roots('expressionArg');
        $call = $this->userReturn($expressions)->value;
        $this->assertInstanceOf(FunctionCall::class, $call);
        $sum = $call->arguments[0]->value;

        $this->assertInstanceOf(Binary::class, $sum);
        $this->assertSame('+', $sum->operator);
        $this->assertVariable($sum->left, 'a');
        $this->assertVariable($sum->right, 'b');
        $this->assertArgument($call->arguments[1], 'SEND_VAR', Argument::VALUE, false, null, 2);
        $this->assertVariable($call->arguments[1]->value, 'c');
        $this->assertLessThan($sum->opline, $call->initOpline);
        $this->assertLessThan($call->arguments[0]->opline, $sum->opline);
        $this->assertLessThan($call->opline, $call->arguments[1]->opline);
        $this->assertSame($call, $this->verified($expressions));
        foreach ($expressions as $expression) {
            $this->assertNotSame($sum, $expression);
            $this->assertNotSame($call, $expression);
        }
    }

    public function testANestedCallIsTheSameObjectAsTheOuterArgument(): void
    {
        $expressions = $this->roots('nested');
        $outer = $this->userReturn($expressions)->value;
        $this->assertInstanceOf(FunctionCall::class, $outer);
        $inner = $outer->arguments[0]->value;

        $this->assertInstanceOf(FunctionCall::class, $inner);
        $this->assertSame('calculate', $inner->name);
        $this->assertSame('calculate', $outer->name);
        $this->assertSame(1, $inner->initExtendedValue);
        $this->assertSame(2, $outer->initExtendedValue);
        $this->assertCount(1, $inner->arguments);
        $this->assertVariable($inner->arguments[0]->value, 'a');
        $this->assertVariable($outer->arguments[1]->value, 'b');
        $this->assertSame($inner, $outer->arguments[0]->value);
        $this->assertLessThan($inner->initOpline, $outer->initOpline);
        $this->assertLessThan($inner->opline, $inner->arguments[0]->opline);
        $this->assertLessThan($outer->arguments[0]->opline, $inner->opline);
        $this->assertLessThan($outer->opline, $outer->arguments[1]->opline);
        $this->assertNotSame($inner, $outer);
        foreach ($expressions as $expression) {
            $this->assertNotSame($inner, $expression);
            $this->assertNotSame($outer, $expression);
        }
    }

    public function testAnAssignedCallIsTheAssignmentValue(): void
    {
        [$expressions, $statements] = $this->both('assigned');
        $assignment = null;
        foreach ($expressions->blockStartingAt(0)?->expressions ?? [] as $expression) {
            if ($expression instanceof Assignment) {
                $assignment = $expression;
            }
        }
        $this->assertInstanceOf(Assignment::class, $assignment);
        $this->assertSame('result', $assignment->variable);
        $this->assertInstanceOf(FunctionCall::class, $assignment->value);
        $this->assertSame('calculate', $assignment->value->name);
        $this->assertCount(1, $statements->blockStartingAt(0)?->statements);
        $statement = $statements->blockStartingAt(0)?->statements[0] ?? null;
        $this->assertInstanceOf(ExpressionStatement::class, $statement);
        $this->assertSame($assignment, $statement->expression);
        $this->assertSame([], $statements->diagnostics);
    }

    public function testAReturnedCallIsTheReturnValue(): void
    {
        [$expressions, $statements] = $this->both('returned');
        $roots = $expressions->blockStartingAt(0)?->expressions ?? [];
        $call = $this->userReturn($roots)->value;
        $this->assertInstanceOf(FunctionCall::class, $call);
        $this->assertSame('calculate', $call->name);
        $this->assertSame($call, $this->verified($roots));
        $this->assertCount(1, $statements->blockStartingAt(0)?->statements);
        $statement = $statements->blockStartingAt(0)?->statements[0] ?? null;
        $this->assertInstanceOf(ReturnStatement::class, $statement);
        $this->assertSame($call, $statement->value);
        $this->assertSame([], $statements->diagnostics);
    }

    public function testACallUsedByAdditionIsTheLeftOperand(): void
    {
        $expressions = $this->roots('added');
        $return = $this->userReturn($expressions);
        $sum = $return->value;

        $this->assertInstanceOf(Binary::class, $sum);
        $this->assertSame('+', $sum->operator);
        $this->assertInstanceOf(FunctionCall::class, $sum->left);
        $this->assertSame('calculate', $sum->left->name);
        $this->assertCount(2, $sum->left->arguments);
        $this->assertVariable($sum->left->arguments[0]->value, 'price');
        $this->assertVariable($sum->left->arguments[1]->value, 'quantity');
        $this->assertVariable($sum->right, 'tax');
        $this->assertSame($sum, $this->verified($expressions));
        $this->assertLessThan($sum->opline, $sum->left->opline);
        $this->assertLessThan($return->opline, $sum->opline);
        foreach ($expressions as $expression) {
            $this->assertNotSame($sum->left, $expression);
            $this->assertNotSame($sum, $expression);
        }
    }

    public function testACallResultUsedTwiceIsOneObject(): void
    {
        $document = $this->mutated(function (array $document): array {
            foreach ($document['op_arrays'] as &$opArray) {
                if (($opArray['name'] ?? null) !== 'added') {
                    continue;
                }
                $opArray['instructions'][7]['op2'] = ['kind' => 'var', 'index' => 0];
            }

            return $document;
        });
        $expressions = $this->roots('added', $document);
        $sum = $this->userReturn($expressions)->value;

        $this->assertInstanceOf(Binary::class, $sum);
        $this->assertInstanceOf(FunctionCall::class, $sum->left);
        $this->assertSame($sum->left, $sum->right);
    }

    public function testNamedArgumentsKeepNamesAndSendOrder(): void
    {
        $call = $this->rootCall($this->roots('namedArgs'));

        $this->assertSame('unknown_func', $call->name);
        $this->assertSame('INIT_FCALL_BY_NAME', $call->initOpcode);
        $this->assertSame('DO_FCALL_BY_NAME', $call->executeOpcode);
        $this->assertSame(0, $call->initExtendedValue);
        $this->assertSame(1, $call->executeExtendedValue);
        $this->assertTrue($call->checksUndefinedArguments);
        $this->assertCount(2, $call->arguments);
        $this->assertArgument($call->arguments[0], 'SEND_VAR_EX', Argument::VALUE, false, 'quantity', null);
        $this->assertArgument($call->arguments[1], 'SEND_VAR_EX', Argument::VALUE, false, 'price', null);
        $this->assertVariable($call->arguments[0]->value, 'quantity');
        $this->assertVariable($call->arguments[1]->value, 'price');
        $this->assertLessThan($call->arguments[1]->opline, $call->arguments[0]->opline);
    }

    public function testAKnownCalleeDropsANameTheCompilerRewrote(): void
    {
        $call = $this->returnedCall('rewrittenName');

        $this->assertSame('calculate', $call->name);
        $this->assertFalse($call->checksUndefinedArguments);
        $this->assertCount(2, $call->arguments);
        $this->assertArgument($call->arguments[0], 'SEND_VAL', Argument::VALUE, false, null, 1);
        $this->assertArgument($call->arguments[1], 'SEND_VAR', Argument::VALUE, false, null, 2);
        $this->assertLiteral($call->arguments[0]->value, 'int', 10);
        $this->assertVariable($call->arguments[1]->value, 'price');
        $this->assertNull($call->arguments[0]->name);
        $this->assertNull($call->arguments[1]->name);
    }

    public function testAPositionalArgumentCanBeFollowedByANamedArgument(): void
    {
        $call = $this->rootCall($this->roots('mixedArgs'));

        $this->assertSame('unknown_func', $call->name);
        $this->assertSame(1, $call->initExtendedValue);
        $this->assertTrue($call->checksUndefinedArguments);
        $this->assertCount(2, $call->arguments);
        $this->assertArgument($call->arguments[0], 'SEND_VAL_EX', Argument::VALUE, false, null, 1);
        $this->assertArgument($call->arguments[1], 'SEND_VAR_EX', Argument::VALUE, false, 'label', null);
        $this->assertLiteral($call->arguments[0]->value, 'int', 1);
        $this->assertVariable($call->arguments[1]->value, 'label');
    }

    public function testAnUnpackedArgumentStaysOneExpression(): void
    {
        $call = $this->rootCall($this->roots('unpacked'));

        $this->assertSame('calculate', $call->name);
        $this->assertSame(0, $call->initExtendedValue);
        $this->assertSame(0, $call->executeExtendedValue);
        $this->assertTrue($call->checksUndefinedArguments);
        $this->assertCount(1, $call->arguments);
        $this->assertArgument($call->arguments[0], 'SEND_UNPACK', Argument::VALUE, true, null, null);
        $this->assertVariable($call->arguments[0]->value, 'args');
    }

    public function testAPositionalArgumentCanBeFollowedByAnUnpack(): void
    {
        $call = $this->rootCall($this->roots('unpackedAfter'));

        $this->assertSame('unknown_func', $call->name);
        $this->assertSame('INIT_FCALL_BY_NAME', $call->initOpcode);
        $this->assertSame(1, $call->initExtendedValue);
        $this->assertTrue($call->checksUndefinedArguments);
        $this->assertCount(2, $call->arguments);
        $this->assertArgument($call->arguments[0], 'SEND_VAR_EX', Argument::VALUE, false, null, 1);
        $this->assertArgument($call->arguments[1], 'SEND_UNPACK', Argument::VALUE, true, null, null);
        $this->assertVariable($call->arguments[0]->value, 'price');
        $this->assertVariable($call->arguments[1]->value, 'args');
        $this->assertLessThan($call->arguments[1]->opline, $call->arguments[0]->opline);
    }

    public function testAByReferenceArgumentKeepsItsMode(): void
    {
        $internal = $this->rootCall($this->roots('referenced'));
        $user = $this->rootCall($this->roots('callsByReference'));

        $this->assertSame('sort', $internal->name);
        $this->assertSame('DO_ICALL', $internal->executeOpcode);
        $this->assertArgument($internal->arguments[0], 'SEND_REF', Argument::REFERENCE, false, null, 1);
        $this->assertVariable($internal->arguments[0]->value, 'items');

        $this->assertSame('byreference', $user->name);
        $this->assertSame('DO_UCALL', $user->executeOpcode);
        $this->assertArgument($user->arguments[0], 'SEND_REF', Argument::REFERENCE, false, null, 1);
        $this->assertVariable($user->arguments[0]->value, 'n');
    }

    public function testAForwardCallKeepsTheCompilerName(): void
    {
        $call = $this->returnedCall('forward');

        $this->assertSame('notYet', $call->name);
        $this->assertSame('INIT_FCALL_BY_NAME', $call->initOpcode);
        $this->assertSame('DO_FCALL_BY_NAME', $call->executeOpcode);
        $this->assertSame(1, $call->initExtendedValue);
        $this->assertSame(0, $call->executeExtendedValue);
        $this->assertFalse($call->checksUndefinedArguments);
        $this->assertArgument($call->arguments[0], 'SEND_VAR_EX', Argument::VALUE, false, null, 1);
        $this->assertVariable($call->arguments[0]->value, 'n');
    }

    public function testACallWithNoArgumentsHasAnEmptyList(): void
    {
        $expressions = $this->roots('noArguments');
        $call = $this->rootCall($expressions);

        $this->assertSame('greet', $call->name);
        $this->assertSame([], $call->arguments);
        $this->assertSame(0, $call->initExtendedValue);
        $this->assertSame('DO_UCALL', $call->executeOpcode);
        $this->assertLessThan($call->opline, $call->initOpline);
        [$expressionsAgain, $statements] = $this->both('noArguments');
        $statement = $statements->blockStartingAt(0)?->statements[0] ?? null;
        $this->assertInstanceOf(ExpressionStatement::class, $statement);
        $this->assertSame($this->rootCall($expressionsAgain->blockStartingAt(0)?->expressions ?? []), $statement->expression);
        $this->assertCount(1, $statements->blockStartingAt(0)?->statements);
    }

    public function testAnIncompleteCallStaysOpaqueAndKeepsTheArgument(): void
    {
        $document = $this->mutated(function (array $document): array {
            foreach ($document['op_arrays'] as &$opArray) {
                if (($opArray['name'] ?? null) !== 'sideEffect') {
                    continue;
                }
                $opArray['instructions'][1]['extended_value'] = 2;
            }

            return $document;
        });
        $result = $this->reconstruct('sideEffect', $document);
        $expressions = $this->roots('sideEffect', $document);
        $init = $this->opaqueNamed($expressions, 'INIT_FCALL');
        $send = $this->opaqueNamed($expressions, 'SEND_VAR');
        $execute = $this->opaqueNamed($expressions, 'DO_ICALL');

        $this->assertNull($this->findCall($expressions));
        $this->assertSame('function call could not be reconstructed', $init->reason);
        $this->assertSame('function call could not be reconstructed', $send->reason);
        $this->assertSame('function call could not be reconstructed', $execute->reason);
        $this->assertVariable($send->op1->resolved, 'text');
        $this->assertSame(['INIT_FCALL', 'SEND_VAR', 'DO_ICALL'], $this->opcodes($result->diagnostics));
        $this->assertLessThan($send->opline, $init->opline);
        $this->assertLessThan($execute->opline, $send->opline);
    }

    public function testAnUnrecognizedSendDoesNotProduceAPartialCall(): void
    {
        $document = $this->mutated(function (array $document): array {
            foreach ($document['op_arrays'] as &$opArray) {
                if (($opArray['name'] ?? null) !== 'sideEffect') {
                    continue;
                }
                $opArray['instructions'][2]['opcode'] = 'SEND_USER';
                $opArray['instructions'][2]['opcode_number'] = 120;
            }

            return $document;
        });
        $result = $this->reconstruct('sideEffect', $document);
        $expressions = $this->roots('sideEffect', $document);
        $send = $this->opaqueNamed($expressions, 'SEND_USER');

        $this->assertNull($this->findCall($expressions));
        $this->assertVariable($send->op1->resolved, 'text');
        $this->assertSame(['INIT_FCALL', 'SEND_USER', 'DO_ICALL'], $this->opcodes($result->diagnostics));
    }

    public function testAnInterruptedCallIsNotPartiallyReconstructed(): void
    {
        $document = $this->mutated(function (array $document): array {
            foreach ($document['op_arrays'] as &$opArray) {
                if (($opArray['name'] ?? null) !== 'sideEffect') {
                    continue;
                }
                $opArray['instructions'][3]['opcode'] = 'ECHO';
                $opArray['instructions'][3]['opcode_number'] = 136;
            }

            return $document;
        });
        $result = $this->reconstruct('sideEffect', $document);
        $expressions = $this->roots('sideEffect', $document);

        $this->assertNull($this->findCall($expressions));
        $this->assertSame(
            'function call was interrupted by ECHO',
            $this->opaqueNamed($expressions, 'INIT_FCALL')->reason,
        );
        $this->assertVariable($this->opaqueNamed($expressions, 'SEND_VAR')->op1->resolved, 'text');
        $this->assertSame(['INIT_FCALL', 'SEND_VAR', 'ECHO'], $this->opcodes($result->diagnostics));
    }

    public function testADynamicCallStaysOpaqueWithItsArgument(): void
    {
        $result = $this->reconstruct('dynamic');
        $expressions = $this->roots('dynamic');
        $init = $this->opaqueNamed($expressions, 'INIT_DYNAMIC_CALL');
        $send = $this->opaqueNamed($expressions, 'SEND_VAR_EX');
        $execute = $this->opaqueNamed($expressions, 'DO_FCALL');

        $this->assertNull($this->findCall($expressions));
        $this->assertVariable($init->op2->resolved, 'name');
        $this->assertVariable($send->op1->resolved, 'price');
        $this->assertSame('unused', $execute->result->kind);
        $this->assertNull($execute->result->resolved);
        $this->assertSame(['INIT_DYNAMIC_CALL', 'SEND_VAR_EX', 'DO_FCALL'], $this->opcodes($result->diagnostics));
        $this->assertLessThan($send->opline, $init->opline);
        $this->assertLessThan($execute->opline, $send->opline);
    }

    public function testAMethodCallStaysOpaque(): void
    {
        $result = $this->reconstruct('methodCall');
        $expressions = $this->roots('methodCall');
        $init = $this->opaqueNamed($expressions, 'INIT_METHOD_CALL');
        $send = $this->opaqueNamed($expressions, 'SEND_VAR_EX');

        $this->assertNull($this->findCall($expressions));
        $this->assertVariable($init->op1->resolved, 'obj');
        $this->assertLiteral($init->op2->resolved, 'string', 'name');
        $this->assertVariable($send->op1->resolved, 'obj');
        $this->assertNotSame($init->op1->resolved, $send->op1->resolved);
        $this->assertSame(['INIT_METHOD_CALL', 'SEND_VAR_EX', 'DO_FCALL'], $this->opcodes($result->diagnostics));
    }

    public function testAConstructorStaysOpaque(): void
    {
        $result = $this->reconstruct('constructed');
        $expressions = $this->roots('constructed');
        $assignment = null;
        foreach ($expressions as $expression) {
            if ($expression instanceof Assignment) {
                $assignment = $expression;
            }
        }

        $this->assertNull($this->findCall($expressions));
        $this->assertInstanceOf(Assignment::class, $assignment);
        $this->assertSame('box', $assignment->variable);
        $this->assertInstanceOf(Opaque::class, $assignment->value);
        $this->assertSame('NEW', $assignment->value->opcode);
        $this->assertLiteral($assignment->value->op1->resolved, 'string', 'Exception');
        $this->assertLiteral($this->opaqueNamed($expressions, 'SEND_VAL_EX')->op1->resolved, 'string', 'bad');
        $this->assertSame('DO_FCALL', $this->opaqueNamed($expressions, 'DO_FCALL')->opcode);
        $this->assertSame(['NEW', 'SEND_VAL_EX', 'DO_FCALL'], $this->opcodes($result->diagnostics));
        foreach ($expressions as $expression) {
            $this->assertNotSame($assignment->value, $expression);
        }
    }

    public function testAFirstClassCallableIsNotAFunctionCall(): void
    {
        $result = $this->reconstruct('firstClass');
        $expressions = $this->roots('firstClass');
        $assignment = null;
        foreach ($expressions as $expression) {
            if ($expression instanceof Assignment) {
                $assignment = $expression;
            }
        }

        $this->assertNull($this->findCall($expressions));
        $this->assertInstanceOf(Assignment::class, $assignment);
        $this->assertInstanceOf(Opaque::class, $assignment->value);
        $this->assertSame('CALLABLE_CONVERT', $assignment->value->opcode);
        $this->assertSame('first-class callable is not a function call', $this->opaqueNamed($expressions, 'INIT_FCALL')->reason);
        $this->assertLiteral($this->opaqueNamed($expressions, 'INIT_FCALL')->op2->resolved, 'string', 'strlen');
        $this->assertSame(['INIT_FCALL', 'CALLABLE_CONVERT'], $this->opcodes($result->diagnostics));
        foreach ($expressions as $expression) {
            $this->assertNotSame($assignment->value, $expression);
        }
    }

    public function testAConstructorArgumentDoesNotConsumeTheOuterCall(): void
    {
        $result = $this->reconstruct('withNew');
        $expressions = $result->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);
        $call = $this->userReturn($expressions)->value;
        $this->assertInstanceOf(FunctionCall::class, $call);
        $created = $call->arguments[0]->value;

        $this->assertSame('take', $call->name);
        $this->assertSame('DO_UCALL', $call->executeOpcode);
        $this->assertCount(2, $call->arguments);
        $this->assertInstanceOf(Opaque::class, $created);
        $this->assertSame('NEW', $created->opcode);
        $this->assertLiteral($created->op1->resolved, 'string', 'Exception');
        $this->assertVariable($call->arguments[1]->value, 'n');
        $this->assertLiteral($this->opaqueNamed($expressions, 'SEND_VAL_EX')->op1->resolved, 'string', 'bad');
        $this->assertSame('DO_FCALL', $this->opaqueNamed($expressions, 'DO_FCALL')->opcode);
        $this->assertSame(['NEW', 'SEND_VAL_EX', 'DO_FCALL'], $this->opcodes($result->diagnostics));
        $this->assertNotContains('INIT_FCALL', $this->opcodes($result->diagnostics));
        foreach ($expressions as $expression) {
            $this->assertNotSame($created, $expression);
            $this->assertNotSame($call, $expression);
        }
    }

    public function testADynamicArgumentDoesNotConsumeTheOuterCall(): void
    {
        $result = $this->reconstruct('withDynamic');
        $expressions = $result->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);
        $call = $this->userReturn($expressions)->value;
        $this->assertInstanceOf(FunctionCall::class, $call);
        $dynamic = $call->arguments[0]->value;

        $this->assertSame('take', $call->name);
        $this->assertCount(2, $call->arguments);
        $this->assertInstanceOf(Opaque::class, $dynamic);
        $this->assertSame('DO_FCALL', $dynamic->opcode);
        $this->assertSame('var', $dynamic->result->kind);
        $this->assertSame(1, $dynamic->result->index);
        $this->assertNull($dynamic->result->resolved);
        $this->assertVariable($call->arguments[1]->value, 'n');
        $this->assertVariable($this->opaqueNamed($expressions, 'INIT_DYNAMIC_CALL')->op2->resolved, 'name');
        $this->assertVariable($this->opaqueNamed($expressions, 'SEND_VAR_EX')->op1->resolved, 'n');
        $this->assertSame(['INIT_DYNAMIC_CALL', 'SEND_VAR_EX', 'DO_FCALL'], $this->opcodes($result->diagnostics));
        $this->assertNotContains('INIT_FCALL', $this->opcodes($result->diagnostics));
        $this->assertNotContains('DO_UCALL', $this->opcodes($result->diagnostics));
        foreach ($expressions as $expression) {
            $this->assertNotSame($dynamic, $expression);
            $this->assertNotSame($call, $expression);
        }
    }

    private function returnedCall(string $name): FunctionCall
    {
        $call = $this->userReturn($this->roots($name))->value;
        $this->assertInstanceOf(FunctionCall::class, $call);

        return $call;
    }

    /**
     * @param list<Expression> $expressions
     */
    private function userReturn(array $expressions): ReturnValue
    {
        $found = null;
        foreach ($expressions as $expression) {
            if ($expression instanceof ReturnValue && !$this->isNullLiteral($expression->value)) {
                $found = $expression;
            }
        }
        $this->assertInstanceOf(ReturnValue::class, $found);

        return $found;
    }

    private function isNullLiteral(mixed $expression): bool
    {
        return $expression instanceof Literal && $expression->type === 'null';
    }

    /**
     * @param list<Expression> $expressions
     */
    private function verified(array $expressions): Expression
    {
        foreach ($expressions as $expression) {
            if ($expression instanceof Opaque && $expression->opcode === 'VERIFY_RETURN_TYPE' && $expression->op1->resolved !== null) {
                return $expression->op1->resolved;
            }
        }

        $this->fail('Missing verified value');
    }

    /**
     * @param list<Expression> $expressions
     */
    private function rootCall(array $expressions): FunctionCall
    {
        $call = $this->findCall($expressions);
        $this->assertInstanceOf(FunctionCall::class, $call);

        return $call;
    }

    /**
     * @param list<Expression> $expressions
     */
    private function findCall(array $expressions): ?FunctionCall
    {
        foreach ($expressions as $expression) {
            if ($expression instanceof FunctionCall) {
                return $expression;
            }
        }

        return null;
    }

    /**
     * @param list<Expression> $expressions
     */
    private function opaqueNamed(array $expressions, string $opcode): Opaque
    {
        foreach ($expressions as $expression) {
            if ($expression instanceof Opaque && $expression->opcode === $opcode) {
                return $expression;
            }
        }

        $this->fail('Missing opaque ' . $opcode);
    }

    private function assertArgument(
        Argument $argument,
        string $opcode,
        string $mode,
        bool $unpack,
        ?string $name,
        ?int $position,
    ): void {
        $this->assertSame($opcode, $argument->opcode);
        $this->assertSame($mode, $argument->mode);
        $this->assertSame($unpack, $argument->unpack);
        $this->assertSame($name, $argument->name);
        $this->assertSame($position, $argument->position);
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

    /**
     * @param list<Expression> $expressions
     */
    private function assertNoOpaqueCall(array $expressions): void
    {
        foreach ($expressions as $expression) {
            if ($expression instanceof Opaque) {
                $this->assertNotContains($expression->opcode, ['INIT_FCALL', 'SEND_VAR', 'DO_ICALL', 'DO_UCALL']);
            }
        }
    }

    /**
     * @param list<\Rephper\Expr\Diagnostic> $diagnostics
     * @return list<string>
     */
    private function opcodes(array $diagnostics): array
    {
        return array_map(static fn ($diagnostic): string => $diagnostic->opcode, $diagnostics);
    }

    /**
     * @return list<Expression>
     */
    private function roots(string $name, ?Document $document = null): array
    {
        $expressions = $this->reconstruct($name, $document)->blockStartingAt(0)?->expressions;
        $this->assertNotNull($expressions);

        return $expressions;
    }

    private function reconstruct(string $name, ?Document $document = null): \Rephper\Expr\Reconstruction
    {
        $document ??= $this->document();
        $opArray = $this->opArray($name, $document);
        $graph = null;
        foreach ((new Builder())->buildDocument($document) as $candidate) {
            if ($candidate->name === $name) {
                $graph = $candidate;
            }
        }
        $this->assertNotNull($graph);

        return (new Reconstructor())->reconstruct($opArray, $graph);
    }

    /**
     * @return array{0: \Rephper\Expr\Reconstruction, 1: \Rephper\Stmt\Reconstruction}
     */
    private function both(string $name): array
    {
        $document = $this->document();
        $opArray = $this->opArray($name, $document);
        $graph = null;
        foreach ((new Builder())->buildDocument($document) as $candidate) {
            if ($candidate->name === $name) {
                $graph = $candidate;
            }
        }
        $this->assertNotNull($graph);
        $expressions = (new Reconstructor())->reconstruct($opArray, $graph);

        return [$expressions, (new StatementReconstructor())->reconstruct($opArray, $graph, $expressions)];
    }

    private function opArray(string $name, Document $document): OpArray
    {
        $opArray = $document->opArrayNamed($name);
        $this->assertInstanceOf(OpArray::class, $opArray);

        return $opArray;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    private function mutated(callable $change): Document
    {
        $decoded = json_decode((string) file_get_contents($this->fixture()), true);
        $this->assertIsArray($decoded);
        $decoded['php']['version'] = '8.4.27';
        $decoded['php']['version_id'] = 80427;

        return (new Reader())->readString((string) json_encode($change($decoded)));
    }

    private function document(): Document
    {
        $json = (string) file_get_contents($this->fixture());
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);

        return (new Reader())->readString($json);
    }

    private function fixture(): string
    {
        return dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Calls.json';
    }
}
