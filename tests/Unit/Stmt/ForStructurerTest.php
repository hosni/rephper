<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Stmt;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephper\Cfg\Builder;
use Rephper\Cfg\Graph;
use Rephper\Cfg\LoopAnalysis;
use Rephper\Cfg\LoopAnalyzer;
use Rephper\Cfg\NaturalLoop;
use Rephper\Expr\Assignment;
use Rephper\Expr\Binary;
use Rephper\Expr\Expression;
use Rephper\Expr\FunctionCall;
use Rephper\Expr\Literal;
use Rephper\Expr\Opaque;
use Rephper\Expr\Reconstructor as ExpressionReconstructor;
use Rephper\Expr\VariableRead;
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

final class ForStructurerTest extends TestCase
{
    public function testASimpleForKeepsInitConditionUpdateAndBody(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('simple');
        $for = $this->onlyFor($structured->statements);
        $loop = $this->onlyLoop($analysis);

        $this->assertAssignment($structured->statements[0], 'total');
        $this->assertSame($loop->id, $for->loopId);
        $this->assertSame($loop->headerLine, $for->line);
        $this->assertSame([$this->assignment($reconstruction, 'i')], $for->initializers);
        $this->assertSame($this->resolved($reconstruction, $for->opline), $for->condition);
        $this->assertSame('<', $this->binary($for->condition)->operator);
        $this->assertSame('i', $this->binary($for->condition)->left->name);
        $this->assertSame('n', $this->binary($for->condition)->right->name);
        $this->assertSame([$this->opaque($reconstruction, $loop->continueOpline)], $for->updates);
        $this->assertSame('PRE_INC', $for->updates[0]->opcode);
        $this->assertSame('i', $for->updates[0]->op1->name);
        $this->assertSame([$this->original($reconstruction, $for->body[0]->opline)], $for->body);
        $this->assertSame('PRE_INC', $this->opcode($for->body[0]));
        $this->assertSame('total', $this->opcodeStatement($for->body[0])->op1->name);
        $this->assertInstanceOf(ReturnStatement::class, $structured->statements[2]);
        $this->assertForOrder($for);
    }

    public function testAnEmptyInitializerStaysAStatementBeforeTheLoop(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('emptyInit');
        $for = $this->onlyFor($structured->statements);

        $this->assertSame([], $for->initializers);
        $this->assertAssignment($structured->statements[0], 'i');
        $this->assertSame($this->assignment($reconstruction, 'i'), $structured->statements[0]->expression);
        $this->assertNotSame($structured->statements[0]->expression, $for->updates[0]);
        $this->assertSame($this->onlyLoop($analysis)->continueOpline, $for->updates[0]->opline);
        $this->assertLessThan($for->updates[0]->opline, $for->body[0]->opline);
        $this->assertSame('i', $this->opcodeStatement($for->body[0])->op1->name);
        $this->assertSame('i', $for->updates[0]->op1->name);
    }

    public function testAnEmptyUpdateIsTheCompilerWhilePresentedAsFor(): void
    {
        [$graph, , $analysis, $structured] = $this->prepare('emptyUpdate');
        $for = $this->onlyFor($structured->statements);
        $loop = $this->onlyLoop($analysis);
        $continueBlock = null;
        foreach ($graph->blocks as $block) {
            if ($loop->continueOpline !== null && $block->start <= $loop->continueOpline && $loop->continueOpline < $block->end) {
                $continueBlock = $block;
            }
        }

        $this->assertSame(NaturalLoop::WHILE, $loop->kind);
        $this->assertNotNull($continueBlock);
        $this->assertSame($loop->headerId, $continueBlock->id);
        $this->assertSame([], $for->updates);
        $this->assertSame($loop->id, $for->loopId);
        $this->assertCount(1, $for->initializers);
        $this->assertSame('i', $for->initializers[0]->variable);
        $this->assertAssignment($structured->statements[0], 'total');
        $this->assertSame('<', $this->binary($for->condition)->operator);
        $this->assertCount(2, $for->body);
        $this->assertSame('total', $this->opcodeStatement($for->body[0])->op1->name);
        $this->assertSame('i', $this->opcodeStatement($for->body[1])->op1->name);
        $this->assertLessThan($for->body[1]->opline, $for->body[0]->opline);
        $this->assertSame([], $this->whiles($structured->statements));
    }

    public function testMultipleInitializersAndUpdatesKeepCompiledOrder(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('multiInit');
        $for = $this->onlyFor($structured->statements);

        $this->assertAssignment($structured->statements[0], 'total');
        $this->assertSame(
            [$this->assignment($reconstruction, 'i'), $this->assignment($reconstruction, 'j')],
            $for->initializers,
        );
        $this->assertLessThan($for->initializers[1]->opline, $for->initializers[0]->opline);
        $this->assertSame('j', $this->binary($for->condition)->left->name);
        $this->assertSame(
            [
                $this->opaque($reconstruction, $analysis->loops[0]->continueOpline),
                $for->updates[1],
            ],
            $for->updates,
        );
        $this->assertSame('PRE_INC', $for->updates[0]->opcode);
        $this->assertSame('i', $for->updates[0]->op1->name);
        $this->assertSame('PRE_INC', $for->updates[1]->opcode);
        $this->assertSame('j', $for->updates[1]->op1->name);
        $this->assertForOrder($for);

        [, $updatedReconstruction, $updatedAnalysis, $updated] = $this->prepare('multiUpdate');
        $updatedFor = $this->onlyFor($updated->statements);
        $this->assertSame(
            [$this->opaque($updatedReconstruction, $updatedAnalysis->loops[0]->continueOpline), $updatedFor->updates[1]],
            $updatedFor->updates,
        );
        $this->assertSame('PRE_INC', $updatedFor->updates[0]->opcode);
        $this->assertSame('PRE_DEC', $updatedFor->updates[1]->opcode);
        $this->assertSame('n', $updatedFor->updates[1]->op1->name);
        $this->assertLessThan($updatedFor->updates[1]->opline, $updatedFor->updates[0]->opline);
        $this->assertForOrder($updatedFor);
    }

    public function testAnAssignmentConditionStaysInsideTheFor(): void
    {
        [, $reconstruction, , $structured] = $this->prepare('assignCond');
        $for = $this->onlyFor($structured->statements);
        $condition = $for->condition;

        $this->assertInstanceOf(Assignment::class, $condition);
        $this->assertSame('ok', $condition->variable);
        $this->assertNull($condition->operator);
        $this->assertSame('<', $this->binary($condition->value)->operator);
        $this->assertSame($this->resolved($reconstruction, $for->opline), $condition);
        $this->assertAssignment($structured->statements[0], 'ok');
        $this->assertAssignment($structured->statements[1], 'total');
        $this->assertNotSame($structured->statements[0]->expression, $condition);
        foreach ($this->statements($structured->statements) as $statement) {
            if ($statement instanceof ExpressionStatement && $statement->expression instanceof Assignment) {
                $this->assertNotSame($condition, $statement->expression);
            }
        }
    }

    public function testFunctionCallsStayInInitConditionAndUpdate(): void
    {
        [, , $analysis, $initStructured] = $this->prepare('callInit');
        $init = $this->onlyFor($initStructured->statements);
        $this->assertInstanceOf(Assignment::class, $init->initializers[0]);
        $call = $init->initializers[0]->value;
        $this->assertInstanceOf(FunctionCall::class, $call);
        $this->assertSame('measure', $call->name);
        $this->assertSame(['text'], $this->argumentNames($call));
        $this->assertLessThan($init->initializers[0]->opline, $call->initOpline);
        $this->assertSame($analysis->loops[0]->continueOpline, $init->updates[0]->opline);

        [, $conditionReconstruction, , $conditionStructured] = $this->prepare('callCond');
        $conditionFor = $this->onlyFor($conditionStructured->statements);
        $condition = $conditionFor->condition;
        $this->assertInstanceOf(FunctionCall::class, $condition);
        $this->assertSame('check', $condition->name);
        $this->assertSame(['i', 'n'], $this->argumentNames($condition));
        $this->assertSame($this->resolved($conditionReconstruction, $conditionFor->opline), $condition);
        $this->assertLessThan($condition->opline, $conditionFor->updates[0]->opline);

        [, , $updateAnalysis, $updateStructured] = $this->prepare('callUpdate');
        $updateFor = $this->onlyFor($updateStructured->statements);
        $update = $updateFor->updates[0];
        $this->assertInstanceOf(Assignment::class, $update);
        $this->assertSame('i', $update->variable);
        $this->assertInstanceOf(FunctionCall::class, $update->value);
        $this->assertSame('step', $update->value->name);
        $this->assertSame(['i'], $this->argumentNames($update->value));
        $this->assertSame($updateAnalysis->loops[0]->continueOpline, $update->value->initOpline);
        $this->assertGreaterThan($update->value->initOpline, $update->opline);
        $this->assertLessThan($update->value->initOpline, $updateFor->body[0]->opline);
        $this->assertSame([], array_filter(
            $updateFor->body,
            static fn (Statement $statement): bool => $statement instanceof ExpressionStatement
                && $statement->expression === $update,
        ));
    }

    public function testBreakAndContinueTargetThisFor(): void
    {
        [, , $analysis, $structured] = $this->prepare('breakContinue');
        $for = $this->onlyFor($structured->statements);
        $continue = $this->onlyContinue($for->body[0]->branches[0]->statements);
        $break = $this->onlyBreak($for->body[1]->branches[0]->statements);

        $this->assertSame(1, $continue->level);
        $this->assertSame($for->loopId, $continue->loopId);
        $this->assertSame($this->control($analysis, 'CONT', 1)->opline, $continue->opline);
        $this->assertSame($this->control($analysis, 'CONT', 1)->line, $continue->line);
        $this->assertSame($this->control($analysis, 'CONT', 1)->targetOpline, $analysis->loops[0]->continueOpline);
        $this->assertSame($analysis->loops[0]->continueOpline, $for->updates[0]->opline);
        $this->assertSame(1, $break->level);
        $this->assertSame($for->loopId, $break->loopId);
        $this->assertFalse($this->control($analysis, 'BRK', 1)->outer);
        $this->assertSame('total', $this->opcodeStatement($for->body[2])->op1->name);
        $this->assertNull($for->body[0]->elseStatements);
        $this->assertNull($for->body[1]->elseStatements);
        $this->assertForOrder($for);
    }

    public function testBreakTwoAndContinueTwoNameTheOuterFor(): void
    {
        [, , $brokenAnalysis, $broken] = $this->prepare('breakTwo');
        $outer = $this->onlyFor($broken->statements);
        $inner = $outer->body[0];
        $this->assertInstanceOf(ForStatement::class, $inner);
        $break = $this->onlyBreak($inner->body[0]->branches[0]->statements);
        $this->assertSame($this->loop($brokenAnalysis, $inner->loopId)->parentId, $outer->loopId);
        $this->assertSame(2, $break->level);
        $this->assertSame($outer->loopId, $break->loopId);
        $this->assertNotSame($inner->loopId, $break->loopId);
        $this->assertTrue($this->control($brokenAnalysis, 'BRK', 2)->outer);
        $this->assertSame($outer->loopId, $this->control($brokenAnalysis, 'BRK', 2)->loopId);
        $this->assertNotContains($this->control($brokenAnalysis, 'BRK', 2)->targetId, $this->loop($brokenAnalysis, $inner->loopId)->blocks);

        [, , $continuedAnalysis, $continued] = $this->prepare('continueTwo');
        $continuedOuter = $this->onlyFor($continued->statements);
        $continuedInner = $continuedOuter->body[0];
        $this->assertInstanceOf(ForStatement::class, $continuedInner);
        $continue = $this->onlyContinue($continuedInner->body[0]->branches[0]->statements);
        $this->assertSame(2, $continue->level);
        $this->assertSame($continuedOuter->loopId, $continue->loopId);
        $this->assertTrue($this->control($continuedAnalysis, 'CONT', 2)->outer);
        $this->assertSame(
            $this->loop($continuedAnalysis, $continuedOuter->loopId)->continueOpline,
            $this->control($continuedAnalysis, 'CONT', 2)->targetOpline,
        );
        $this->assertSame(
            $this->loop($continuedAnalysis, $continuedInner->loopId)->continueOpline,
            $continuedInner->updates[0]->opline,
        );
        $this->assertNotSame($continuedInner->updates[0]->opline, $continuedOuter->updates[0]->opline);
        $this->assertSame('total', $this->opcodeStatement($continuedInner->body[1])->op1->name);
    }

    public function testAnIfElseInsideAForKeepsBothArms(): void
    {
        [, , , $structured] = $this->prepare('ifElse');
        $for = $this->onlyFor($structured->statements);
        $conditional = $for->body[0];

        $this->assertInstanceOf(IfStatement::class, $conditional);
        $this->assertNotNull($conditional->elseStatements);
        $comparison = $conditional->branches[0]->condition;
        $this->assertInstanceOf(Binary::class, $comparison);
        $this->assertSame('===', $comparison->operator);
        $this->assertInstanceOf(Binary::class, $comparison->left);
        $this->assertSame('%', $comparison->left->operator);
        $this->assertSame('PRE_INC', $this->opcode($conditional->branches[0]->statements[0]));
        $else = $conditional->elseStatements[0];
        $this->assertInstanceOf(ExpressionStatement::class, $else);
        $this->assertInstanceOf(Assignment::class, $else->expression);
        $this->assertSame('+', $else->expression->operator);
        $this->assertSame('total', $else->expression->variable);
        $this->assertSame('PRE_INC', $for->updates[0]->opcode);
        $this->assertSame('i', $for->updates[0]->op1->name);
        $this->assertForOrder($for);
    }

    public function testNestedForWhileAndDoWhileStayInTheParentBody(): void
    {
        [, , $nestedAnalysis, $nested] = $this->prepare('nestedFor');
        $outer = $this->onlyFor($nested->statements);
        $inner = $outer->body[0];
        $this->assertInstanceOf(ForStatement::class, $inner);
        $this->assertSame($outer->loopId, $this->loop($nestedAnalysis, $inner->loopId)->parentId);
        $this->assertContains($inner->loopId, $this->loop($nestedAnalysis, $outer->loopId)->childIds);
        $this->assertSame('i', $outer->initializers[0]->variable);
        $this->assertSame('j', $inner->initializers[0]->variable);
        $this->assertSame('j', $inner->updates[0]->op1->name);
        $this->assertSame('i', $outer->updates[0]->op1->name);
        $this->assertSame('total', $this->opcodeStatement($inner->body[0])->op1->name);

        [, , $whileAnalysis, $whileStructured] = $this->prepare('forInWhile');
        $while = $this->topWhile($whileStructured->statements);
        $innerFor = $while->body[0];
        $this->assertInstanceOf(ForStatement::class, $innerFor);
        $this->assertSame($while->loopId, $this->loop($whileAnalysis, $innerFor->loopId)->parentId);
        $this->assertSame('j', $innerFor->updates[0]->op1->name);
        $this->assertSame('i', $this->opcodeStatement($while->body[1])->op1->name);
        $this->assertNotSame($innerFor->updates[0], $this->opcodeStatement($while->body[1]));

        [, , , $forStructured] = $this->prepare('whileInFor');
        $for = $this->onlyFor($forStructured->statements);
        $this->assertAssignment($for->body[0], 'j');
        $innerWhile = $for->body[1];
        $this->assertInstanceOf(WhileStatement::class, $innerWhile);
        $this->assertSame('j', $this->binary($innerWhile->condition)->left->name);
        $this->assertSame('i', $for->updates[0]->op1->name);
        $this->assertNotContains($for->updates[0], $this->expressionsIn($innerWhile->body));

        [, , , $doStructured] = $this->prepare('doInFor');
        $doFor = $this->onlyFor($doStructured->statements);
        $this->assertAssignment($doFor->body[0], 'j');
        $do = $doFor->body[1];
        $this->assertInstanceOf(DoWhileStatement::class, $do);
        $this->assertSame('<', $this->binary($do->condition)->operator);
        $this->assertSame('j', $this->binary($do->condition)->left->name);
        $this->assertCount(2, $do->body);
        $this->assertSame('i', $doFor->updates[0]->op1->name);
        $this->assertLessThan($doFor->updates[0]->opline, $do->opline);
        $this->assertNotContains($doFor->updates[0], $this->expressionsIn($do->body));
    }

    public function testReturnAndThrowStayInTheBodyAndTheUpdateIsStillExtracted(): void
    {
        [, , , $returned] = $this->prepare('earlyReturn');
        $for = $this->onlyFor($returned->statements);
        $early = $for->body[0]->branches[0]->statements[0];
        $this->assertInstanceOf(ReturnStatement::class, $early);
        $this->assertInstanceOf(ReturnStatement::class, $returned->statements[2]);
        $this->assertNotSame($early, $returned->statements[2]);
        $this->assertSame('total', $this->opcodeStatement($for->body[1])->op1->name);
        $this->assertSame('i', $for->updates[0]->op1->name);
        $this->assertForOrder($for);

        [, , , $thrown] = $this->prepare('thrown');
        $throwFor = $this->onlyFor($thrown->statements);
        $branch = $throwFor->body[0]->branches[0]->statements;
        $this->assertSame('SEND_VAL_EX', $this->opcode($branch[0]));
        $this->assertSame('DO_FCALL', $this->opcode($branch[1]));
        $this->assertInstanceOf(ThrowStatement::class, $branch[2]);
        $this->assertSame('total', $this->opcodeStatement($throwFor->body[1])->op1->name);
        $this->assertInstanceOf(ReturnStatement::class, $thrown->statements[2]);
        $this->assertSame('i', $throwFor->updates[0]->op1->name);
    }

    public function testAnOmittedConditionIsDistinctFromLiteralTrue(): void
    {
        [, $reconstruction, $analysis, $omittedStructured] = $this->prepare('omitted');
        $omitted = $this->onlyFor($omittedStructured->statements);
        $resolved = $this->resolved($reconstruction, $omitted->opline);

        $this->assertSame(NaturalLoop::WHILE, $this->onlyLoop($analysis)->kind);
        $this->assertNull($omitted->condition);
        $this->assertInstanceOf(Literal::class, $resolved);
        $this->assertTrue($resolved->value);
        $this->assertSame([], $omitted->initializers);
        $this->assertSame([], $omitted->updates);
        $this->assertAssignment($omittedStructured->statements[0], 'i');
        $this->assertSame(1, $this->onlyBreak($omitted->body[0]->branches[0]->statements)->level);
        $this->assertSame($omitted->loopId, $this->onlyBreak($omitted->body[0]->branches[0]->statements)->loopId);

        [, $trueReconstruction, , $trueStructured] = $this->prepare('writtenTrue');
        $this->assertSame([], $this->fors($trueStructured->statements));
        $while = $this->onlyWhile($trueStructured->statements);
        $this->assertInstanceOf(Literal::class, $while->condition);
        $this->assertTrue($while->condition->value);
        $this->assertSame($this->resolved($trueReconstruction, $while->opline), $while->condition);

        [, $updateReconstruction, $updateAnalysis, $updateStructured] = $this->prepare('omittedUpdate');
        $updateFor = $this->onlyFor($updateStructured->statements);
        $this->assertSame(NaturalLoop::FOR, $this->onlyLoop($updateAnalysis)->kind);
        $this->assertInstanceOf(Literal::class, $updateFor->condition);
        $this->assertTrue($updateFor->condition->value);
        $this->assertSame($this->resolved($updateReconstruction, $updateFor->opline), $updateFor->condition);
        $this->assertSame('i', $updateFor->initializers[0]->variable);
        $this->assertSame($updateAnalysis->loops[0]->continueOpline, $updateFor->updates[0]->opline);
    }

    public function testAnAmbiguousForStaysUnstructuredAndKeepsTheAssignment(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('ambiguous');

        $this->assertSame(NaturalLoop::UNKNOWN, $this->onlyLoop($analysis)->kind);
        $this->assertStringContainsString('COALESCE', $this->onlyLoop($analysis)->reason);
        $this->assertSame([], $this->fors($structured->statements));
        $this->assertAssignment($structured->statements[0], 'n');
        $this->assertAssignment($structured->statements[1], 'i');
        $this->assertSame('QM_ASSIGN', $this->opcode($this->statementAt($structured->statements, $this->oplineOf($reconstruction, 'QM_ASSIGN'))));
        $this->assertSame('COALESCE', $this->diagnostic($structured, 'unstructured')->opcode);
        $this->assertStringContainsString('COALESCE', $this->diagnostic($structured, 'unstructured')->reason);
        foreach ($structured->diagnostics as $diagnostic) {
            $this->assertStringNotContainsString('reachable block was left outside', $diagnostic->reason);
        }

        [, , $eachAnalysis, $each] = $this->prepare('foreachInside');
        $this->assertSame(NaturalLoop::FOREACH, $eachAnalysis->loops[0]->kind);
        $this->assertSame(NaturalLoop::FOR, $eachAnalysis->loops[1]->kind);
        $for = $this->onlyFor($each->statements);
        $this->assertSame($eachAnalysis->loops[1]->id, $for->loopId);
        $this->assertAssignment($each->statements[0], 'total');
        $this->assertSame('i', $for->initializers[0]->variable);
        $this->assertSame('PRE_INC', $for->updates[0]->opcode);
        $this->assertSame($eachAnalysis->loops[1]->continueOpline, $for->updates[0]->opline);
        $this->assertCount(1, $for->body);
        $this->assertInstanceOf(ForeachStatement::class, $for->body[0]);
        $this->assertSame($eachAnalysis->loops[0]->id, $for->body[0]->loopId);
        $this->assertNull($for->body[0]->key);
        $this->assertFalse($for->body[0]->byReference);
        $this->assertInstanceOf(VariableRead::class, $for->body[0]->iterable);
        $this->assertSame('rows', $for->body[0]->iterable->name);
        $this->assertSame('row', $for->body[0]->value->name);
        $this->assertInstanceOf(ExpressionStatement::class, $for->body[0]->body[0]);
        $this->assertInstanceOf(Assignment::class, $for->body[0]->body[0]->expression);
        $this->assertSame('total', $for->body[0]->body[0]->expression->variable);
        $this->assertSame('+', $for->body[0]->body[0]->expression->operator);
        foreach ($each->diagnostics as $diagnostic) {
            $this->assertNotSame('unstructured', $diagnostic->kind);
        }
    }

    public function testInitializerLinesThatAreNotTheJumpLineStayOutside(): void
    {
        [, $reconstruction, , $structured] = $this->prepare('multilineInit');
        $for = $this->onlyFor($structured->statements);

        $this->assertAssignment($structured->statements[0], 'total');
        $this->assertAssignment($structured->statements[1], 'i');
        $this->assertSame([$this->assignment($reconstruction, 'j')], $for->initializers);
        $this->assertSame('i', $for->updates[0]->op1->name);
        $this->assertSame('j', $for->updates[1]->op1->name);
        $this->assertLessThan($for->initializers[0]->line, $structured->statements[1]->line);

        [, , , $same] = $this->prepare('sameLine');
        $sameFor = $this->onlyFor($same->statements);
        $this->assertSame('total', $sameFor->initializers[0]->variable);
        $this->assertSame('i', $sameFor->initializers[1]->variable);
        $this->assertSame($sameFor->initializers[0]->line, $sameFor->initializers[1]->line);
        $this->assertSame([], array_filter(
            $same->statements,
            static fn (Statement $statement): bool => $statement instanceof ExpressionStatement,
        ));
    }

    public function testWhileAssignmentConditionRemainsTheLoopCondition(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('whileAssign');
        $while = $this->onlyWhile($structured->statements);
        $condition = $while->condition;

        $this->assertSame(NaturalLoop::WHILE, $this->onlyLoop($analysis)->kind);
        $this->assertInstanceOf(Assignment::class, $condition);
        $this->assertSame('line', $condition->variable);
        $this->assertNull($condition->operator);
        $this->assertInstanceOf(FunctionCall::class, $condition->value);
        $this->assertSame('fgets', $condition->value->name);
        $this->assertSame(['handle'], $this->argumentNames($condition->value));
        $this->assertSame($this->resolved($reconstruction, $while->opline), $condition);
        $this->assertAssignment($structured->statements[0], 'total');
        $this->assertInstanceOf(ExpressionStatement::class, $while->body[0]);
        $this->assertInstanceOf(FunctionCall::class, $while->body[0]->expression);
        $this->assertSame('process', $while->body[0]->expression->name);
        $this->assertSame(['line'], $this->argumentNames($while->body[0]->expression));
        $this->assertSame('PRE_INC', $this->opcode($while->body[1]));
        $this->assertSame([], $this->fors($structured->statements));
        foreach ($this->statements($structured->statements) as $statement) {
            if ($statement instanceof ExpressionStatement && $statement->expression instanceof Assignment) {
                $this->assertNotSame('line', $statement->expression->variable);
            }
        }
    }

    public function testConditionalStructurerStillRefusesAFor(): void
    {
        [, $reconstruction, , , $graph] = $this->prepare('simple');
        $structured = (new ConditionalStructurer())->structure($graph, $reconstruction);

        $this->assertSame($this->flat($reconstruction), $structured->statements);
        $failure = $structured->diagnostics[array_key_last($structured->diagnostics)];
        $this->assertSame('unstructured', $failure->kind);
        foreach ($structured->statements as $statement) {
            $this->assertNotInstanceOf(ForStatement::class, $statement);
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
            'measure', 'check', 'step', 'process', 'simple', 'emptyInit', 'emptyUpdate', 'multiInit',
            'multiUpdate', 'assignCond', 'callInit', 'callCond', 'callUpdate', 'breakContinue',
            'breakTwo', 'continueTwo', 'ifElse', 'nestedFor', 'forInWhile', 'whileInFor', 'doInFor',
            'earlyReturn', 'thrown', 'whileAssign', 'omitted', 'writtenTrue', 'omittedUpdate',
            'ambiguous', 'multilineInit', 'sameLine', 'foreachInside',
        ] as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /**
     * @return array{0: Graph, 1: Reconstruction, 2: LoopAnalysis, 3: StructuredStatements, 4: Graph}
     */
    private function prepare(string $name): array
    {
        $opArray = $this->document()->opArrayNamed($name);
        $this->assertNotNull($opArray);
        $graph = (new Builder())->build($opArray);
        $expressions = (new ExpressionReconstructor())->reconstruct($opArray, $graph);
        $reconstruction = (new Reconstructor())->reconstruct($opArray, $graph, $expressions);
        $analysis = (new LoopAnalyzer())->analyze($graph, $opArray);
        $edges = $this->edges($graph);
        $identities = $this->identities($reconstruction);
        $diagnostics = $reconstruction->diagnostics;
        $structured = (new LoopStructurer())->structure($graph, $analysis, $reconstruction);

        $this->assertSame($edges, $this->edges($graph), $name);
        $this->assertSame($identities, $this->identities($reconstruction), $name);
        $this->assertSame($diagnostics, $reconstruction->diagnostics, $name);

        return [$graph, $reconstruction, $analysis, $structured, $graph];
    }

    private function document(): \Rephper\Ir\Document
    {
        static $document = null;
        if ($document !== null) {
            return $document;
        }
        $json = (string) file_get_contents(dirname(__DIR__, 2) . '/Expected/PHP/8.4/Common/ForLoops.json');
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

    private function assertForOrder(ForStatement $for): void
    {
        $previous = -1;
        foreach ($for->initializers as $expression) {
            $this->assertGreaterThan($previous, $expression->opline);
            $this->assertLessThan($for->opline, $expression->opline);
            $this->assertSame($expression->line, $for->line);
            $previous = $expression->opline;
        }
        foreach ($this->oplines($for->body) as $opline) {
            $this->assertGreaterThan($previous, $opline);
            $this->assertLessThan($for->opline, $opline);
            $previous = $opline;
        }
        foreach ($for->updates as $expression) {
            $this->assertGreaterThan($previous, $expression->opline);
            $this->assertLessThan($for->opline, $expression->opline);
            $previous = $expression->opline;
        }
    }

    /**
     * @param list<Statement> $statements
     * @return list<int>
     */
    private function oplines(array $statements): array
    {
        $oplines = [];
        foreach ($this->statements($statements) as $statement) {
            if ($statement instanceof ForStatement || $statement instanceof WhileStatement || $statement instanceof DoWhileStatement || $statement instanceof ForeachStatement || $statement instanceof IfStatement) {
                continue;
            }
            $oplines[] = $statement->opline;
        }

        return $oplines;
    }

    /**
     * @param list<Statement> $statements
     */
    private function onlyFor(array $statements): ForStatement
    {
        $tops = [];
        foreach ($statements as $statement) {
            if ($statement instanceof ForStatement) {
                $tops[] = $statement;
            }
        }
        $this->assertCount(1, $tops);

        return $tops[0];
    }

    /**
     * @param list<Statement> $statements
     */
    private function topWhile(array $statements): WhileStatement
    {
        $tops = [];
        foreach ($statements as $statement) {
            if ($statement instanceof WhileStatement) {
                $tops[] = $statement;
            }
        }
        $this->assertCount(1, $tops);

        return $tops[0];
    }

    /**
     * @param list<Statement> $statements
     */
    private function onlyWhile(array $statements): WhileStatement
    {
        $this->assertInstanceOf(WhileStatement::class, $statements[1]);

        return $statements[1];
    }

    private function onlyLoop(LoopAnalysis $analysis): NaturalLoop
    {
        $this->assertCount(1, $analysis->loops);

        return $analysis->loops[0];
    }

    private function loop(LoopAnalysis $analysis, string $id): NaturalLoop
    {
        foreach ($analysis->loops as $loop) {
            if ($loop->id === $id) {
                return $loop;
            }
        }
        $this->fail('Missing loop ' . $id);
    }

    private function control(LoopAnalysis $analysis, string $opcode, int $level): \Rephper\Cfg\LoopControl
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

    private function opaque(Reconstruction $reconstruction, ?int $opline): Opaque
    {
        $this->assertNotNull($opline);
        $statement = $this->original($reconstruction, $opline);
        $this->assertInstanceOf(OpaqueStatement::class, $statement);

        return $statement->instruction;
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
        $this->fail('Missing statement at ' . $opline);
    }

    private function resolved(Reconstruction $reconstruction, int $opline): Expression
    {
        $statement = $this->original($reconstruction, $opline);
        $this->assertInstanceOf(OpaqueStatement::class, $statement);
        $resolved = $statement->instruction->op1->resolved;
        $this->assertInstanceOf(Expression::class, $resolved);

        return $resolved;
    }

    private function binary(Expression $expression): Binary
    {
        $this->assertInstanceOf(Binary::class, $expression);
        $this->assertInstanceOf(VariableRead::class, $expression->left);

        return $expression;
    }

    /**
     * @return list<string>
     */
    private function argumentNames(FunctionCall $call): array
    {
        $names = [];
        foreach ($call->arguments as $argument) {
            $this->assertInstanceOf(VariableRead::class, $argument->value);
            $names[] = $argument->value->name;
        }

        return $names;
    }

    private function opcode(Statement $statement): string
    {
        return $this->opcodeStatement($statement)->opcode;
    }

    private function opcodeStatement(Statement $statement): Opaque
    {
        $this->assertInstanceOf(OpaqueStatement::class, $statement);

        return $statement->instruction;
    }

    private function assertAssignment(Statement $statement, string $variable): void
    {
        $this->assertInstanceOf(ExpressionStatement::class, $statement);
        $this->assertInstanceOf(Assignment::class, $statement->expression);
        $this->assertSame($variable, $statement->expression->variable);
    }

    private function oplineOf(Reconstruction $reconstruction, string $opcode): int
    {
        foreach ($reconstruction->blocks as $block) {
            foreach ($block->statements as $statement) {
                if ($statement instanceof OpaqueStatement && $statement->instruction->opcode === $opcode) {
                    return $statement->opline;
                }
            }
        }
        $this->fail('Missing opcode ' . $opcode);
    }

    /**
     * @param list<Statement> $statements
     * @return list<Expression>
     */
    private function expressionsIn(array $statements): array
    {
        $expressions = [];
        foreach ($this->statements($statements) as $statement) {
            if ($statement instanceof ExpressionStatement) {
                $expressions[] = $statement->expression;
            } elseif ($statement instanceof OpaqueStatement) {
                $expressions[] = $statement->instruction;
            }
        }

        return $expressions;
    }

    /**
     * @param list<Statement> $statements
     * @return list<ForStatement>
     */
    private function fors(array $statements): array
    {
        $found = [];
        foreach ($this->statements($statements) as $statement) {
            if ($statement instanceof ForStatement) {
                $found[] = $statement;
            }
        }

        return $found;
    }

    /**
     * @param list<Statement> $statements
     * @return list<WhileStatement>
     */
    private function whiles(array $statements): array
    {
        $found = [];
        foreach ($this->statements($statements) as $statement) {
            if ($statement instanceof WhileStatement) {
                $found[] = $statement;
            }
        }

        return $found;
    }

    /**
     * @param list<Statement> $statements
     */
    private function onlyBreak(array $statements): BreakStatement
    {
        $found = [];
        foreach ($statements as $statement) {
            if ($statement instanceof BreakStatement) {
                $found[] = $statement;
            }
        }
        $this->assertCount(1, $found);

        return $found[0];
    }

    /**
     * @param list<Statement> $statements
     */
    private function onlyContinue(array $statements): ContinueStatement
    {
        $found = [];
        foreach ($statements as $statement) {
            if ($statement instanceof ContinueStatement) {
                $found[] = $statement;
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
        foreach ($this->statements($statements) as $statement) {
            if ($statement->opline === $opline && !$statement instanceof ForStatement && !$statement instanceof WhileStatement && !$statement instanceof IfStatement) {
                return $statement;
            }
        }
        $this->fail('Missing statement at ' . $opline);
    }

    private function diagnostic(StructuredStatements $structured, string $kind): \Rephper\Expr\Diagnostic
    {
        $found = [];
        foreach ($structured->diagnostics as $diagnostic) {
            if ($diagnostic->kind === $kind) {
                $found[] = $diagnostic;
            }
        }
        $this->assertNotEmpty($found);

        return $found[array_key_last($found)];
    }

    /**
     * @param list<Statement> $statements
     * @return list<Statement>
     */
    private function statements(array $statements): array
    {
        $found = [];
        foreach ($statements as $statement) {
            $found[] = $statement;
            if ($statement instanceof ForStatement || $statement instanceof WhileStatement || $statement instanceof DoWhileStatement || $statement instanceof ForeachStatement) {
                foreach ($this->statements($statement->body) as $nested) {
                    $found[] = $nested;
                }
            } elseif ($statement instanceof IfStatement) {
                foreach ($statement->branches as $branch) {
                    foreach ($this->statements($branch->statements) as $nested) {
                        $found[] = $nested;
                    }
                }
                if ($statement->elseStatements !== null) {
                    foreach ($this->statements($statement->elseStatements) as $nested) {
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
     * @return list<Statement>
     */
    private function actualLeaves(StructuredStatements $structured): array
    {
        $leaves = [];
        foreach ($this->statements($structured->statements) as $statement) {
            if ($statement instanceof ForStatement || $statement instanceof WhileStatement || $statement instanceof DoWhileStatement || $statement instanceof ForeachStatement || $statement instanceof IfStatement) {
                continue;
            }
            if ($statement instanceof BreakStatement || $statement instanceof ContinueStatement) {
                continue;
            }
            if ($statement instanceof OpaqueStatement && in_array($statement->instruction->opcode, ['BRK', 'CONT'], true)) {
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
        $keyOplines = [];
        foreach ($this->statements($structured->statements) as $statement) {
            if ($statement instanceof ForeachStatement && $statement->key !== null) {
                $keyOplines[$statement->key->opline] = true;
            }
        }
        $consumed = [];
        foreach ($this->statements($structured->statements) as $statement) {
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
                if (
                    $statement instanceof ExpressionStatement
                    && isset($keyOplines[$statement->opline])
                    && !in_array($statement, $actual, true)
                ) {
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
        $loops = array_merge($this->fors($structured->statements), $this->whiles($structured->statements));
        foreach ($this->statements($structured->statements) as $statement) {
            if ($statement instanceof ForeachStatement) {
                $loops[] = $statement;
            }
        }
        foreach ($loops as $loop) {
            $ids[$loop->loopId] = true;
        }
        foreach ($analysis->controls as $control) {
            $statement = null;
            foreach ($this->statements($structured->statements) as $candidate) {
                if (
                    $candidate->opline === $control->opline
                    && !$candidate instanceof ForStatement
                    && !$candidate instanceof WhileStatement
                    && !$candidate instanceof DoWhileStatement
                    && !$candidate instanceof ForeachStatement
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
