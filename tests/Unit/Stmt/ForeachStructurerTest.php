<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Stmt;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephper\Cfg\Builder;
use Rephper\Cfg\Graph;
use Rephper\Cfg\LoopAnalysis;
use Rephper\Cfg\LoopAnalyzer;
use Rephper\Cfg\LoopControl;
use Rephper\Cfg\NaturalLoop;
use Rephper\Expr\Argument;
use Rephper\Expr\Assignment;
use Rephper\Expr\Binary;
use Rephper\Expr\Expression;
use Rephper\Expr\FunctionCall;
use Rephper\Expr\Literal;
use Rephper\Expr\Reconstructor as ExpressionReconstructor;
use Rephper\Expr\Unresolved;
use Rephper\Expr\VariableRead;
use Rephper\Ir\Document;
use Rephper\Ir\Reader;
use Rephper\Stmt\BreakStatement;
use Rephper\Stmt\ConditionalStructurer;
use Rephper\Stmt\ContinueStatement;
use Rephper\Stmt\DoWhileStatement;
use Rephper\Stmt\ExpressionStatement;
use Rephper\Stmt\ForStatement;
use Rephper\Stmt\ForeachStatement;
use Rephper\Stmt\IfStatement;
use Rephper\Stmt\LoopStructurer;
use Rephper\Stmt\OpaqueStatement;
use Rephper\Stmt\Reconstructor;
use Rephper\Stmt\Reconstruction;
use Rephper\Stmt\ReturnStatement;
use Rephper\Stmt\Statement;
use Rephper\Stmt\StructuredStatements;
use Rephper\Stmt\ThrowStatement;
use Rephper\Stmt\WhileStatement;

final class ForeachStructurerTest extends TestCase
{
    private ?\Rephper\Ir\OpArray $opArray = null;

    public function testASimpleForeachKeepsTheVariableIterableAndValue(): void
    {
        [$foreach, $reconstruction, $analysis, $structured] = $this->one('simple');

        $this->assertVariable($foreach, 'items');
        $this->assertSame('value', $foreach->value->name);
        $this->assertNull($foreach->key);
        $this->assertFalse($foreach->byReference);
        $this->assertSame([$this->original($reconstruction, $foreach->body[0]->opline)], $foreach->body);
        $this->assertCall($foreach->body[0], 'process', ['value']);
        $this->assertSame([], array_slice($structured->statements, 1));
        $this->assertSame($this->onlyForeachLoop($analysis)->headerLine, $foreach->line);
    }

    public function testAKeyIsTheAssignmentOfTheFetchResult(): void
    {
        [$foreach, $reconstruction] = $this->one('withKey');
        $fetch = $this->fetch($reconstruction, $this->onlyForeachLoop($this->analysis('withKey')));

        $this->assertVariable($foreach, 'items');
        $this->assertNotNull($foreach->key);
        $this->assertSame('key', $foreach->key->name);
        $this->assertSame('value', $foreach->value->name);
        $this->assertFalse($foreach->byReference);
        $this->assertNotSame($foreach->key->name, $foreach->value->name);
        $this->assertSame($fetch->instruction->result->kind, 'tmp');
        $this->assertSame($fetch->opline + 1, $foreach->key->opline);
        $this->assertCall($foreach->body[0], 'processkey', ['key', 'value']);
        $this->assertArrayNotHasKey($foreach->key->opline, $this->oplines($foreach->body));
    }

    public function testByReferenceIsTheRwFetchAndNotAnAssignment(): void
    {
        [$foreach, $reconstruction, $analysis] = $this->one('byRef');
        $loop = $this->onlyForeachLoop($analysis);

        $this->assertVariable($foreach, 'items');
        $this->assertTrue($foreach->byReference);
        $this->assertSame('FE_RESET_RW', $this->reset($reconstruction, $loop)->instruction->opcode);
        $this->assertSame('FE_FETCH_RW', $this->fetch($reconstruction, $loop)->instruction->opcode);
        $this->assertNull($foreach->key);
        $this->assertSame('value', $foreach->value->name);
        $this->assertSame('PRE_INC', $this->opcode($foreach->body[0]));
        $this->assertSame('value', $foreach->body[0]->instruction->op1->name);
        $this->assertSame($this->original($reconstruction, $foreach->body[0]->opline), $foreach->body[0]);
        foreach ($foreach->body as $statement) {
            $this->assertNotInstanceOf(ExpressionStatement::class, $statement);
        }
    }

    public function testAKeyStaysByValueWhenTheValueIsByReference(): void
    {
        [$foreach, $reconstruction] = $this->one('keyRef');
        $loop = $this->onlyForeachLoop($this->analysis('keyRef'));

        $this->assertVariable($foreach, 'items');
        $this->assertTrue($foreach->byReference);
        $this->assertSame('FE_RESET_RW', $this->reset($reconstruction, $loop)->instruction->opcode);
        $this->assertSame('FE_FETCH_RW', $this->fetch($reconstruction, $loop)->instruction->opcode);
        $this->assertNotNull($foreach->key);
        $this->assertSame('key', $foreach->key->name);
        $this->assertSame('value', $foreach->value->name);
        $this->assertSame('ASSIGN', $this->instruction($foreach->key->opline)->opcode);
        $this->assertSame('PRE_INC', $this->opcode($foreach->body[0]));
        $this->assertSame('value', $foreach->body[0]->instruction->op1->name);
        $this->assertArrayNotHasKey($foreach->key->opline, $this->oplines($foreach->body));
    }

    public function testACallIterableIsTheResetOperandAndIsNotRepeated(): void
    {
        [$foreach, $reconstruction, , $structured] = $this->one('callIterable');

        $this->assertInstanceOf(FunctionCall::class, $foreach->iterable);
        $this->assertSame('getitems', $foreach->iterable->name);
        $this->assertSame([], $foreach->iterable->arguments);
        $this->assertNull($foreach->key);
        $this->assertFalse($foreach->byReference);
        $this->assertSame('value', $foreach->value->name);
        $this->assertSame(1, $this->occurrences($structured->statements, $foreach->iterable));
        $this->assertCall($foreach->body[0], 'process', ['value']);
        $this->assertNotSame($foreach->iterable, $foreach->body[0]->expression);
        $this->assertInstanceOf(OpaqueStatement::class, $this->reset($reconstruction, $this->onlyForeachLoop($this->analysis('callIterable'))));
    }

    public function testAnEmptyBodyHasNoStatements(): void
    {
        [$foreach, , , $structured] = $this->one('emptyBody');

        $this->assertVariable($foreach, 'items');
        $this->assertSame('value', $foreach->value->name);
        $this->assertNull($foreach->key);
        $this->assertFalse($foreach->byReference);
        $this->assertSame([], $foreach->body);
        $this->assertSame([$foreach], $structured->statements);
    }

    public function testAnIfElseStaysInsideTheBodyInSourceOrder(): void
    {
        [$foreach] = $this->one('ifElse');

        $this->assertVariable($foreach, 'items');
        $this->assertCount(1, $foreach->body);
        $this->assertInstanceOf(IfStatement::class, $foreach->body[0]);
        $if = $foreach->body[0];
        $this->assertCount(1, $if->branches);
        $this->assertNotNull($if->elseStatements);
        $this->assertCall($if->branches[0]->statements[0], 'process', ['value']);
        $this->assertCall($if->elseStatements[0], 'process', ['value']);
        $this->assertNotNull($if->elseOpline);
        $this->assertLessThan($if->elseOpline, $if->branches[0]->statements[0]->opline);
        $this->assertLessThan($if->elseStatements[0]->opline, $if->branches[0]->statements[0]->opline);
        $this->assertInstanceOf(Binary::class, $if->branches[0]->condition);
        $this->assertSame('<', $if->branches[0]->condition->operator);
    }

    public function testNestedForeachUsesEachResetOperand(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('nested');
        $outerLoop = $analysis->loops[0];
        $innerLoop = $analysis->loops[1];
        $outer = $structured->statements[0];
        $this->assertInstanceOf(ForeachStatement::class, $outer);
        $inner = $outer->body[0];
        $this->assertInstanceOf(ForeachStatement::class, $inner);

        $this->assertSame(NaturalLoop::FOREACH, $outerLoop->kind);
        $this->assertSame(NaturalLoop::FOREACH, $innerLoop->kind);
        $this->assertSame($outerLoop->id, $innerLoop->parentId);
        $this->assertSame($outerLoop->id, $outer->loopId);
        $this->assertSame($innerLoop->id, $inner->loopId);
        $this->assertVariable($outer, 'rows');
        $this->assertSame('row', $outer->value->name);
        $this->assertVariable($inner, 'row');
        $this->assertSame('value', $inner->value->name);
        $this->assertNull($outer->key);
        $this->assertNull($inner->key);
        $this->assertFalse($outer->byReference);
        $this->assertFalse($inner->byReference);
        $this->assertSame($this->reset($reconstruction, $outerLoop)->instruction->op1->resolved, $outer->iterable);
        $this->assertSame($this->reset($reconstruction, $innerLoop)->instruction->op1->resolved, $inner->iterable);
        $this->assertNotSame($outer->iterable, $inner->iterable);
        $this->assertSame($this->fetch($reconstruction, $outerLoop)->instruction->op2->name, $outer->value->name);
        $this->assertSame($this->fetch($reconstruction, $innerLoop)->instruction->op2->name, $inner->value->name);
        $this->assertCall($inner->body[0], 'process', ['value']);
        $this->assertSame([$inner], $outer->body);
        $this->assertNoIteratorOpcodes($structured->statements);
        $this->assertNoUnstructured($structured);
    }

    public function testAForeachInsideAForDoesNotFlattenTheFor(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('foreachInFor');
        $for = $this->onlyFor($structured->statements);
        $foreach = $for->body[0];

        $this->assertInstanceOf(ForeachStatement::class, $foreach);
        $this->assertSame($analysis->loops[1]->id, $for->loopId);
        $this->assertSame(NaturalLoop::FOR, $analysis->loops[1]->kind);
        $this->assertSame($analysis->loops[0]->id, $foreach->loopId);
        $this->assertSame($analysis->loops[1]->id, $analysis->loops[0]->parentId);
        $this->assertSame('i', $for->initializers[0]->variable);
        $this->assertSame([$this->assignment($reconstruction, 'i')], $for->initializers);
        $this->assertSame('PRE_INC', $for->updates[0]->opcode);
        $this->assertSame('i', $for->updates[0]->op1->name);
        $this->assertSame($analysis->loops[1]->continueOpline, $for->updates[0]->opline);
        $this->assertVariable($foreach, 'rows');
        $this->assertSame('row', $foreach->value->name);
        $this->assertFalse($foreach->byReference);
        $this->assertCall($foreach->body[0], 'process', ['row']);
        $this->assertSame([$foreach], $for->body);
        $this->assertNoIteratorOpcodes($structured->statements);
        $this->assertNoUnstructured($structured);
    }

    public function testAForInsideAForeachStaysAFor(): void
    {
        [$foreach, $reconstruction, $analysis] = $this->one('forInForeach');
        $for = $foreach->body[0];

        $this->assertInstanceOf(ForStatement::class, $for);
        $this->assertSame($analysis->loops[1]->id, $for->loopId);
        $this->assertSame(NaturalLoop::FOR, $analysis->loops[1]->kind);
        $this->assertSame($foreach->loopId, $analysis->loops[1]->parentId);
        $this->assertVariable($foreach, 'rows');
        $this->assertSame('row', $foreach->value->name);
        $this->assertSame([$for], $foreach->body);
        $this->assertSame('i', $for->initializers[0]->variable);
        $this->assertSame([$this->assignment($reconstruction, 'i')], $for->initializers);
        $this->assertSame('PRE_INC', $for->updates[0]->opcode);
        $this->assertSame($analysis->loops[1]->continueOpline, $for->updates[0]->opline);
        $this->assertCall($for->body[0], 'process', ['i']);
    }

    public function testAForeachInsideAWhileKeepsTheReferenceSend(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('foreachInWhile');
        $while = $this->onlyWhile($structured->statements);
        $foreach = $while->body[1];

        $this->assertInstanceOf(ForeachStatement::class, $foreach);
        $this->assertSame($analysis->loops[1]->id, $while->loopId);
        $this->assertSame($analysis->loops[0]->id, $foreach->loopId);
        $this->assertSame($while->loopId, $analysis->loops[0]->parentId);
        $this->assertInstanceOf(ExpressionStatement::class, $while->body[0]);
        $this->assertInstanceOf(Assignment::class, $while->body[0]->expression);
        $this->assertSame('group', $while->body[0]->expression->variable);
        $this->assertInstanceOf(FunctionCall::class, $while->body[0]->expression->value);
        $this->assertSame('array_shift', $while->body[0]->expression->value->name);
        $this->assertSame(Argument::REFERENCE, $while->body[0]->expression->value->arguments[0]->mode);
        $this->assertSame($this->assignment($reconstruction, 'group'), $while->body[0]->expression);
        $this->assertVariable($foreach, 'group');
        $this->assertNotSame($while->body[0]->expression->value, $foreach->iterable);
        $this->assertSame('value', $foreach->value->name);
        $this->assertCall($foreach->body[0], 'process', ['value']);
        $this->assertSame([$while->body[0], $foreach], $while->body);
        $this->assertNoIteratorOpcodes($structured->statements);
        $this->assertNoUnstructured($structured);
    }

    public function testAWhileInsideAForeachKeepsTheAssignmentBeforeTheWhile(): void
    {
        [$foreach, $reconstruction, $analysis] = $this->one('whileInForeach');
        $while = $foreach->body[1];

        $this->assertInstanceOf(WhileStatement::class, $while);
        $this->assertSame($analysis->loops[1]->id, $while->loopId);
        $this->assertSame($foreach->loopId, $analysis->loops[1]->parentId);
        $this->assertInstanceOf(ExpressionStatement::class, $foreach->body[0]);
        $this->assertSame('j', $foreach->body[0]->expression->variable);
        $this->assertSame($this->assignment($reconstruction, 'j'), $foreach->body[0]->expression);
        $this->assertLessThan($while->line, $foreach->body[0]->line);
        $this->assertCall($while->body[0], 'process', ['j']);
        $this->assertSame('PRE_INC', $this->opcode($while->body[1]));
        $this->assertSame('j', $while->body[1]->instruction->op1->name);
        $this->assertSame([], $this->fors([$foreach]));
    }

    public function testAForeachInsideADoWhileStaysInsideTheDoWhile(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('foreachInDo');
        $do = $this->onlyDo($structured->statements);
        $foreach = $do->body[1];

        $this->assertInstanceOf(ForeachStatement::class, $foreach);
        $this->assertSame($analysis->loops[0]->id, $do->loopId);
        $this->assertSame(NaturalLoop::DO_WHILE, $analysis->loops[0]->kind);
        $this->assertSame($analysis->loops[1]->id, $foreach->loopId);
        $this->assertSame($do->loopId, $analysis->loops[1]->parentId);
        $this->assertSame('group', $do->body[0]->expression->variable);
        $this->assertSame($this->assignment($reconstruction, 'group'), $do->body[0]->expression);
        $this->assertInstanceOf(FunctionCall::class, $do->body[0]->expression->value);
        $this->assertSame(Argument::REFERENCE, $do->body[0]->expression->value->arguments[0]->mode);
        $this->assertVariable($foreach, 'group');
        $this->assertSame([$do->body[0], $foreach], $do->body);
        $this->assertInstanceOf(Binary::class, $do->condition);
        $this->assertSame('!==', $do->condition->operator);
        $this->assertSame($this->branchResolved($reconstruction, $do->opline), $do->condition);
        $this->assertNoIteratorOpcodes($structured->statements);
        $this->assertNoUnstructured($structured);
    }

    public function testADoWhileInsideAForeachStaysADoWhile(): void
    {
        [$foreach, $reconstruction, $analysis] = $this->one('doInForeach');
        $do = $foreach->body[1];

        $this->assertInstanceOf(DoWhileStatement::class, $do);
        $this->assertSame($analysis->loops[1]->id, $do->loopId);
        $this->assertSame(NaturalLoop::DO_WHILE, $analysis->loops[1]->kind);
        $this->assertSame($foreach->loopId, $analysis->loops[1]->parentId);
        $this->assertSame('j', $foreach->body[0]->expression->variable);
        $this->assertSame($this->assignment($reconstruction, 'j'), $foreach->body[0]->expression);
        $this->assertCall($do->body[0], 'process', ['j']);
        $this->assertSame('PRE_INC', $this->opcode($do->body[1]));
        $this->assertInstanceOf(Binary::class, $do->condition);
        $this->assertSame('<', $do->condition->operator);
        $this->assertSame([], $this->fors([$foreach]));
    }

    public function testBreakAndContinueTargetTheForeach(): void
    {
        [$foreach, , $analysis] = $this->one('breakContinue');
        $continue = $this->control($analysis, 'CONT', 1);
        $break = $this->control($analysis, 'BRK', 1);

        $this->assertInstanceOf(IfStatement::class, $foreach->body[0]);
        $this->assertInstanceOf(IfStatement::class, $foreach->body[1]);
        $this->assertInstanceOf(ContinueStatement::class, $foreach->body[0]->branches[0]->statements[0]);
        $this->assertInstanceOf(BreakStatement::class, $foreach->body[1]->branches[0]->statements[0]);
        $this->assertSame($continue->level, $foreach->body[0]->branches[0]->statements[0]->level);
        $this->assertSame($continue->loopId, $foreach->body[0]->branches[0]->statements[0]->loopId);
        $this->assertSame($foreach->loopId, $foreach->body[0]->branches[0]->statements[0]->loopId);
        $this->assertSame($continue->opline, $foreach->body[0]->branches[0]->statements[0]->opline);
        $this->assertSame($break->level, $foreach->body[1]->branches[0]->statements[0]->level);
        $this->assertSame($break->loopId, $foreach->body[1]->branches[0]->statements[0]->loopId);
        $this->assertSame($break->line, $foreach->body[1]->branches[0]->statements[0]->line);
        $this->assertCall($foreach->body[2], 'process', ['value']);
        $this->assertLessThan($foreach->body[1]->opline, $foreach->body[0]->opline);
        $this->assertNoIteratorOpcodes([$foreach]);
    }

    public function testBreakTwoTargetsTheOuterForeach(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('breakTwo');
        $outer = $structured->statements[0];
        $this->assertInstanceOf(ForeachStatement::class, $outer);
        $inner = $outer->body[0];
        $this->assertInstanceOf(ForeachStatement::class, $inner);
        $break = $inner->body[0]->branches[0]->statements[0];
        $control = $this->control($analysis, 'BRK', 2);

        $this->assertInstanceOf(BreakStatement::class, $break);
        $this->assertSame(2, $break->level);
        $this->assertSame($control->loopId, $break->loopId);
        $this->assertSame($outer->loopId, $break->loopId);
        $this->assertNotSame($inner->loopId, $break->loopId);
        $this->assertSame($control->opline, $break->opline);
        $this->assertSame($control->line, $break->line);
        $this->assertTrue($control->outer);
        $this->assertCall($inner->body[1], 'process', ['value']);
        $this->assertSame($this->reset($reconstruction, $analysis->loops[0])->instruction->op1->resolved, $outer->iterable);
        $this->assertSame($this->reset($reconstruction, $analysis->loops[1])->instruction->op1->resolved, $inner->iterable);
        $this->assertNoIteratorOpcodes($structured->statements);
        $this->assertNoUnstructured($structured);
    }

    public function testContinueTwoTargetsTheOuterForeach(): void
    {
        [, , $analysis, $structured] = $this->prepare('continueTwo');
        $outer = $structured->statements[0];
        $this->assertInstanceOf(ForeachStatement::class, $outer);
        $inner = $outer->body[0];
        $this->assertInstanceOf(ForeachStatement::class, $inner);
        $continue = $inner->body[0]->branches[0]->statements[0];
        $control = $this->control($analysis, 'CONT', 2);

        $this->assertInstanceOf(ContinueStatement::class, $continue);
        $this->assertSame(2, $continue->level);
        $this->assertSame($control->loopId, $continue->loopId);
        $this->assertSame($outer->loopId, $continue->loopId);
        $this->assertNotSame($inner->loopId, $continue->loopId);
        $this->assertSame($control->opline, $continue->opline);
        $this->assertSame($control->line, $continue->line);
        $this->assertTrue($control->outer);
        $this->assertCall($inner->body[1], 'process', ['value']);
        $this->assertNoIteratorOpcodes($structured->statements);
        $this->assertNoUnstructured($structured);
    }

    public function testReturnFromTheBodyStaysAReturn(): void
    {
        [$foreach, $reconstruction, , $structured] = $this->one('earlyReturn');
        $returned = $foreach->body[0]->branches[0]->statements[0];

        $this->assertInstanceOf(ReturnStatement::class, $returned);
        $this->assertSame($this->original($reconstruction, $returned->opline), $returned);
        $this->assertInstanceOf(VariableRead::class, $returned->value);
        $this->assertSame('value', $returned->value->name);
        $this->assertSame($foreach->value->index, $returned->value->index);
        $this->assertInstanceOf(ReturnStatement::class, $structured->statements[1]);
        $this->assertNotSame($returned, $structured->statements[1]);
        $this->assertInstanceOf(Literal::class, $structured->statements[1]->value);
        $this->assertSame(0, $structured->statements[1]->value->value);
        $this->assertLessThan($structured->statements[1]->opline, $returned->opline);
        $this->assertNoIteratorOpcodes($structured->statements);
    }

    public function testThrowFromTheBodyKeepsTheConstructorAndTheThrow(): void
    {
        [$foreach, $reconstruction] = $this->one('thrown');
        $arm = $foreach->body[0]->branches[0]->statements;

        $this->assertCount(3, $arm);
        $this->assertSame('SEND_VAL_EX', $this->opcode($arm[0]));
        $this->assertSame('DO_FCALL', $this->opcode($arm[1]));
        $this->assertInstanceOf(ThrowStatement::class, $arm[2]);
        $this->assertSame($this->original($reconstruction, $arm[2]->opline), $arm[2]);
        $this->assertSame($this->original($reconstruction, $arm[0]->opline), $arm[0]);
        $this->assertSame($this->original($reconstruction, $arm[1]->opline), $arm[1]);
        $this->assertNoIteratorOpcodes([$foreach]);
    }

    public function testDestructuringStaysUnstructuredWithItsStatements(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('destructure');

        $this->assertSame(NaturalLoop::FOREACH, $this->onlyForeachLoop($analysis)->kind);
        $this->assertSame([], $this->foreaches($structured->statements));
        $this->assertSame($this->flat($reconstruction), $structured->statements);
        $failure = $this->unstructured($structured);
        $this->assertSame('FE_FETCH_R', $failure->opcode);
        $this->assertStringContainsString('compiled variable', $failure->reason);
        $this->assertSame('FE_FETCH_R', $this->fetch($reconstruction, $this->onlyForeachLoop($analysis))->instruction->opcode);
        $this->assertSame('var', $this->fetch($reconstruction, $this->onlyForeachLoop($analysis))->instruction->op2->kind);
        $this->assertSame('FE_RESET_R', $this->opcode($structured->statements[0]));
        $this->assertSame('FREE', $this->opcode($this->statementAt($structured->statements, $this->oplineOf($reconstruction, 'FREE'))));
    }

    public function testAListValueStaysUnstructuredAndDoesNotStealTheKey(): void
    {
        [, $reconstruction, , $structured] = $this->prepare('listAssign');

        $this->assertSame([], $this->foreaches($structured->statements));
        $this->assertSame($this->flat($reconstruction), $structured->statements);
        $failure = $this->unstructured($structured);
        $this->assertSame('FE_FETCH_R', $failure->opcode);
        $this->assertStringContainsString('compiled variable', $failure->reason);
        $this->assertAssignment($this->statementAt($structured->statements, $this->assignmentOpline($reconstruction, 'value')), 'value');
        $this->assertAssignment($this->statementAt($structured->statements, $this->assignmentOpline($reconstruction, 'extra')), 'extra');
        $this->assertAssignment($this->statementAt($structured->statements, $this->assignmentOpline($reconstruction, 'key')), 'key');
        $key = $this->statementAt($structured->statements, $this->assignmentOpline($reconstruction, 'key'));
        $this->assertInstanceOf(Unresolved::class, $key->expression->value);
    }

    public function testAReferenceValueIsStillThatVariableAfterTheLoop(): void
    {
        [$foreach, $reconstruction, , $structured] = $this->one('refAfter');
        $returned = $structured->statements[1];

        $this->assertTrue($foreach->byReference);
        $this->assertSame('value', $foreach->value->name);
        $this->assertSame('PRE_INC', $this->opcode($foreach->body[0]));
        $this->assertInstanceOf(ReturnStatement::class, $returned);
        $this->assertSame($this->original($reconstruction, $returned->opline), $returned);
        $this->assertInstanceOf(VariableRead::class, $returned->value);
        $this->assertSame($foreach->value->name, $returned->value->name);
        $this->assertSame($foreach->value->index, $returned->value->index);
        $this->assertGreaterThan($foreach->opline, $returned->opline);
        $this->assertSame([$foreach, $returned], $structured->statements);
        $this->assertNoIteratorOpcodes($structured->statements);
    }

    public function testSequentialForeachLoopsDoNotShareAnIterable(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('sequential');
        $first = $structured->statements[0];
        $second = $structured->statements[1];

        $this->assertInstanceOf(ForeachStatement::class, $first);
        $this->assertInstanceOf(ForeachStatement::class, $second);
        $this->assertNull($analysis->loops[0]->parentId);
        $this->assertNull($analysis->loops[1]->parentId);
        $this->assertSame($analysis->loops[0]->id, $first->loopId);
        $this->assertSame($analysis->loops[1]->id, $second->loopId);
        $this->assertVariable($first, 'left');
        $this->assertVariable($second, 'right');
        $this->assertNotSame($first->iterable, $second->iterable);
        $this->assertSame($this->reset($reconstruction, $analysis->loops[0])->instruction->op1->resolved, $first->iterable);
        $this->assertSame($this->reset($reconstruction, $analysis->loops[1])->instruction->op1->resolved, $second->iterable);
        $this->assertSame('value', $first->value->name);
        $this->assertSame('value', $second->value->name);
        $this->assertSame([], $this->foreaches($first->body));
        $this->assertSame([], $this->foreaches($second->body));
        $this->assertCall($first->body[0], 'process', ['value']);
        $this->assertCall($second->body[0], 'process', ['value']);
        $this->assertNoIteratorOpcodes($structured->statements);
        $this->assertNoUnstructured($structured);
    }

    public function testAForeachInsideAnIfStaysInThatBranch(): void
    {
        [, , , $structured] = $this->prepare('foreachInIf');
        $if = $structured->statements[0];

        $this->assertInstanceOf(IfStatement::class, $if);
        $this->assertInstanceOf(ForeachStatement::class, $if->branches[0]->statements[0]);
        $this->assertVariable($if->branches[0]->statements[0], 'items');
        $this->assertSame('value', $if->branches[0]->statements[0]->value->name);
        $this->assertCall($if->branches[0]->statements[0]->body[0], 'process', ['value']);
        $this->assertNotNull($if->elseStatements);
        $this->assertCall($if->elseStatements[0], 'process', ['items']);
        $this->assertSame([], $this->foreaches($if->elseStatements));
        $this->assertNoIteratorOpcodes($structured->statements);
        $this->assertNoUnstructured($structured);
    }

    public function testAnUnsupportedForeachDoesNotFlattenTheContainingFor(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('destructureInFor');
        $for = $this->onlyFor($structured->statements);

        $this->assertSame(NaturalLoop::FOR, $analysis->loops[1]->kind);
        $this->assertSame(NaturalLoop::FOREACH, $analysis->loops[0]->kind);
        $this->assertSame($analysis->loops[1]->id, $for->loopId);
        $this->assertSame([], $this->foreaches($structured->statements));
        $this->assertSame('i', $for->initializers[0]->variable);
        $this->assertSame([$this->assignment($reconstruction, 'i')], $for->initializers);
        $this->assertSame('PRE_INC', $for->updates[0]->opcode);
        $this->assertSame($analysis->loops[1]->continueOpline, $for->updates[0]->opline);
        $this->assertSame('FE_RESET_R', $this->opcode($for->body[0]));
        $this->assertSame('FE_FETCH_R', $this->opcode($for->body[1]));
        $this->assertSame('FE_FREE', $this->opcode($for->body[array_key_last($for->body)]));
        $failure = $this->unstructured($structured);
        $this->assertSame('FE_FETCH_R', $failure->opcode);
        $this->assertStringContainsString('compiled variable', $failure->reason);
        foreach ($structured->diagnostics as $diagnostic) {
            $this->assertStringNotContainsString('reachable block was left outside', $diagnostic->reason);
        }
    }

    public function testConditionalStructurerStillRefusesAForeach(): void
    {
        [, $reconstruction, , , $graph] = $this->prepare('simple');
        $structured = (new ConditionalStructurer())->structure($graph, $reconstruction);

        $this->assertSame($this->flat($reconstruction), $structured->statements);
        $failure = $structured->diagnostics[array_key_last($structured->diagnostics)];
        $this->assertSame('unstructured', $failure->kind);
        $this->assertSame('FE_RESET_R', $failure->opcode);
        foreach ($structured->statements as $statement) {
            $this->assertNotInstanceOf(ForeachStatement::class, $statement);
            $this->assertNotInstanceOf(IfStatement::class, $statement);
        }
    }

    #[DataProvider('functions')]
    public function testEveryOriginalStatementAppearsOnce(string $name): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare($name);

        $this->assertSame($this->expectedLeaves($reconstruction, $structured), $this->actualLeaves($structured), $name);
        foreach ($structured->diagnostics as $diagnostic) {
            $this->assertStringNotContainsString('reachable block was left outside', $diagnostic->reason, $name);
        }
        $this->assertStructuredControls($analysis, $structured, $name);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function functions(): array
    {
        $cases = [];
        foreach ([
            'process', 'processKey', 'getItems', 'simple', 'withKey', 'byRef', 'keyRef', 'callIterable',
            'emptyBody', 'ifElse', 'nested', 'foreachInFor', 'forInForeach', 'foreachInWhile', 'whileInForeach',
            'foreachInDo', 'doInForeach', 'breakContinue', 'breakTwo', 'continueTwo', 'earlyReturn', 'thrown',
            'destructure', 'listAssign', 'refAfter', 'sequential', 'foreachInIf', 'destructureInFor',
        ] as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /**
     * @return array{ForeachStatement, Reconstruction, LoopAnalysis, StructuredStatements}
     */
    private function one(string $name): array
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare($name);
        $loop = $this->onlyForeachLoop($analysis);
        $foreach = $this->onlyForeach($structured->statements);
        $reset = $this->reset($reconstruction, $loop);
        $fetch = $this->fetch($reconstruction, $loop);

        $this->assertSame($loop->id, $foreach->loopId);
        $this->assertSame($loop->headerOpline, $foreach->opline);
        $this->assertSame($loop->headerLine, $foreach->line);
        $this->assertSame($fetch->opline, $foreach->opline);
        $this->assertSame($fetch->line, $foreach->line);
        $this->assertSame($loop->continueOpline, $foreach->opline);
        $this->assertSame($reset->instruction->op1->resolved, $foreach->iterable);
        $this->assertNotInstanceOf(Unresolved::class, $foreach->iterable);
        $this->assertSame($fetch->instruction->opcode === 'FE_FETCH_RW', $foreach->byReference);
        $this->assertSame($reset->instruction->opcode === 'FE_RESET_RW', $foreach->byReference);
        $this->assertSame($fetch->instruction->op2->name, $foreach->value->name);
        $this->assertSame($fetch->instruction->op2->index, $foreach->value->index);
        $this->assertSame($fetch->opline, $foreach->value->opline);
        $this->assertSame($fetch->line, $foreach->value->line);
        if ($fetch->instruction->result->kind === 'unused') {
            $this->assertNull($foreach->key);
        } else {
            $this->assertNotNull($foreach->key);
            $assignment = $this->instruction($foreach->key->opline);
            $this->assertSame('ASSIGN', $assignment->opcode);
            $this->assertSame($assignment->op1->name, $foreach->key->name);
            $this->assertSame($assignment->op1->index, $foreach->key->index);
            $this->assertSame($assignment->line, $foreach->key->line);
            $this->assertInstanceOf(Unresolved::class, $this->assignmentOf($reconstruction, $foreach->key->opline)->value);
            $this->assertSame($fetch->instruction->result->kind, $this->assignmentOf($reconstruction, $foreach->key->opline)->value->kind);
            $this->assertSame($fetch->instruction->result->index, $this->assignmentOf($reconstruction, $foreach->key->opline)->value->index);
            $this->assertArrayNotHasKey($foreach->key->opline, $this->oplines($foreach->body));
        }
        $previous = $foreach->opline;
        foreach ($foreach->body as $statement) {
            $this->assertGreaterThan($previous, $statement->opline);
            $previous = $statement->opline;
        }
        $this->assertSame(1, $this->occurrences($structured->statements, $foreach->iterable));
        $this->assertNoIteratorOpcodes($structured->statements);
        $this->assertNoUnstructured($structured);
        foreach ($structured->diagnostics as $diagnostic) {
            $this->assertFalse(str_starts_with($diagnostic->opcode, 'FE_RESET'), $name);
            $this->assertFalse(str_starts_with($diagnostic->opcode, 'FE_FETCH'), $name);
            $this->assertNotSame('FE_FREE', $diagnostic->opcode, $name);
        }

        return [$foreach, $reconstruction, $analysis, $structured];
    }

    private function analysis(string $name): LoopAnalysis
    {
        [, , $analysis] = $this->prepare($name);

        return $analysis;
    }

    /**
     * @return array{0: Graph, 1: Reconstruction, 2: LoopAnalysis, 3: StructuredStatements, 4: Graph}
     */
    private function prepare(string $name): array
    {
        $opArray = $this->document()->opArrayNamed($name);
        $this->assertNotNull($opArray);
        $this->opArray = $opArray;
        $graph = (new Builder())->build($opArray);
        $expressions = (new ExpressionReconstructor())->reconstruct($opArray, $graph);
        $expressionDiagnostics = $expressions->diagnostics;
        $reconstruction = (new Reconstructor())->reconstruct($opArray, $graph, $expressions);
        $analysis = (new LoopAnalyzer())->analyze($graph, $opArray);
        $edges = $this->edges($graph);
        $identities = $this->identities($reconstruction);
        $diagnostics = $reconstruction->diagnostics;
        $structured = (new LoopStructurer())->structure($graph, $analysis, $reconstruction);

        $this->assertSame($edges, $this->edges($graph), $name);
        $this->assertSame($identities, $this->identities($reconstruction), $name);
        $this->assertSame($diagnostics, $reconstruction->diagnostics, $name);
        $this->assertSame($expressionDiagnostics, $expressions->diagnostics, $name);

        return [$graph, $reconstruction, $analysis, $structured, $graph];
    }

    private function document(): Document
    {
        static $document = null;
        if ($document !== null) {
            return $document;
        }
        $json = (string) file_get_contents(dirname(__DIR__, 2) . '/Expected/PHP/8.4/Common/ForeachLoops.json');
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);
        $document = (new Reader())->readString($json);

        return $document;
    }

    /**
     * @return list<array{string, int, int, list<array{string, string}>, list<string>, list<array{string, string}>}>
     */
    private function edges(Graph $graph): array
    {
        $rows = [];
        foreach ($graph->blocks as $block) {
            $successors = [];
            foreach ($block->successors as $edge) {
                $successors[] = [$edge->kind, $edge->to];
            }
            $exceptions = [];
            foreach ($block->exceptions as $edge) {
                $exceptions[] = [$edge->kind, $edge->to];
            }
            $rows[] = [$block->id, $block->start, $block->end, $successors, $block->predecessors, $exceptions];
        }

        return $rows;
    }

    /**
     * @return list<list<int>>
     */
    private function identities(Reconstruction $reconstruction): array
    {
        $rows = [];
        foreach ($reconstruction->blocks as $block) {
            $rows[] = array_map(static fn (Statement $statement): int => spl_object_id($statement), $block->statements);
        }

        return $rows;
    }

    private function onlyForeachLoop(LoopAnalysis $analysis): NaturalLoop
    {
        $found = [];
        foreach ($analysis->loops as $loop) {
            if ($loop->kind === NaturalLoop::FOREACH) {
                $found[] = $loop;
            }
        }
        $this->assertCount(1, $found);

        return $found[0];
    }

    /**
     * @param list<Statement> $statements
     * @return list<ForeachStatement>
     */
    private function foreaches(array $statements): array
    {
        $found = [];
        foreach ($this->walk($statements) as $statement) {
            if ($statement instanceof ForeachStatement) {
                $found[] = $statement;
            }
        }

        return $found;
    }

    /**
     * @param list<Statement> $statements
     */
    private function onlyForeach(array $statements): ForeachStatement
    {
        $found = $this->foreaches($statements);
        $this->assertCount(1, $found);

        return $found[0];
    }

    /**
     * @param list<Statement> $statements
     * @return list<ForStatement>
     */
    private function fors(array $statements): array
    {
        $found = [];
        foreach ($this->walk($statements) as $statement) {
            if ($statement instanceof ForStatement) {
                $found[] = $statement;
            }
        }

        return $found;
    }

    /**
     * @param list<Statement> $statements
     */
    private function onlyFor(array $statements): ForStatement
    {
        $found = $this->fors($statements);
        $this->assertCount(1, $found);

        return $found[0];
    }

    /**
     * @param list<Statement> $statements
     */
    private function onlyWhile(array $statements): WhileStatement
    {
        $found = [];
        foreach ($this->walk($statements) as $statement) {
            if ($statement instanceof WhileStatement) {
                $found[] = $statement;
            }
        }
        $this->assertCount(1, $found);

        return $found[0];
    }

    /**
     * @param list<Statement> $statements
     */
    private function onlyDo(array $statements): DoWhileStatement
    {
        $found = [];
        foreach ($this->walk($statements) as $statement) {
            if ($statement instanceof DoWhileStatement) {
                $found[] = $statement;
            }
        }
        $this->assertCount(1, $found);

        return $found[0];
    }

    private function reset(Reconstruction $reconstruction, NaturalLoop $loop): OpaqueStatement
    {
        $this->assertCount(1, $loop->entries);

        return $this->opaqueAt($reconstruction, $loop->entries[0]->opline);
    }

    private function fetch(Reconstruction $reconstruction, NaturalLoop $loop): OpaqueStatement
    {
        return $this->opaqueAt($reconstruction, $loop->headerOpline);
    }

    private function opaqueAt(Reconstruction $reconstruction, int $opline): OpaqueStatement
    {
        $statement = $this->original($reconstruction, $opline);
        $this->assertInstanceOf(OpaqueStatement::class, $statement);

        return $statement;
    }

    private function original(Reconstruction $reconstruction, int $opline): Statement
    {
        foreach ($reconstruction->blocks as $block) {
            foreach ($block->statements as $statement) {
                if ($statement->opline === $opline) {
                    return $statement;
                }
            }
        }
        $this->fail('Missing statement at opline ' . $opline);
    }

    private function instruction(int $opline): \Rephper\Ir\Instruction
    {
        $this->assertNotNull($this->opArray);
        $instruction = $this->opArray->instruction($opline);
        $this->assertNotNull($instruction);

        return $instruction;
    }

    private function assignmentOf(Reconstruction $reconstruction, int $opline): Assignment
    {
        $statement = $this->original($reconstruction, $opline);
        $this->assertInstanceOf(ExpressionStatement::class, $statement);
        $this->assertInstanceOf(Assignment::class, $statement->expression);

        return $statement->expression;
    }

    private function assignment(Reconstruction $reconstruction, string $variable): Assignment
    {
        $found = [];
        foreach ($reconstruction->blocks as $block) {
            foreach ($block->statements as $statement) {
                if ($statement instanceof ExpressionStatement && $statement->expression instanceof Assignment && $statement->expression->variable === $variable) {
                    $found[] = $statement->expression;
                }
            }
        }
        $this->assertCount(1, $found);

        return $found[0];
    }

    private function assignmentOpline(Reconstruction $reconstruction, string $variable): int
    {
        return $this->assignment($reconstruction, $variable)->opline;
    }

    private function branchResolved(Reconstruction $reconstruction, int $opline): Expression
    {
        $resolved = $this->opaqueAt($reconstruction, $opline)->instruction->op1->resolved;
        $this->assertInstanceOf(Expression::class, $resolved);
        $this->assertNotInstanceOf(Unresolved::class, $resolved);

        return $resolved;
    }

    private function control(LoopAnalysis $analysis, string $opcode, int $level): LoopControl
    {
        $found = [];
        foreach ($analysis->controls as $control) {
            if ($control->opcode === $opcode && $control->level === $level) {
                $found[] = $control;
            }
        }
        $this->assertCount(1, $found);

        return $found[0];
    }

    private function assertVariable(ForeachStatement $foreach, string $name): void
    {
        $this->assertInstanceOf(VariableRead::class, $foreach->iterable);
        $this->assertSame($name, $foreach->iterable->name);
    }

    /**
     * @param list<string> $arguments
     */
    private function assertCall(Statement $statement, string $name, array $arguments): void
    {
        $this->assertInstanceOf(ExpressionStatement::class, $statement);
        $this->assertInstanceOf(FunctionCall::class, $statement->expression);
        $this->assertSame($name, $statement->expression->name);
        $names = [];
        foreach ($statement->expression->arguments as $argument) {
            $this->assertInstanceOf(VariableRead::class, $argument->value);
            $names[] = $argument->value->name;
        }
        $this->assertSame($arguments, $names);
    }

    private function assertAssignment(Statement $statement, string $variable): void
    {
        $this->assertInstanceOf(ExpressionStatement::class, $statement);
        $this->assertInstanceOf(Assignment::class, $statement->expression);
        $this->assertSame($variable, $statement->expression->variable);
    }

    private function opcode(Statement $statement): string
    {
        $this->assertInstanceOf(OpaqueStatement::class, $statement);

        return $statement->instruction->opcode;
    }

    /**
     * @param list<Statement> $statements
     * @return array<int, true>
     */
    private function oplines(array $statements): array
    {
        $oplines = [];
        foreach ($this->walk($statements) as $statement) {
            $oplines[$statement->opline] = true;
        }

        return $oplines;
    }

    /**
     * @param list<Statement> $statements
     */
    private function occurrences(array $statements, Expression $needle): int
    {
        $count = 0;
        foreach ($this->walk($statements) as $statement) {
            if ($statement instanceof ForeachStatement && $statement->iterable === $needle) {
                $count++;
            }
            if ($statement instanceof ExpressionStatement && $statement->expression === $needle) {
                $count++;
            }
            if ($statement instanceof ExpressionStatement && $statement->expression instanceof Assignment && $statement->expression->value === $needle) {
                $count++;
            }
            if ($statement instanceof ReturnStatement && $statement->value === $needle) {
                $count++;
            }
            if ($statement instanceof ThrowStatement && $statement->exception === $needle) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param list<Statement> $statements
     */
    private function assertNoIteratorOpcodes(array $statements): void
    {
        foreach ($this->walk($statements) as $statement) {
            if (!$statement instanceof OpaqueStatement) {
                continue;
            }
            $this->assertFalse(str_starts_with($statement->instruction->opcode, 'FE_RESET'));
            $this->assertFalse(str_starts_with($statement->instruction->opcode, 'FE_FETCH'));
            $this->assertNotSame('FE_FREE', $statement->instruction->opcode);
        }
    }

    private function assertNoUnstructured(StructuredStatements $structured): void
    {
        foreach ($structured->diagnostics as $diagnostic) {
            $this->assertNotSame('unstructured', $diagnostic->kind);
            $this->assertStringNotContainsString('reachable block was left outside', $diagnostic->reason);
        }
    }

    private function unstructured(StructuredStatements $structured): \Rephper\Expr\Diagnostic
    {
        $found = [];
        foreach ($structured->diagnostics as $diagnostic) {
            if ($diagnostic->kind === 'unstructured') {
                $found[] = $diagnostic;
            }
        }
        $this->assertCount(1, $found);

        return $found[0];
    }

    private function oplineOf(Reconstruction $reconstruction, string $opcode): int
    {
        $found = [];
        foreach ($reconstruction->blocks as $block) {
            foreach ($block->statements as $statement) {
                if ($statement instanceof OpaqueStatement && $statement->instruction->opcode === $opcode) {
                    $found[] = $statement->opline;
                }
            }
        }
        $this->assertCount(1, $found);

        return $found[0];
    }

    /**
     * @param list<Statement> $statements
     */
    private function statementAt(array $statements, int $opline): Statement
    {
        foreach ($this->walk($statements) as $statement) {
            if ($statement->opline === $opline && !$statement instanceof ForeachStatement && !$statement instanceof ForStatement && !$statement instanceof IfStatement) {
                return $statement;
            }
        }
        $this->fail('Missing structured statement at opline ' . $opline);
    }

    /**
     * @return list<Statement>
     */
    private function flat(Reconstruction $reconstruction): array
    {
        $statements = [];
        foreach ($reconstruction->blocks as $block) {
            foreach ($block->statements as $statement) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * @param list<Statement> $statements
     * @return list<Statement>
     */
    private function walk(array $statements): array
    {
        $found = [];
        foreach ($statements as $statement) {
            $found[] = $statement;
            if ($statement instanceof ForeachStatement || $statement instanceof ForStatement || $statement instanceof WhileStatement || $statement instanceof DoWhileStatement) {
                foreach ($this->walk($statement->body) as $nested) {
                    $found[] = $nested;
                }
            } elseif ($statement instanceof IfStatement) {
                foreach ($statement->branches as $branch) {
                    foreach ($this->walk($branch->statements) as $nested) {
                        $found[] = $nested;
                    }
                }
                if ($statement->elseStatements !== null) {
                    foreach ($this->walk($statement->elseStatements) as $nested) {
                        $found[] = $nested;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @return list<Statement>
     */
    private function actualLeaves(StructuredStatements $structured): array
    {
        $leaves = [];
        foreach ($this->walk($structured->statements) as $statement) {
            if ($statement instanceof ForeachStatement || $statement instanceof ForStatement || $statement instanceof WhileStatement || $statement instanceof DoWhileStatement || $statement instanceof IfStatement) {
                continue;
            }
            if ($statement instanceof BreakStatement || $statement instanceof ContinueStatement) {
                continue;
            }
            $leaves[] = $statement;
        }

        return $leaves;
    }

    /**
     * @return list<Statement>
     */
    private function expectedLeaves(Reconstruction $reconstruction, StructuredStatements $structured): array
    {
        $actual = $this->actualLeaves($structured);
        $consumed = [];
        $keyOplines = [];
        foreach ($this->walk($structured->statements) as $statement) {
            if ($statement instanceof ForeachStatement && $statement->key !== null) {
                $keyOplines[$statement->key->opline] = true;
            }
            $expressions = [];
            if ($statement instanceof ForStatement) {
                $expressions = [...$statement->initializers, ...$statement->updates];
                if ($statement->condition instanceof Expression) {
                    $expressions[] = $statement->condition;
                }
            } elseif ($statement instanceof WhileStatement || $statement instanceof DoWhileStatement) {
                $expressions[] = $statement->condition;
            }
            foreach ($expressions as $expression) {
                $consumed[spl_object_id($expression)] = true;
            }
        }
        $expected = [];
        foreach ($reconstruction->blocks as $block) {
            foreach ($block->statements as $statement) {
                $opcode = $statement instanceof OpaqueStatement ? $statement->instruction->opcode : '';
                if (
                    $statement instanceof OpaqueStatement
                    && (
                        in_array($opcode, ['JMPZ', 'JMPNZ', 'FE_FREE'], true)
                        || str_starts_with($opcode, 'FE_RESET')
                        || str_starts_with($opcode, 'FE_FETCH')
                    )
                    && !in_array($statement, $actual, true)
                ) {
                    continue;
                }
                if ($statement instanceof ExpressionStatement && isset($keyOplines[$statement->opline]) && !in_array($statement, $actual, true)) {
                    continue;
                }
                if ($statement instanceof ExpressionStatement && isset($consumed[spl_object_id($statement->expression)])) {
                    continue;
                }
                if ($statement instanceof OpaqueStatement && isset($consumed[spl_object_id($statement->instruction)])) {
                    continue;
                }
                $expected[] = $statement;
            }
        }

        return $expected;
    }

    private function assertStructuredControls(LoopAnalysis $analysis, StructuredStatements $structured, string $name): void
    {
        $ids = [];
        foreach ($this->walk($structured->statements) as $statement) {
            if ($statement instanceof ForeachStatement || $statement instanceof ForStatement || $statement instanceof WhileStatement || $statement instanceof DoWhileStatement) {
                $ids[$statement->loopId] = true;
            }
        }
        foreach ($analysis->controls as $control) {
            $statement = null;
            foreach ($this->walk($structured->statements) as $candidate) {
                if (
                    $candidate->opline === $control->opline
                    && !$candidate instanceof ForeachStatement
                    && !$candidate instanceof ForStatement
                    && !$candidate instanceof WhileStatement
                    && !$candidate instanceof DoWhileStatement
                    && !$candidate instanceof IfStatement
                ) {
                    $statement = $candidate;
                    break;
                }
            }
            if ($control->loopId !== null && isset($ids[$control->loopId])) {
                $this->assertInstanceOf($control->opcode === 'BRK' ? BreakStatement::class : ContinueStatement::class, $statement, $name);
                $this->assertSame($control->level, $statement->level, $name);
                $this->assertSame($control->loopId, $statement->loopId, $name);
                $this->assertSame($control->line, $statement->line, $name);
            }
        }
    }
}
