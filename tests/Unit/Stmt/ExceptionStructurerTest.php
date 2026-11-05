<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Stmt;

use PHPUnit\Framework\TestCase;
use Rephper\Cfg\Builder;
use Rephper\Cfg\CfgException;
use Rephper\Cfg\Edge;
use Rephper\Cfg\Graph;
use Rephper\Cfg\LoopAnalyzer;
use Rephper\Expr\Assignment;
use Rephper\Expr\Binary;
use Rephper\Expr\FunctionCall;
use Rephper\Expr\Literal;
use Rephper\Expr\MatchExpression;
use Rephper\Expr\Opaque;
use Rephper\Expr\Reconstructor as ExpressionReconstructor;
use Rephper\Expr\Unresolved;
use Rephper\Ir\Document;
use Rephper\Ir\Reader;
use Rephper\Stmt\BreakStatement;
use Rephper\Stmt\ContinueStatement;
use Rephper\Stmt\ExceptionStructurer;
use Rephper\Stmt\ExpressionStatement;
use Rephper\Stmt\FinallyClause;
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
use Rephper\Stmt\SwitchBreakStatement;
use Rephper\Stmt\SwitchStatement;
use Rephper\Stmt\ThrowStatement;
use Rephper\Stmt\TryStatement;
use Rephper\Stmt\WhileStatement;

final class ExceptionStructurerTest extends TestCase
{
    public function testASimpleTryCatchKeepsTheBodyTheClauseAndTheContinuation(): void
    {
        [, $reconstruction, $structured] = $this->prepare('simple');
        $try = $this->onlyTry($structured->statements);
        $call = $this->onlyCall($try->body, 'operation');
        $recover = $this->onlyCall($try->catches[0]->body, 'recover');

        $this->assertSame('RuntimeException', $try->catches[0]->types[0]);
        $this->assertSame(['RuntimeException'], $try->catches[0]->types);
        $this->assertSame('exception', $try->catches[0]->variable);
        $this->assertSame([5], $try->catches[0]->catchOplines);
        $this->assertSame($call, $this->originalCall($reconstruction, 'operation'));
        $this->assertSame($recover, $this->originalCall($reconstruction, 'recover'));
        $this->assertInstanceOf(ReturnStatement::class, $structured->statements[1]);
        $this->assertSame($try->continuationOpline, 9);
        $this->assertGreaterThanOrEqual($try->continuationOpline, $structured->statements[1]->opline);
        $this->assertNoUnstructured($structured);
    }

    public function testMultipleCatchClausesStaySeparateAndOrdered(): void
    {
        [, , $structured] = $this->prepare('multi');
        $try = $this->onlyTry($structured->statements);

        $this->assertCount(2, $try->catches);
        $this->assertSame(['RuntimeException'], $try->catches[0]->types);
        $this->assertSame(['LogicException'], $try->catches[1]->types);
        $this->assertSame('exception', $try->catches[0]->variable);
        $this->assertSame('exception', $try->catches[1]->variable);
        $this->assertSame('recover', $this->onlyCall($try->catches[0]->body, 'recover')->name);
        $this->assertSame('report', $this->onlyCall($try->catches[1]->body, 'report')->name);
        $this->assertLessThan($try->catches[1]->opline, $try->catches[0]->opline);
        $this->assertNoUnstructured($structured);
    }

    public function testAUnionCatchIsOneClauseWithBothTypes(): void
    {
        [, , $structured] = $this->prepare('union');
        $try = $this->onlyTry($structured->statements);

        $this->assertCount(1, $try->catches);
        $this->assertSame(['RuntimeException', 'LogicException'], $try->catches[0]->types);
        $this->assertSame('exception', $try->catches[0]->variable);
        $this->assertSame([5, 7], $try->catches[0]->catchOplines);
        $this->assertSame('recover', $this->onlyCall($try->catches[0]->body, 'recover')->name);
        $this->assertNoUnstructured($structured);
    }

    public function testAUnionClauseStaysDistinctFromTheFollowingClause(): void
    {
        [, , $structured] = $this->prepare('mixed');
        $try = $this->onlyTry($structured->statements);

        $this->assertCount(2, $try->catches);
        $this->assertSame(['RuntimeException', 'LogicException'], $try->catches[0]->types);
        $this->assertSame('exception', $try->catches[0]->variable);
        $this->assertSame(['Error'], $try->catches[1]->types);
        $this->assertSame('error', $try->catches[1]->variable);
        $this->assertSame('recover', $this->onlyCall($try->catches[0]->body, 'recover')->name);
        $this->assertSame('report', $this->onlyCall($try->catches[1]->body, 'report')->name);
    }

    public function testACatchWithoutAVariableRecordsNoName(): void
    {
        [, , $structured] = $this->prepare('noVar');
        $clause = $this->onlyTry($structured->statements)->catches[0];

        $this->assertNull($clause->variable);
        $this->assertSame(['RuntimeException'], $clause->types);
        $this->assertSame('recover', $this->onlyCall($clause->body, 'recover')->name);
    }

    public function testAnEmptyCatchHasAnEmptyBody(): void
    {
        [, , $structured] = $this->prepare('emptyCatch');
        $clause = $this->onlyTry($structured->statements)->catches[0];

        $this->assertNull($clause->variable);
        $this->assertSame([], $clause->body);
    }

    public function testEmptyClausesOnDifferentLinesStaySeparate(): void
    {
        [, , $structured] = $this->prepare('splitEmpty');
        $try = $this->onlyTry($structured->statements);

        $this->assertCount(2, $try->catches);
        $this->assertSame(['RuntimeException'], $try->catches[0]->types);
        $this->assertSame(['LogicException'], $try->catches[1]->types);
        $this->assertNull($try->catches[0]->variable);
        $this->assertNull($try->catches[1]->variable);
        $this->assertSame([], $try->catches[0]->body);
        $this->assertSame([], $try->catches[1]->body);
        $this->assertNotSame($try->catches[0]->line, $try->catches[1]->line);
    }

    public function testThrowableIsTheCatchType(): void
    {
        [, , $structured] = $this->prepare('throwableCatch');

        $this->assertSame(['Throwable'], $this->onlyTry($structured->statements)->catches[0]->types);
    }

    public function testAnExplicitThrowStaysInsideTheTry(): void
    {
        [, $reconstruction, $structured] = $this->prepare('explicitThrow');
        $try = $this->onlyTry($structured->statements);
        $thrown = $this->onlyInstance($try->body, ThrowStatement::class);

        $this->assertSame($this->onlyInstance($this->flat($reconstruction), ThrowStatement::class), $thrown);
        $this->assertSame('recover', $this->onlyCall($try->catches[0]->body, 'recover')->name);
    }

    public function testAnImplicitlyThrowableDivisionStaysInsideTheTry(): void
    {
        [, , $structured] = $this->prepare('implicit');
        $try = $this->onlyTry($structured->statements);
        $assignment = $try->body[0];

        $this->assertInstanceOf(ExpressionStatement::class, $assignment);
        $this->assertInstanceOf(Assignment::class, $assignment->expression);
        $this->assertInstanceOf(Binary::class, $assignment->expression->value);
        $this->assertSame('/', $assignment->expression->value->operator);
        $this->assertSame(['DivisionByZeroError'], $try->catches[0]->types);
        $this->assertSame('exception', $try->catches[0]->variable);
    }

    public function testAReturnInsideTryIsNotTheEndOfTheProtectedBody(): void
    {
        [, , $structured] = $this->prepare('returnInTry');
        $try = $this->onlyTry($structured->statements);
        $conditional = $try->body[0];

        $this->assertInstanceOf(IfStatement::class, $conditional);
        $this->assertNull($conditional->elseStatements);
        $this->assertInstanceOf(ReturnStatement::class, $conditional->branches[0]->statements[0]);
        $this->assertSame('operation', $this->onlyCall($try->body, 'operation')->name);
        $this->assertInstanceOf(ReturnStatement::class, $structured->statements[1]);
        $this->assertNotSame($conditional->branches[0]->statements[0], $structured->statements[1]);
    }

    public function testAReturnInsideCatchIsNotTheContinuation(): void
    {
        [, , $structured] = $this->prepare('returnInCatch');
        $try = $this->onlyTry($structured->statements);
        $returned = $try->catches[0]->body[0];

        $this->assertInstanceOf(ReturnStatement::class, $returned);
        $this->assertInstanceOf(ReturnStatement::class, $structured->statements[1]);
        $this->assertNotSame($returned, $structured->statements[1]);
        $this->assertSame(1, $returned->value->value ?? null);
        $this->assertSame(0, $structured->statements[1]->value->value ?? null);
    }

    public function testAThrowInsideCatchStaysInThatClause(): void
    {
        [, , $structured] = $this->prepare('throwInCatch');
        $try = $this->onlyTry($structured->statements);

        $this->assertInstanceOf(ThrowStatement::class, $try->catches[0]->body[array_key_last($try->catches[0]->body)]);
        $this->assertSame('operation', $this->onlyCall($try->body, 'operation')->name);
    }

    public function testARethrowUsesTheCaughtVariable(): void
    {
        [, , $structured] = $this->prepare('rethrow');
        $try = $this->onlyTry($structured->statements);
        $thrown = $try->catches[0]->body[0];

        $this->assertInstanceOf(ThrowStatement::class, $thrown);
        $this->assertSame('exception', $thrown->exception->name ?? null);
    }

    public function testAConditionalInsideTryKeepsBothBranches(): void
    {
        [, , $structured] = $this->prepare('condInTry');
        $try = $this->onlyTry($structured->statements);
        $conditional = $try->body[0];

        $this->assertInstanceOf(IfStatement::class, $conditional);
        $this->assertSame('operation', $this->onlyCall($conditional->branches[0]->statements, 'operation')->name);
        $this->assertNotNull($conditional->elseStatements);
        $this->assertSame('recover', $this->onlyCall($conditional->elseStatements, 'recover')->name);
        $this->assertSame('report', $this->onlyCall($try->catches[0]->body, 'report')->name);
    }

    public function testAConditionalInsideCatchKeepsBothBranches(): void
    {
        [, , $structured] = $this->prepare('condInCatch');
        $conditional = $this->onlyTry($structured->statements)->catches[0]->body[0];

        $this->assertInstanceOf(IfStatement::class, $conditional);
        $this->assertSame('recover', $this->onlyCall($conditional->branches[0]->statements, 'recover')->name);
        $this->assertNotNull($conditional->elseStatements);
        $this->assertSame('report', $this->onlyCall($conditional->elseStatements, 'report')->name);
    }

    public function testATryInsideAnIfStaysInThatBranch(): void
    {
        [, , $structured] = $this->prepare('ifAroundTry');
        $conditional = $structured->statements[0];

        $this->assertInstanceOf(IfStatement::class, $conditional);
        $this->assertInstanceOf(TryStatement::class, $conditional->branches[0]->statements[0]);
        $this->assertSame('operation', $this->onlyCall($conditional->branches[0]->statements[0]->body, 'operation')->name);
        $this->assertNotNull($conditional->elseStatements);
        $this->assertSame('report', $this->onlyCall($conditional->elseStatements, 'report')->name);
    }

    public function testALoopInsideTryStaysInTheProtectedBody(): void
    {
        [, , $structured] = $this->prepare('loopInTry');
        $try = $this->onlyTry($structured->statements);
        $loop = $try->body[0];

        $this->assertInstanceOf(WhileStatement::class, $loop);
        $this->assertSame('operation', $this->onlyCall($loop->body, 'operation')->name);
        $this->assertSame('recover', $this->onlyCall($try->catches[0]->body, 'recover')->name);
        $this->assertNotContains($loop, $try->catches[0]->body);
    }

    public function testALoopInsideCatchIsThatClausesBody(): void
    {
        [, , $structured] = $this->prepare('loopInCatch');
        $try = $this->onlyTry($structured->statements);
        $loop = $try->catches[0]->body[0];

        $this->assertInstanceOf(WhileStatement::class, $loop);
        $this->assertSame('recover', $this->onlyCall($loop->body, 'recover')->name);
        $this->assertSame('operation', $this->onlyCall($try->body, 'operation')->name);
    }

    public function testATryInsideWhileStaysInTheLoopBody(): void
    {
        [, , $structured] = $this->prepare('tryInWhile');
        $loop = $structured->statements[0];

        $this->assertInstanceOf(WhileStatement::class, $loop);
        $this->assertInstanceOf(TryStatement::class, $loop->body[0]);
        $this->assertSame('operation', $this->onlyCall($loop->body[0]->body, 'operation')->name);
        $this->assertInstanceOf(OpaqueStatement::class, $loop->body[1]);
        $this->assertSame('PRE_DEC', $loop->body[1]->instruction->opcode);
    }

    public function testATryInsideForStaysInTheLoopBody(): void
    {
        [, , $structured] = $this->prepare('tryInFor');
        $loop = $structured->statements[0];

        $this->assertInstanceOf(ForStatement::class, $loop);
        $this->assertInstanceOf(TryStatement::class, $loop->body[0]);
        $this->assertSame('operation', $this->onlyCall($loop->body[0]->body, 'operation')->name);
        $this->assertNotSame([], $loop->initializers);
        $this->assertNotSame([], $loop->updates);
    }

    public function testATryInsideForeachStaysInTheLoopBody(): void
    {
        [, $reconstruction, $structured] = $this->prepare('tryInForeach');
        $loop = $structured->statements[0];

        $this->assertInstanceOf(ForeachStatement::class, $loop);
        $this->assertNull($loop->key);
        $this->assertSame('item', $loop->value->name);
        $this->assertFalse($loop->byReference);
        $this->assertInstanceOf(TryStatement::class, $loop->body[0]);
        $returned = $structured->statements[1];
        $original = $this->onlyInstance($this->flat($reconstruction), ReturnStatement::class);
        $this->assertSame($original, $returned);
        $this->assertInstanceOf(Opaque::class, $returned->value);
        $this->assertSame('COUNT', $returned->value->opcode);
    }

    public function testNestedTryCatchNestsTheStatements(): void
    {
        [, , $structured] = $this->prepare('nested');
        $tries = $this->tries($structured->statements);
        $this->assertCount(2, $tries);
        $outer = $tries[0];
        $inner = $outer->body[0];
        $this->assertSame($inner, $tries[1]);

        $this->assertInstanceOf(TryStatement::class, $inner);
        $this->assertSame(['LogicException'], $outer->catches[0]->types);
        $this->assertSame('outer', $outer->catches[0]->variable);
        $this->assertSame(['RuntimeException'], $inner->catches[0]->types);
        $this->assertSame('inner', $inner->catches[0]->variable);
        $this->assertSame('operation', $this->onlyCall($inner->body, 'operation')->name);
        $this->assertSame('recover', $this->onlyCall($inner->catches[0]->body, 'recover')->name);
        $this->assertSame('report', $this->onlyCall($outer->catches[0]->body, 'report')->name);
    }

    public function testAnInnerThrowRemainsInTheInnerCatch(): void
    {
        [$graph, , $structured] = $this->prepare('innerToOuter');
        $tries = $this->tries($structured->statements);
        $this->assertCount(2, $tries);
        $outer = $tries[0];
        $inner = $outer->body[0];
        $this->assertSame($inner, $tries[1]);

        $this->assertInstanceOf(TryStatement::class, $inner);
        $this->assertInstanceOf(ThrowStatement::class, $inner->catches[0]->body[array_key_last($inner->catches[0]->body)]);
        $this->assertSame('report', $this->onlyCall($outer->catches[0]->body, 'report')->name);
        $this->assertSame('outer', $outer->catches[0]->variable);
        $this->assertExceptionTarget($graph, $inner->opline, $inner->catchOpline, $inner->catchOpline);
        $this->assertExceptionTarget($graph, $inner->catchOpline, $outer->catchOpline, $outer->catchOpline);
    }

    public function testASwitchInsideCatchKeepsItsCases(): void
    {
        [, , $structured] = $this->prepare('switchInCatch');
        $switch = $this->onlyTry($structured->statements)->catches[0]->body[0];

        $this->assertInstanceOf(SwitchStatement::class, $switch);
        $this->assertCount(6, $switch->cases);
        $this->assertTrue($switch->cases[5]->isDefault);
        $this->assertSame('recover', $this->onlyCall($switch->cases[0]->body, 'recover')->name);
        $this->assertInstanceOf(SwitchBreakStatement::class, $switch->cases[0]->body[1]);
        $this->assertSame($switch->switchId, $switch->cases[0]->body[1]->switchId);
    }

    public function testAMatchInsideCatchStaysAMatchExpression(): void
    {
        [, , $structured] = $this->prepare('matchInCatch');
        $statement = $this->onlyTry($structured->statements)->catches[0]->body[0];

        $this->assertInstanceOf(ExpressionStatement::class, $statement);
        $this->assertInstanceOf(Assignment::class, $statement->expression);
        $this->assertInstanceOf(MatchExpression::class, $statement->expression->value);
        $this->assertCount(3, $statement->expression->value->arms);
        $this->assertTrue($statement->expression->value->arms[2]->isDefault);
        $this->assertNull($statement->expression->value->unhandledOpline);
        $this->assertSame('recover', $this->onlyCall($this->onlyTry($structured->statements)->catches[0]->body, 'recover')->name);
    }

    public function testTryFinallyKeepsTheBodyAndTheCleanup(): void
    {
        [, $reconstruction, $structured] = $this->prepare('tryFinally');
        $try = $this->onlyTry($structured->statements);

        $this->assertSame([], $try->catches);
        $this->assertNull($try->catchOpline);
        $this->assertSame($this->originalCall($reconstruction, 'operation'), $this->onlyCall($try->body, 'operation'));
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertNoUnstructured($structured);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testTryCatchFinallyKeepsTheCatchAndTheCleanup(): void
    {
        [, $reconstruction, $structured] = $this->prepare('tryCatchFinally');
        $try = $this->onlyTry($structured->statements);

        $this->assertSame(['RuntimeException'], $try->catches[0]->types);
        $this->assertSame($this->originalCall($reconstruction, 'recover'), $this->onlyCall($try->catches[0]->body, 'recover'));
        $this->assertSame($this->originalCall($reconstruction, 'operation'), $this->onlyCall($try->body, 'operation'));
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertNoUnstructured($structured);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testATryThatContainsFinallyStructuresBothRegions(): void
    {
        [, $reconstruction, $structured] = $this->prepare('containsFinally');
        $tries = $this->tries($structured->statements);
        $this->assertCount(2, $tries);
        $outer = $tries[0];
        $inner = $outer->body[0];

        $this->assertSame($inner, $tries[1]);
        $this->assertInstanceOf(TryStatement::class, $inner);
        $this->assertNull($outer->finally);
        $this->assertSame(['RuntimeException'], $outer->catches[0]->types);
        $this->assertSame($this->originalCall($reconstruction, 'operation'), $this->onlyCall($inner->body, 'operation'));
        $this->assertFinallyCall($inner->finally, 'cleanup', $reconstruction);
        $this->assertSame($this->originalCall($reconstruction, 'recover'), $this->onlyCall($outer->catches[0]->body, 'recover'));
        $this->assertNoUnstructured($structured);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testEmptyClausesOnOneLineStayUnstructured(): void
    {
        [, $reconstruction, $structured] = $this->prepare('ambiguous', false);

        $this->assertSame([], $this->tries($structured->statements));
        $this->assertSame([$this->originalCall($reconstruction, 'operation')], $this->calls($structured->statements));
        $this->assertReason($structured, 'Empty catch clauses cannot be distinguished from a union catch.');
        $this->assertCount(2, array_filter(
            $this->flat($reconstruction),
            static fn (Statement $statement): bool => $statement instanceof OpaqueStatement && $statement->instruction->opcode === 'CATCH',
        ));
    }

    public function testAStructuredTryDoesNotFlattenAFollowingFinally(): void
    {
        [, $reconstruction, $structured] = $this->prepare('sibling');
        $tries = $this->tries($structured->statements);
        $this->assertCount(2, $tries);
        $caught = $tries[0];
        $finished = $tries[1];

        $this->assertNull($caught->finally);
        $this->assertSame('recover', $this->onlyCall($caught->catches[0]->body, 'recover')->name);
        $this->assertSame([], $finished->catches);
        $this->assertFinallyCall($finished->finally, 'cleanup', $reconstruction);
        $operations = array_values(array_filter(
            $this->calls($this->flat($reconstruction)),
            static fn (FunctionCall $call): bool => $call->name === 'operation',
        ));
        $this->assertSame($operations[0], $this->onlyCall($caught->body, 'operation'));
        $this->assertSame($operations[1], $this->onlyCall($finished->body, 'operation'));
        $this->assertNoUnstructured($structured);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testLoopStructurerDoesNotTurnATryIntoAConditional(): void
    {
        $opArray = $this->document()->opArrayNamed('simple');
        $this->assertNotNull($opArray);
        $graph = (new Builder())->build($opArray);
        $expressions = (new ExpressionReconstructor())->reconstruct($opArray, $graph);
        $reconstruction = (new Reconstructor())->reconstruct($opArray, $graph, $expressions);
        $edges = $this->edgeSnapshot($graph);
        $structured = (new LoopStructurer())->structure($graph, (new LoopAnalyzer())->analyze($graph, $opArray), $reconstruction);

        $this->assertSame([], $this->tries($structured->statements));
        $this->assertSame($edges, $this->edgeSnapshot($graph));
        $this->assertReason($structured, 'The region contains an exception edge.');
        $this->assertContains('CATCH', $this->opcodes($structured));
    }

    public function testLoopStructurerDoesNotTurnFinallyIntoATry(): void
    {
        $opArray = $this->document('Finally')->opArrayNamed('simple');
        $this->assertNotNull($opArray);
        $graph = (new Builder())->build($opArray);
        $expressions = (new ExpressionReconstructor())->reconstruct($opArray, $graph);
        $reconstruction = (new Reconstructor())->reconstruct($opArray, $graph, $expressions);
        $structured = (new LoopStructurer())->structure($graph, (new LoopAnalyzer())->analyze($graph, $opArray), $reconstruction);

        $this->assertSame([], $this->tries($structured->statements));
        $this->assertReason($structured, 'FAST_CALL is not structured as a conditional.');
    }

    public function testASimpleFinallyKeepsOneCleanup(): void
    {
        [$graph, $reconstruction, $structured] = $this->prepare('simple', true, 'Finally');
        $try = $this->onlyTry($structured->statements);

        $this->assertSame([], $try->catches);
        $this->assertNull($try->catchOpline);
        $this->assertSame($this->originalCall($reconstruction, 'operation'), $this->onlyCall($try->body, 'operation'));
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertInstanceOf(ReturnStatement::class, $structured->statements[1]);
        $this->assertSame(1, $this->finallyReturns($graph));
        $this->assertNoUnstructured($structured);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testTryCatchFinallyKeepsEveryClauseAndOneCleanup(): void
    {
        [$graph, $reconstruction, $structured] = $this->prepare('caught', true, 'Finally');
        $try = $this->onlyTry($structured->statements);

        $this->assertSame(['RuntimeException'], $try->catches[0]->types);
        $this->assertSame('exception', $try->catches[0]->variable);
        $this->assertSame($this->originalCall($reconstruction, 'recover'), $this->onlyCall($try->catches[0]->body, 'recover'));
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertSame(1, $this->finallyReturns($graph));
        $this->assertNoFinallyMachinery($structured);
    }

    public function testMultipleCatchesStayOrderedBesideOneFinally(): void
    {
        [, $reconstruction, $structured] = $this->prepare('multi', true, 'Finally');
        $try = $this->onlyTry($structured->statements);

        $this->assertCount(2, $try->catches);
        $this->assertSame(['RuntimeException'], $try->catches[0]->types);
        $this->assertSame(['LogicException'], $try->catches[1]->types);
        $this->assertLessThan($try->catches[1]->opline, $try->catches[0]->opline);
        $this->assertSame('recover', $this->onlyCall($try->catches[0]->body, 'recover')->name);
        $this->assertSame('report', $this->onlyCall($try->catches[1]->body, 'report')->name);
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testAUnionCatchWithFinallyIsOneClause(): void
    {
        [, $reconstruction, $structured] = $this->prepare('union', true, 'Finally');
        $try = $this->onlyTry($structured->statements);

        $this->assertCount(1, $try->catches);
        $this->assertSame(['RuntimeException', 'LogicException'], $try->catches[0]->types);
        $this->assertSame([5, 7], $try->catches[0]->catchOplines);
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testAReturnInsideTryStaysThereAndTheCleanupRunsOnce(): void
    {
        [$graph, $reconstruction, $structured] = $this->prepare('returnInTry', true, 'Finally');
        $try = $this->onlyTry($structured->statements);
        $returned = $this->onlyInstance($try->body, ReturnStatement::class);

        $this->assertSame($this->onlyInstance($this->flat($reconstruction), ReturnStatement::class), $returned);
        $this->assertInstanceOf(Unresolved::class, $returned->value);
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertSame(2, $this->finallyReturns($graph));
        $this->assertCount(1, $this->ofType($structured->statements, ReturnStatement::class));
        $this->assertNoFinallyMachinery($structured);
    }

    public function testAReturnInsideCatchStillRunsFinally(): void
    {
        [, $reconstruction, $structured] = $this->prepare('returnInCatch', true, 'Finally');
        $try = $this->onlyTry($structured->statements);
        $returned = $try->catches[0]->body[0];

        $this->assertInstanceOf(ReturnStatement::class, $returned);
        $this->assertInstanceOf(Literal::class, $returned->value);
        $this->assertSame(1, $returned->value->value);
        $this->assertInstanceOf(ReturnStatement::class, $structured->statements[1]);
        $this->assertInstanceOf(Literal::class, $structured->statements[1]->value);
        $this->assertSame(0, $structured->statements[1]->value->value);
        $this->assertNotSame($returned, $structured->statements[1]);
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testAnExplicitThrowStaysInsideTryAndCleanupStaysInFinally(): void
    {
        [, $reconstruction, $structured] = $this->prepare('throwInTry', true, 'Finally');
        $try = $this->onlyTry($structured->statements);

        $this->assertSame([], $try->catches);
        $this->assertSame($this->onlyInstance($this->flat($reconstruction), ThrowStatement::class), $this->onlyInstance($try->body, ThrowStatement::class));
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testARethrowStaysInTheCatch(): void
    {
        [, $reconstruction, $structured] = $this->prepare('rethrow', true, 'Finally');
        $try = $this->onlyTry($structured->statements);

        $this->assertInstanceOf(ThrowStatement::class, $try->catches[0]->body[array_key_last($try->catches[0]->body)]);
        $this->assertSame($this->onlyInstance($this->flat($reconstruction), ThrowStatement::class), $this->onlyInstance($try->catches[0]->body, ThrowStatement::class));
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testAnImplicitThrowStaysInsideTry(): void
    {
        [, $reconstruction, $structured] = $this->prepare('implicit', true, 'Finally');
        $try = $this->onlyTry($structured->statements);
        $assignment = $try->body[0];

        $this->assertInstanceOf(ExpressionStatement::class, $assignment);
        $this->assertInstanceOf(Assignment::class, $assignment->expression);
        $this->assertInstanceOf(Binary::class, $assignment->expression->value);
        $this->assertSame('/', $assignment->expression->value->operator);
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testAReturnInsideFinallyOverridesThePendingReturn(): void
    {
        [, , $structured] = $this->prepare('returnInFinally', true, 'Finally');
        $try = $this->onlyTry($structured->statements);
        $pending = $this->onlyInstance($try->body, ReturnStatement::class);
        $finished = $try->finally;

        $this->assertInstanceOf(FinallyClause::class, $finished);
        $override = $this->onlyInstance($finished->body, ReturnStatement::class);
        $this->assertInstanceOf(Unresolved::class, $pending->value);
        $this->assertInstanceOf(Literal::class, $override->value);
        $this->assertSame(1, $override->value->value);
        $this->assertNotSame($pending, $override);
        $this->assertCount(2, $this->ofType($structured->statements, ReturnStatement::class));
        $this->assertNoFinallyMachinery($structured);
    }

    public function testAThrowInsideFinallyStaysInTheFinally(): void
    {
        [, $reconstruction, $structured] = $this->prepare('throwInFinally', true, 'Finally');
        $try = $this->onlyTry($structured->statements);

        $this->assertSame($this->originalCall($reconstruction, 'operation'), $this->onlyCall($try->body, 'operation'));
        $this->assertInstanceOf(FinallyClause::class, $try->finally);
        $this->assertSame(
            $this->onlyInstance($this->flat($reconstruction), ThrowStatement::class),
            $this->onlyInstance($try->finally->body, ThrowStatement::class),
        );
        $this->assertCount(1, $this->ofType($structured->statements, ThrowStatement::class));
        $this->assertNoFinallyMachinery($structured);
    }

    public function testSeveralFastCallsShareOneFinally(): void
    {
        [$graph, $reconstruction, $structured] = $this->prepare('multipleCalls', true, 'Finally');
        $try = $this->onlyTry($structured->statements);
        $conditional = $try->body[0];
        $returns = $this->ofType($try->body, ReturnStatement::class);

        $this->assertInstanceOf(IfStatement::class, $conditional);
        $this->assertNull($conditional->elseStatements);
        $this->assertInstanceOf(ReturnStatement::class, $conditional->branches[0]->statements[0]);
        $this->assertInstanceOf(Literal::class, $conditional->branches[0]->statements[0]->value);
        $this->assertSame(1, $conditional->branches[0]->statements[0]->value->value);
        $this->assertSame($this->originalCall($reconstruction, 'operation'), $this->onlyCall($try->body, 'operation'));
        $this->assertCount(2, $returns);
        $this->assertSame($conditional->branches[0]->statements[0], $returns[0]);
        $this->assertInstanceOf(Literal::class, $returns[1]->value);
        $this->assertSame(0, $returns[1]->value->value);
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertSame(3, $this->finallyReturns($graph));
        $this->assertNoFinallyMachinery($structured);
    }

    public function testBreakThroughFinallyKeepsTheLoopTarget(): void
    {
        [$graph, $reconstruction, $structured] = $this->prepare('breakThrough', true, 'Finally');
        $loop = $structured->statements[0];

        $this->assertInstanceOf(WhileStatement::class, $loop);
        $try = $loop->body[0];
        $this->assertInstanceOf(TryStatement::class, $try);
        $break = $this->onlyInstance($try->body, BreakStatement::class);
        $this->assertSame(1, $break->level);
        $this->assertSame($loop->loopId, $break->loopId);
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertSame(2, $this->finallyReturns($graph));
        $this->assertNoFinallyMachinery($structured);
    }

    public function testContinueThroughFinallyKeepsTheLoopTarget(): void
    {
        [, $reconstruction, $structured] = $this->prepare('continueThrough', true, 'Finally');
        $loop = $structured->statements[0];

        $this->assertInstanceOf(WhileStatement::class, $loop);
        $try = $loop->body[0];
        $this->assertInstanceOf(TryStatement::class, $try);
        $continue = $this->onlyInstance($try->body, ContinueStatement::class);
        $this->assertSame(1, $continue->level);
        $this->assertSame($loop->loopId, $continue->loopId);
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testBreakTwoThroughFinallyLeavesTheOuterLoop(): void
    {
        [, $reconstruction, $structured] = $this->prepare('breakTwo', true, 'Finally');
        $outer = $structured->statements[0];

        $this->assertInstanceOf(WhileStatement::class, $outer);
        $inner = $outer->body[0];
        $this->assertInstanceOf(WhileStatement::class, $inner);
        $try = $inner->body[0];
        $this->assertInstanceOf(TryStatement::class, $try);
        $break = $this->onlyInstance($try->body, BreakStatement::class);
        $this->assertSame(2, $break->level);
        $this->assertSame($outer->loopId, $break->loopId);
        $this->assertNotSame($inner->loopId, $break->loopId);
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testContinueTwoThroughFinallyTargetsTheOuterLoop(): void
    {
        [, $reconstruction, $structured] = $this->prepare('continueTwo', true, 'Finally');
        $outer = $structured->statements[0];

        $this->assertInstanceOf(WhileStatement::class, $outer);
        $inner = $outer->body[0];
        $this->assertInstanceOf(WhileStatement::class, $inner);
        $try = $inner->body[0];
        $this->assertInstanceOf(TryStatement::class, $try);
        $continue = $this->onlyInstance($try->body, ContinueStatement::class);
        $this->assertSame(2, $continue->level);
        $this->assertSame($outer->loopId, $continue->loopId);
        $this->assertNotSame($inner->loopId, $continue->loopId);
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testAConditionalInsideFinallyStaysInsideIt(): void
    {
        [, $reconstruction, $structured] = $this->prepare('condInFinally', true, 'Finally');
        $try = $this->onlyTry($structured->statements);
        $finished = $try->finally;

        $this->assertInstanceOf(FinallyClause::class, $finished);
        $conditional = $finished->body[0];
        $this->assertInstanceOf(IfStatement::class, $conditional);
        $this->assertNotNull($conditional->elseStatements);
        $this->assertSame($this->originalCall($reconstruction, 'recover'), $this->onlyCall($conditional->branches[0]->statements, 'recover'));
        $this->assertSame($this->originalCall($reconstruction, 'report'), $this->onlyCall($conditional->elseStatements, 'report'));
        $this->assertSame($this->originalCall($reconstruction, 'operation'), $this->onlyCall($try->body, 'operation'));
        $this->assertNoFinallyMachinery($structured);
    }

    public function testALoopInsideFinallyKeepsItsOwnBreak(): void
    {
        [, $reconstruction, $structured] = $this->prepare('loopInFinally', true, 'Finally');
        $try = $this->onlyTry($structured->statements);
        $finished = $try->finally;

        $this->assertInstanceOf(FinallyClause::class, $finished);
        $loop = $finished->body[0];
        $this->assertInstanceOf(WhileStatement::class, $loop);
        $this->assertSame($this->originalCall($reconstruction, 'cleanup'), $this->onlyCall($loop->body, 'cleanup'));
        $break = $this->onlyInstance($loop->body, BreakStatement::class);
        $this->assertSame(1, $break->level);
        $this->assertSame($loop->loopId, $break->loopId);
        $this->assertSame($this->originalCall($reconstruction, 'operation'), $this->onlyCall($try->body, 'operation'));
        $this->assertNoFinallyMachinery($structured);
    }

    public function testAFinallyLoopThatNeverReachesItsHeaderStaysUnstructured(): void
    {
        [, $reconstruction, $structured] = $this->prepare('alwaysBreakInFinally', true, 'Finally');

        $this->assertSame([], $this->tries($structured->statements));
        $this->assertSame(
            [$this->originalCall($reconstruction, 'operation'), $this->originalCall($reconstruction, 'cleanup')],
            $this->calls($structured->statements),
        );
        $this->assertReason($structured, 'The branch target is not a forward edge.');
    }

    public function testNestedFinallyBelongsToTheInnerTry(): void
    {
        [, $reconstruction, $structured] = $this->prepare('nested', true, 'Finally');
        $tries = $this->tries($structured->statements);
        $this->assertCount(2, $tries);
        $outer = $tries[0];
        $inner = $outer->body[0];

        $this->assertSame($inner, $tries[1]);
        $this->assertInstanceOf(TryStatement::class, $inner);
        $this->assertSame([], $inner->catches);
        $this->assertSame(['RuntimeException'], $outer->catches[0]->types);
        $this->assertSame($this->originalCall($reconstruction, 'operation'), $this->onlyCall($inner->body, 'operation'));
        $this->assertFinallyCall($inner->finally, 'innerCleanup', $reconstruction);
        $this->assertSame($this->originalCall($reconstruction, 'recover'), $this->onlyCall($outer->catches[0]->body, 'recover'));
        $this->assertFinallyCall($outer->finally, 'outerCleanup', $reconstruction);
        $this->assertNotSame($inner->finally, $outer->finally);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testNestedCatchAndFinallyStayOnTheirOwnRows(): void
    {
        [, $reconstruction, $structured] = $this->prepare('nestedCaught', true, 'Finally');
        $tries = $this->tries($structured->statements);
        $outer = $tries[0];
        $inner = $outer->body[0];

        $this->assertInstanceOf(TryStatement::class, $inner);
        $this->assertSame(['RuntimeException'], $inner->catches[0]->types);
        $this->assertSame(['LogicException'], $outer->catches[0]->types);
        $this->assertSame($this->originalCall($reconstruction, 'recover'), $this->onlyCall($inner->catches[0]->body, 'recover'));
        $this->assertFinallyCall($inner->finally, 'innerCleanup', $reconstruction);
        $this->assertSame($this->originalCall($reconstruction, 'report'), $this->onlyCall($outer->catches[0]->body, 'report'));
        $this->assertFinallyCall($outer->finally, 'outerCleanup', $reconstruction);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testANestedReturnCallsEachFinallyOnce(): void
    {
        [$graph, $reconstruction, $structured] = $this->prepare('chained', true, 'Finally');
        $tries = $this->tries($structured->statements);
        $outer = $tries[0];
        $inner = $outer->body[0];

        $this->assertInstanceOf(TryStatement::class, $inner);
        $returned = $this->onlyInstance($inner->body, ReturnStatement::class);
        $this->assertInstanceOf(Unresolved::class, $returned->value);
        $this->assertSame([$returned], $this->ofType($structured->statements, ReturnStatement::class));
        $this->assertSame([], $this->ofType($outer->finally?->body ?? [], ReturnStatement::class));
        $this->assertFinallyCall($inner->finally, 'innerCleanup', $reconstruction);
        $this->assertFinallyCall($outer->finally, 'outerCleanup', $reconstruction);
        $this->assertSame(4, $this->finallyReturns($graph));
        $this->assertNoFinallyMachinery($structured);
    }

    public function testFinallyInsideALoopStaysInsideTheLoop(): void
    {
        [, $reconstruction, $structured] = $this->prepare('tryInLoop', true, 'Finally');
        $loop = $structured->statements[0];

        $this->assertInstanceOf(WhileStatement::class, $loop);
        $try = $loop->body[0];
        $this->assertInstanceOf(TryStatement::class, $try);
        $this->assertSame($this->originalCall($reconstruction, 'operation'), $this->onlyCall($try->body, 'operation'));
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertSame($try->continuationOpline, $loop->body[1]->opline);
        $this->assertSame($loop->body[1], $this->statementAt($reconstruction, $loop->body[1]->opline));
        $this->assertNoFinallyMachinery($structured);
    }

    public function testALoopInsideTryStaysInsideTheTry(): void
    {
        [, $reconstruction, $structured] = $this->prepare('loopInTry', true, 'Finally');
        $try = $this->onlyTry($structured->statements);
        $loop = $try->body[0];

        $this->assertInstanceOf(WhileStatement::class, $loop);
        $this->assertSame($this->originalCall($reconstruction, 'operation'), $this->onlyCall($loop->body, 'operation'));
        $this->assertFinallyCall($try->finally, 'cleanup', $reconstruction);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testTryCatchInsideFinallyBelongsToTheFinally(): void
    {
        [, $reconstruction, $structured] = $this->prepare('tryInFinally', true, 'Finally');
        $tries = $this->tries($structured->statements);
        $outer = $tries[0];
        $this->assertInstanceOf(TryStatement::class, $outer);
        $finished = $outer->finally;

        $this->assertInstanceOf(FinallyClause::class, $finished);
        $inner = $finished->body[0];
        $this->assertInstanceOf(TryStatement::class, $inner);
        $this->assertNull($inner->finally);
        $this->assertSame(['RuntimeException'], $inner->catches[0]->types);
        $this->assertSame($this->originalCall($reconstruction, 'operation'), $this->onlyCall($outer->body, 'operation'));
        $this->assertSame($this->originalCall($reconstruction, 'recover'), $this->onlyCall($inner->body, 'recover'));
        $this->assertSame($this->originalCall($reconstruction, 'report'), $this->onlyCall($inner->catches[0]->body, 'report'));
        $this->assertCount(2, $this->tries($structured->statements));
        $this->assertNoFinallyMachinery($structured);
    }

    public function testAnExceptionInAnInnerFinallyReachesTheOuterCatch(): void
    {
        [$graph, $reconstruction, $structured] = $this->prepare('innerFinallyToOuterCatch', true, 'Finally');
        $tries = $this->tries($structured->statements);
        $outer = $tries[0];
        $inner = $outer->body[0];

        $this->assertInstanceOf(TryStatement::class, $inner);
        $this->assertNull($outer->finally);
        $this->assertInstanceOf(FinallyClause::class, $inner->finally);
        $thrown = $this->onlyInstance($inner->finally->body, ThrowStatement::class);
        $this->assertSame($this->onlyInstance($this->flat($reconstruction), ThrowStatement::class), $thrown);
        $this->assertSame($this->originalCall($reconstruction, 'recover'), $this->onlyCall($outer->catches[0]->body, 'recover'));
        $this->assertNotNull($outer->catchOpline);
        $this->assertExceptionTarget($graph, $inner->finally->opline, $inner->finally->endOpline, $outer->catchOpline);
        $this->assertNoFinallyMachinery($structured);
    }

    public function testAMismatchedFinallyJumpStaysUnstructured(): void
    {
        [, $reconstruction, $structured] = $this->structureEdited('simple', function (array &$instruction): void {
            if ($instruction['opcode'] !== 'JMP' || ($instruction['index'] ?? null) !== 5) {
                return;
            }
            $instruction['op1']['target'] = 6;
        });

        $this->assertSame([], $this->tries($structured->statements));
        $this->assertSame(
            [$this->originalCall($reconstruction, 'operation'), $this->originalCall($reconstruction, 'cleanup')],
            $this->calls($structured->statements),
        );
        $this->assertReason($structured, 'The jump after FAST_CALL at opline 4 does not target the continuation.');
    }

    public function testAMismatchedFastReturnTemporaryStaysUnstructured(): void
    {
        [, $reconstruction, $structured] = $this->structureEdited('simple', function (array &$instruction): void {
            if ($instruction['opcode'] !== 'FAST_RET') {
                return;
            }
            $instruction['op1']['index'] = 1;
        });

        $this->assertSame([], $this->tries($structured->statements));
        $this->assertSame(
            [$this->originalCall($reconstruction, 'operation'), $this->originalCall($reconstruction, 'cleanup')],
            $this->calls($structured->statements),
        );
        $this->assertReason($structured, 'FAST_RET at opline 9 does not return through the FAST_CALL temporary.');
    }

    public function testAFastCallWithoutAFinallyRowIsRejectedByTheCfg(): void
    {
        $this->expectException(CfgException::class);
        $this->expectExceptionMessage('does not point at a finally handler');
        $this->structureEdited('simple', function (array &$instruction): void {
            if ($instruction['opcode'] !== 'FAST_CALL') {
                return;
            }
            $instruction['op1']['index'] = 99;
        });
    }

    /**
     * @return array{0: Graph, 1: Reconstruction, 2: StructuredStatements}
     */
    private function prepare(string $name, bool $catchConsumed = true, string $fixture = 'TryCatch'): array
    {
        $opArray = $this->document($fixture)->opArrayNamed($name);
        $this->assertNotNull($opArray);
        $graph = (new Builder())->build($opArray);
        $expressions = (new ExpressionReconstructor())->reconstruct($opArray, $graph);
        $reconstruction = (new Reconstructor())->reconstruct($opArray, $graph, $expressions);
        $edges = $this->edgeSnapshot($graph);
        $structured = (new ExceptionStructurer())->structure(
            $opArray,
            $graph,
            (new LoopAnalyzer())->analyze($graph, $opArray),
            $reconstruction,
        );

        $this->assertSame($edges, $this->edgeSnapshot($graph), $name);
        $this->assertExceptionEdgesStaySeparate($graph);
        $this->assertNoDuplicates($structured->statements);
        $this->assertSame($this->calls($this->flat($reconstruction)), $this->calls($structured->statements), $name);
        $this->assertSame($this->ofType($this->flat($reconstruction), ReturnStatement::class), $this->ofType($structured->statements, ReturnStatement::class), $name);
        $this->assertSame($this->ofType($this->flat($reconstruction), ThrowStatement::class), $this->ofType($structured->statements, ThrowStatement::class), $name);
        foreach ($this->flat($reconstruction) as $statement) {
            if (!$statement instanceof OpaqueStatement || $statement->instruction->opcode !== 'CATCH') {
                continue;
            }
            $present = in_array($statement, $this->walk($structured->statements), true);
            $this->assertSame(!$catchConsumed, $present, $name . ' CATCH at ' . $statement->opline);
        }

        return [$graph, $reconstruction, $structured];
    }

    private function document(string $fixture = 'TryCatch'): Document
    {
        $json = (string) file_get_contents(dirname(__DIR__, 2) . '/Expected/PHP/8.4/Common/' . $fixture . '.json');
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);

        return (new Reader())->readString($json);
    }

    /**
     * @return list<array{string, list<int>, list<int>, list<string>, list<string>}>
     */
    private function edgeSnapshot(Graph $graph): array
    {
        $snapshot = [];
        foreach ($graph->blocks as $block) {
            $snapshot[] = [
                $block->id,
                array_map(static fn (Edge $edge): int => spl_object_id($edge), $block->successors),
                array_map(static fn (Edge $edge): int => spl_object_id($edge), $block->exceptions),
                $block->predecessors,
                $block->exceptionPredecessors,
            ];
        }

        return $snapshot;
    }

    private function assertExceptionTarget(Graph $graph, int $from, int $until, int $handlerOpline): void
    {
        $handler = null;
        foreach ($graph->blocks as $block) {
            if ($block->start === $handlerOpline) {
                $handler = $block->id;
            }
        }
        $this->assertNotNull($handler);
        $saw = false;
        foreach ($graph->blocks as $block) {
            if ($block->start < $from || $block->start >= $until) {
                continue;
            }
            foreach ($block->exceptions as $edge) {
                $this->assertSame('catch', $edge->kind);
                $this->assertSame($handler, $edge->to);
                foreach ($block->successors as $successor) {
                    $this->assertNotSame($edge, $successor);
                }
                $saw = true;
            }
        }
        $this->assertTrue($saw);
    }

    private function assertExceptionEdgesStaySeparate(Graph $graph): void
    {
        foreach ($graph->blocks as $block) {
            foreach ($block->exceptions as $exception) {
                $this->assertContains($exception->kind, ['catch', 'finally']);
                foreach ($block->successors as $successor) {
                    $this->assertNotSame($exception, $successor);
                }
            }
        }
    }

    /**
     * @param list<Statement> $statements
     */
    private function assertNoDuplicates(array $statements): void
    {
        $seen = [];
        foreach ($this->walk($statements) as $statement) {
            $id = spl_object_id($statement);
            $this->assertArrayNotHasKey($id, $seen);
            $seen[$id] = true;
        }
    }

    /**
     * @param list<Statement> $statements
     */
    private function onlyTry(array $statements): TryStatement
    {
        $tries = $this->tries($statements);
        $this->assertCount(1, $tries);

        return $tries[0];
    }

    /**
     * @param list<Statement> $statements
     * @return list<TryStatement>
     */
    private function tries(array $statements): array
    {
        $tries = [];
        foreach ($this->walk($statements) as $statement) {
            if ($statement instanceof TryStatement) {
                $tries[] = $statement;
            }
        }

        return $tries;
    }

    /**
     * @param list<Statement> $statements
     */
    private function onlyCall(array $statements, string $name): FunctionCall
    {
        $found = null;
        foreach ($this->calls($statements) as $call) {
            if ($call->name !== $name) {
                continue;
            }
            $this->assertNull($found);
            $found = $call;
        }
        $this->assertInstanceOf(FunctionCall::class, $found);

        return $found;
    }

    private function originalCall(Reconstruction $reconstruction, string $name): FunctionCall
    {
        return $this->onlyCall($this->flat($reconstruction), $name);
    }

    /**
     * @param list<Statement> $statements
     * @return list<FunctionCall>
     */
    private function calls(array $statements): array
    {
        $calls = [];
        foreach ($this->walk($statements) as $statement) {
            if ($statement instanceof ExpressionStatement && $statement->expression instanceof FunctionCall) {
                $calls[] = $statement->expression;
            }
        }

        return $calls;
    }

    /**
     * @template T of Statement
     * @param list<Statement> $statements
     * @param class-string<T> $class
     * @return list<T>
     */
    private function ofType(array $statements, string $class): array
    {
        $found = [];
        foreach ($this->walk($statements) as $statement) {
            if ($statement instanceof $class) {
                $found[] = $statement;
            }
        }

        return $found;
    }

    /**
     * @template T of Statement
     * @param list<Statement> $statements
     * @param class-string<T> $class
     * @return T
     */
    private function onlyInstance(array $statements, string $class): Statement
    {
        $found = $this->ofType($statements, $class);
        $this->assertCount(1, $found);

        return $found[0];
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
        $all = [];
        foreach ($statements as $statement) {
            $all[] = $statement;
            foreach ($this->children($statement) as $child) {
                foreach ($this->walk([$child]) as $nested) {
                    $all[] = $nested;
                }
            }
        }

        return $all;
    }

    /**
     * @return list<Statement>
     */
    private function children(Statement $statement): array
    {
        if ($statement instanceof TryStatement) {
            $children = $statement->body;
            foreach ($statement->catches as $catch) {
                foreach ($catch->body as $child) {
                    $children[] = $child;
                }
            }
            foreach ($statement->finally->body ?? [] as $child) {
                $children[] = $child;
            }

            return $children;
        }
        if ($statement instanceof IfStatement) {
            $children = [];
            foreach ($statement->branches as $branch) {
                foreach ($branch->statements as $child) {
                    $children[] = $child;
                }
            }
            foreach ($statement->elseStatements ?? [] as $child) {
                $children[] = $child;
            }

            return $children;
        }
        if ($statement instanceof WhileStatement || $statement instanceof ForStatement || $statement instanceof ForeachStatement) {
            return $statement->body;
        }
        if ($statement instanceof SwitchStatement) {
            $children = [];
            foreach ($statement->cases as $case) {
                foreach ($case->body as $child) {
                    $children[] = $child;
                }
            }

            return $children;
        }

        return [];
    }

    private function assertFinallyCall(?FinallyClause $finally, string $name, Reconstruction $reconstruction): void
    {
        $this->assertInstanceOf(FinallyClause::class, $finally);
        $this->assertSame($this->originalCall($reconstruction, $name), $this->onlyCall($finally->body, $name));
        $this->assertLessThan($finally->endOpline, $finally->opline);
        $this->assertLessThan($finally->opline, $finally->callOpline);
    }

    private function assertNoFinallyMachinery(StructuredStatements $structured): void
    {
        foreach ($this->walk($structured->statements) as $statement) {
            if (!$statement instanceof OpaqueStatement) {
                continue;
            }
            $this->assertNotContains($statement->instruction->opcode, ['FAST_CALL', 'FAST_RET', 'DISCARD_EXCEPTION', 'CATCH']);
        }
        foreach ($structured->diagnostics as $diagnostic) {
            $this->assertNotContains($diagnostic->opcode, ['FAST_CALL', 'FAST_RET', 'DISCARD_EXCEPTION', 'CATCH']);
            $this->assertNotSame('unstructured', $diagnostic->kind, $diagnostic->reason);
        }
    }

    private function finallyReturns(Graph $graph): int
    {
        $count = 0;
        foreach ($graph->blocks as $block) {
            foreach ($block->successors as $edge) {
                if ($edge->kind === 'finally_return') {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function statementAt(Reconstruction $reconstruction, int $opline): Statement
    {
        $found = null;
        foreach ($this->flat($reconstruction) as $statement) {
            if ($statement->opline !== $opline) {
                continue;
            }
            $this->assertNull($found);
            $found = $statement;
        }
        $this->assertInstanceOf(Statement::class, $found);

        return $found;
    }

    /**
     * @param callable(array<string, mixed>): void $edit
     * @return array{0: Graph, 1: Reconstruction, 2: StructuredStatements}
     */
    private function structureEdited(string $name, callable $edit): array
    {
        $decoded = json_decode($this->fixtureJson('Finally'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $arrays = $decoded['op_arrays'] ?? null;
        $this->assertIsArray($arrays);
        $found = false;
        foreach ($arrays as &$opArray) {
            if (!is_array($opArray) || ($opArray['name'] ?? null) !== $name) {
                continue;
            }
            $instructions = $opArray['instructions'] ?? null;
            $this->assertIsArray($instructions);
            foreach ($instructions as &$instruction) {
                $this->assertIsArray($instruction);
                $edit($instruction);
            }
            unset($instruction);
            $opArray['instructions'] = $instructions;
            $found = true;
            break;
        }
        unset($opArray);
        $this->assertTrue($found);
        $decoded['op_arrays'] = $arrays;
        $document = (new Reader())->readString((string) json_encode($decoded, JSON_THROW_ON_ERROR));
        $opArray = $document->opArrayNamed($name);
        $this->assertNotNull($opArray);
        $graph = (new Builder())->build($opArray);
        $edges = $this->edgeSnapshot($graph);
        $expressions = (new ExpressionReconstructor())->reconstruct($opArray, $graph);
        $reconstruction = (new Reconstructor())->reconstruct($opArray, $graph, $expressions);
        $structured = (new ExceptionStructurer())->structure(
            $opArray,
            $graph,
            (new LoopAnalyzer())->analyze($graph, $opArray),
            $reconstruction,
        );
        $this->assertSame($edges, $this->edgeSnapshot($graph));

        return [$graph, $reconstruction, $structured];
    }

    private function fixtureJson(string $fixture): string
    {
        $json = (string) file_get_contents(dirname(__DIR__, 2) . '/Expected/PHP/8.4/Common/' . $fixture . '.json');
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);

        return str_replace('"version_id": 0', '"version_id": 80427', $json);
    }

    private function assertNoUnstructured(StructuredStatements $structured): void
    {
        foreach ($structured->diagnostics as $diagnostic) {
            $this->assertNotSame('unstructured', $diagnostic->kind, $diagnostic->reason);
            $this->assertNotSame('CATCH', $diagnostic->opcode);
        }
    }

    private function assertReason(StructuredStatements $structured, string $reason): void
    {
        $reasons = array_map(static fn ($diagnostic): string => $diagnostic->reason, $structured->diagnostics);
        $this->assertContains($reason, $reasons);
    }

    /**
     * @return list<string>
     */
    private function opcodes(StructuredStatements $structured): array
    {
        $opcodes = [];
        foreach ($structured->diagnostics as $diagnostic) {
            $opcodes[] = $diagnostic->opcode;
        }

        return $opcodes;
    }
}
