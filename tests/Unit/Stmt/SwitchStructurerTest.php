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
use Rephper\Expr\Binary;
use Rephper\Expr\Expression;
use Rephper\Expr\FunctionCall;
use Rephper\Expr\Literal;
use Rephper\Expr\Reconstructor as ExpressionReconstructor;
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
use Rephper\Stmt\SwitchBreakStatement;
use Rephper\Stmt\SwitchCase;
use Rephper\Stmt\SwitchContinueStatement;
use Rephper\Stmt\SwitchStatement;
use Rephper\Stmt\ThrowStatement;
use Rephper\Stmt\WhileStatement;

final class SwitchStructurerTest extends TestCase
{
    public function testASimpleIntegerSwitchKeepsTheSubjectAndTheCaseTypes(): void
    {
        [$switch, $reconstruction] = $this->only('simpleInt');
        $subject = $this->switchSubject($reconstruction, $switch->opline);

        $this->assertSame($subject, $switch->subject);
        $this->assertInstanceOf(VariableRead::class, $switch->subject);
        $this->assertSame('value', $switch->subject->name);
        $this->assertSame($subject->line, $switch->line);
        $this->assertSame($subject->opline, $switch->opline);
        $this->assertSame('S0', $switch->switchId);
        $this->assertSame([1, 2, 3, 4, 5], $this->labelValues($switch));
        foreach ($switch->cases as $case) {
            $this->assertFalse($case->isDefault);
            $this->assertFalse($case->fallsThrough);
            $this->assertCount(1, $case->labels);
            $this->assertSame('int', $case->labels[0]->type);
            $this->assertIsInt($case->labels[0]->value);
            $this->assertSame($case->labels[0]->line, $case->line);
            $this->assertInstanceOf(ReturnStatement::class, $case->body[0]);
            $this->assertSame($case->labels[0]->value, $this->literal($case->body[0]->value));
        }
        $this->assertSame(0, $this->literal($this->returns($this->structured('simpleInt')->statements)[5]->value));
        $this->assertFallbackLabels($switch);
    }

    public function testAStringSwitchKeepsStringLabelsAndASharedBody(): void
    {
        [$switch] = $this->only('simpleString');

        $this->assertInstanceOf(VariableRead::class, $switch->subject);
        $this->assertSame('value', $switch->subject->name);
        $this->assertSame('S0', $switch->switchId);
        $this->assertCount(2, $switch->cases);
        $this->assertSame(['a'], $this->values($switch->cases[0]));
        $this->assertSame(['b', 'c'], $this->values($switch->cases[1]));
        $this->assertSame('string', $switch->cases[1]->labels[0]->type);
        $this->assertSame('string', $switch->cases[1]->labels[1]->type);
        $this->assertSame($switch->cases[1]->body, [$switch->cases[1]->body[0]]);
        $this->assertCount(1, $switch->cases[1]->body);
        $this->assertSame('few', $this->literal($switch->cases[1]->body[0]->value));
        $this->assertFalse($switch->cases[0]->fallsThrough);
        $this->assertFalse($switch->cases[1]->fallsThrough);
        $this->assertFalse($switch->cases[1]->isDefault);
        $this->assertFallbackLabels($switch);
    }

    public function testADefaultCaseIsSeparateFromTheTypeFallback(): void
    {
        [$switch] = $this->only('withDefault');

        $this->assertCount(5, $switch->cases);
        $this->assertTrue($switch->cases[4]->isDefault);
        $this->assertSame([], $switch->cases[4]->labels);
        $this->assertSame(0, $this->literal($switch->cases[4]->body[0]->value));
        $this->assertCount(4, $switch->typeFallback);
        foreach ($switch->typeFallback as $arm) {
            $this->assertNotSame(4, $arm->caseIndex);
        }
        $this->assertFallbackLabels($switch);
    }

    public function testASwitchWithoutADefaultDoesNotInventOne(): void
    {
        [$switch, , $structured] = $this->only('simpleInt');

        foreach ($switch->cases as $case) {
            $this->assertFalse($case->isDefault);
        }
        $this->assertInstanceOf(ReturnStatement::class, $structured->statements[1]);
        $this->assertSame(0, $this->literal($structured->statements[1]->value));
    }

    public function testAnEmptyTrailingDefaultIsTheBreakTarget(): void
    {
        [$switch, , $structured] = $this->only('emptyDefaultLast');

        foreach ($switch->cases as $case) {
            $this->assertFalse($case->isDefault);
            $this->assertNotSame([], $case->labels);
        }
        $this->assertSame(0, $this->literal($structured->statements[1]->value));
        $this->assertCount(4, $switch->typeFallback);
    }

    public function testLabelsThatShareADestinationShareOneBody(): void
    {
        [$switch] = $this->only('sharedLabels');

        $this->assertSame([1, 2], $this->values($switch->cases[0]));
        $this->assertSame([3, 4], $this->values($switch->cases[1]));
        $this->assertCount(1, $switch->cases[0]->body);
        $this->assertCount(1, $switch->cases[1]->body);
        $this->assertTrue($switch->cases[2]->isDefault);
        $this->assertSame([], $switch->cases[2]->labels);
        $this->assertFalse($switch->cases[0]->fallsThrough);
        $this->assertSame($switch->cases[0]->labels[0]->line, $switch->cases[0]->line);
        $this->assertNotSame($switch->cases[0]->body[0], $switch->cases[1]->body[0]);
    }

    public function testEmptyCasesAreLabelsOnTheSharedBreak(): void
    {
        [$switch] = $this->only('emptyCases');

        $this->assertCount(1, $switch->cases);
        $this->assertSame([1, 2, 3, 4, 5], $this->values($switch->cases[0]));
        $this->assertFalse($switch->cases[0]->isDefault);
        $this->assertFalse($switch->cases[0]->fallsThrough);
        $this->assertCount(1, $switch->cases[0]->body);
        $this->assertInstanceOf(SwitchBreakStatement::class, $switch->cases[0]->body[0]);
        $this->assertSame(1, $switch->cases[0]->body[0]->level);
        $this->assertSame($switch->switchId, $switch->cases[0]->body[0]->switchId);
        $this->assertSame([], $this->loopBreaks($switch));
    }

    public function testFallthroughIsAnEdgeToTheNextCaseAndABreakIsNot(): void
    {
        [$switch] = $this->only('fallthrough');

        $this->assertSame([true, false, true, true, false], array_map(
            static fn (SwitchCase $case): bool => $case->fallsThrough,
            $switch->cases,
        ));
        $this->assertCount(1, $switch->cases[0]->body);
        $this->assertCount(2, $switch->cases[1]->body);
        $this->assertInstanceOf(SwitchBreakStatement::class, $switch->cases[1]->body[1]);
        $this->assertNotInstanceOf(BreakStatement::class, $switch->cases[1]->body[1]);
        $this->assertSame(1, $switch->cases[1]->body[1]->level);
        $this->assertSame($switch->switchId, $switch->cases[1]->body[1]->switchId);
        $this->assertTrue($switch->cases[4]->isDefault);
        $this->assertSame(
            [$switch->cases[0]->body[0]->opline, $switch->cases[1]->body[0]->opline],
            [$switch->cases[0]->body[0]->opline, $switch->cases[1]->body[0]->opline],
        );
        $this->assertNotSame($switch->cases[0]->body[0], $switch->cases[1]->body[0]);
        $this->assertSame([], $this->loopBreaks($switch));
    }

    public function testABreakInEveryCaseStaysASwitchBreak(): void
    {
        [$switch] = $this->only('breakEvery');

        $this->assertCount(5, $switch->cases);
        foreach ($switch->cases as $case) {
            $this->assertFalse($case->fallsThrough);
            $this->assertInstanceOf(SwitchBreakStatement::class, $case->body[1]);
            $this->assertSame(1, $case->body[1]->level);
            $this->assertSame('S0', $case->body[1]->switchId);
        }
        $this->assertSame([], $this->loopBreaks($switch));
    }

    public function testReturnAndThrowStayInsideTheirCases(): void
    {
        [$thrown] = $this->only('throwInCase');
        [$returned] = $this->only('withDefault');

        $this->assertInstanceOf(ThrowStatement::class, $thrown->cases[0]->body[array_key_last($thrown->cases[0]->body)]);
        $this->assertFalse($thrown->cases[0]->fallsThrough);
        foreach (array_slice($thrown->cases, 1) as $case) {
            foreach ($case->body as $statement) {
                $this->assertNotInstanceOf(ThrowStatement::class, $statement);
            }
        }
        $this->assertInstanceOf(ReturnStatement::class, $returned->cases[0]->body[0]);
        $this->assertFalse($returned->cases[0]->fallsThrough);
    }

    public function testANestedSwitchHasItsOwnIdAndTheOuterCaseDoesNotFallThrough(): void
    {
        $structured = $this->structured('nested');
        $switches = $this->switches($structured->statements);

        $this->assertCount(2, $switches);
        $this->assertSame('S0', $switches[0]->switchId);
        $this->assertSame($switches[1], $switches[0]->cases[0]->body[0]);
        $this->assertSame('S1', $switches[1]->switchId);
        $this->assertFalse($switches[0]->cases[0]->fallsThrough);
        $this->assertTrue($switches[1]->cases[4]->isDefault);
        $this->assertLessThan($switches[1]->opline, $switches[0]->opline);
        $this->assertSame([], $this->loopBreaks($switches[0]));
    }

    public function testDefaultCanBeTheFirstBody(): void
    {
        [$switch] = $this->only('defaultFirst');

        $this->assertTrue($switch->cases[0]->isDefault);
        $this->assertSame([], $switch->cases[0]->labels);
        $this->assertSame([1, 2, 3, 4], array_slice($this->labelValues($switch), 0));
        $this->assertLessThan($switch->cases[1]->opline, $switch->cases[0]->opline);
    }

    public function testCaseOrderFollowsTheJumpTableRatherThanTheNumericKeys(): void
    {
        [$switch] = $this->only('caseOrder');

        $this->assertSame([30, 2, 10, 7, 100], $this->labelValues($switch));
        foreach ($switch->cases as $case) {
            $this->assertSame('int', $case->labels[0]->type);
            $this->assertIsInt($case->labels[0]->value);
        }
    }

    public function testACallSubjectAndAnExpressionSubjectAreUsedOnce(): void
    {
        [$call, $callReconstruction, $callStructured] = $this->only('callSubject');
        [$expr, $exprReconstruction, $exprStructured] = $this->only('exprSubject');

        $this->assertSame($this->switchSubject($callReconstruction, $call->opline), $call->subject);
        $this->assertInstanceOf(FunctionCall::class, $call->subject);
        $this->assertSame('pick', $call->subject->name);
        $this->assertSame(1, $this->occurrences($callStructured->statements, $call->subject));
        $this->assertObjectNotIn($call->subject, $this->statementExpressions($callReconstruction));

        $this->assertSame($this->switchSubject($exprReconstruction, $expr->opline), $expr->subject);
        $this->assertInstanceOf(Binary::class, $expr->subject);
        $this->assertSame('+', $expr->subject->operator);
        $this->assertSame(1, $this->occurrences($exprStructured->statements, $expr->subject));
        foreach ($call->cases as $case) {
            foreach ($case->body as $statement) {
                $this->assertNotSame('FREE', $statement instanceof OpaqueStatement ? $statement->instruction->opcode : '');
            }
        }
    }

    public function testASwitchInsideAnIfStaysInThatBranch(): void
    {
        $structured = $this->structured('switchInIf');

        $this->assertInstanceOf(IfStatement::class, $structured->statements[0]);
        $this->assertNull($structured->statements[0]->elseStatements);
        $switch = $structured->statements[0]->branches[0]->statements[0];
        $this->assertInstanceOf(SwitchStatement::class, $switch);
        $this->assertInstanceOf(ReturnStatement::class, $structured->statements[1]);
        $this->assertSame(9, $this->literal($structured->statements[1]->value));
        $this->assertObjectNotIn($structured->statements[1], $switch->cases[0]->body);
    }

    public function testAnIfWithElseInsideACaseStaysInThatCase(): void
    {
        [$switch] = $this->only('ifInside');
        $if = $switch->cases[0]->body[0];

        $this->assertInstanceOf(IfStatement::class, $if);
        $this->assertInstanceOf(ReturnStatement::class, $if->branches[0]->statements[0]);
        $this->assertSame(1, $this->literal($if->branches[0]->statements[0]->value));
        $this->assertNotNull($if->elseStatements);
        $this->assertInstanceOf(ReturnStatement::class, $if->elseStatements[0]);
        $this->assertSame(2, $this->literal($if->elseStatements[0]->value));
        $this->assertFalse($switch->cases[0]->fallsThrough);
        $this->assertSame(3, $this->literal($switch->cases[1]->body[0]->value));
    }

    public function testASwitchInsideWhileDoForAndForeachKeepsTheLoop(): void
    {
        $while = $this->structured('switchInWhile');
        $do = $this->structured('switchInDo');
        $for = $this->structured('switchInFor');
        $foreach = $this->structured('switchInForeach');

        $this->assertInstanceOf(WhileStatement::class, $while->statements[0]);
        $this->assertInstanceOf(SwitchStatement::class, $while->statements[0]->body[0]);
        $this->assertInstanceOf(BreakStatement::class, $while->statements[0]->body[0]->cases[1]->body[0]);
        $this->assertSame(2, $while->statements[0]->body[0]->cases[1]->body[0]->level);
        $this->assertSame($while->statements[0]->loopId, $while->statements[0]->body[0]->cases[1]->body[0]->loopId);
        $this->assertInstanceOf(SwitchBreakStatement::class, $while->statements[0]->body[0]->cases[0]->body[0]);

        $this->assertInstanceOf(DoWhileStatement::class, $do->statements[0]);
        $this->assertInstanceOf(SwitchStatement::class, $do->statements[0]->body[0]);
        $this->assertInstanceOf(BreakStatement::class, $do->statements[0]->body[0]->cases[1]->body[0]);
        $this->assertSame($do->statements[0]->loopId, $do->statements[0]->body[0]->cases[1]->body[0]->loopId);
        $this->assertObjectNotIn($do->statements[0]->condition, $this->ownedExpressions($do->statements[0]->body[0]));

        $this->assertInstanceOf(ExpressionStatement::class, $for->statements[0]);
        $this->assertInstanceOf(ForStatement::class, $for->statements[1]);
        $switch = $for->statements[1]->body[0];
        $this->assertInstanceOf(SwitchStatement::class, $switch);
        $this->assertCount(1, $for->statements[1]->updates);
        $this->assertObjectNotIn($for->statements[1]->updates[0], $this->ownedExpressions($switch));
        $this->assertInstanceOf(BreakStatement::class, $switch->cases[3]->body[0]);
        $this->assertSame(2, $switch->cases[3]->body[0]->level);
        $this->assertSame($for->statements[1]->loopId, $switch->cases[3]->body[0]->loopId);
        $this->assertFalse($switch->cases[4]->fallsThrough);

        $this->assertInstanceOf(ForeachStatement::class, $foreach->statements[1]);
        $this->assertInstanceOf(SwitchStatement::class, $foreach->statements[1]->body[0]);
        $this->assertInstanceOf(BreakStatement::class, $foreach->statements[1]->body[0]->cases[2]->body[0]);
        $this->assertSame($foreach->statements[1]->loopId, $foreach->statements[1]->body[0]->cases[2]->body[0]->loopId);
    }

    public function testLoopsInsideASwitchStayInsideTheirCases(): void
    {
        [$switch] = $this->only('loopInside');

        $this->assertInstanceOf(ForStatement::class, $switch->cases[0]->body[0]);
        $this->assertInstanceOf(SwitchBreakStatement::class, $switch->cases[0]->body[1]);
        $this->assertInstanceOf(WhileStatement::class, $switch->cases[1]->body[0]);
        $this->assertInstanceOf(ForeachStatement::class, $switch->cases[2]->body[0]);
        $this->assertSame('item', $switch->cases[2]->body[0]->value->name);
        $this->assertFalse($switch->cases[0]->fallsThrough);
        $this->assertSame($switch->switchId, $switch->cases[0]->body[1]->switchId);
    }

    public function testBreakTwoCanTargetAnOuterSwitch(): void
    {
        $structured = $this->structured('breakTwoSwitch');
        $outer = $this->switches($structured->statements)[0];
        $inner = $outer->cases[0]->body[0];

        $this->assertInstanceOf(SwitchStatement::class, $inner);
        $this->assertInstanceOf(SwitchBreakStatement::class, $inner->cases[0]->body[0]);
        $this->assertSame(2, $inner->cases[0]->body[0]->level);
        $this->assertSame($outer->switchId, $inner->cases[0]->body[0]->switchId);
        $this->assertNotSame($inner->switchId, $inner->cases[0]->body[0]->switchId);
        $this->assertInstanceOf(SwitchBreakStatement::class, $inner->cases[1]->body[1]);
        $this->assertSame(1, $inner->cases[1]->body[1]->level);
        $this->assertSame($inner->switchId, $inner->cases[1]->body[1]->switchId);
        $this->assertInstanceOf(SwitchBreakStatement::class, $outer->cases[0]->body[2]);
        $this->assertSame($outer->switchId, $outer->cases[0]->body[2]->switchId);
        $this->assertSame([], $this->loopBreaks($outer));
    }

    public function testContinueInsideASwitchIsNotRewrittenAsABreakOrALoopContinue(): void
    {
        [, , $analysis, $structured] = $this->prepare('continueAtEnd');
        $while = $structured->statements[0];
        $switch = $while->body[0];
        $this->assertInstanceOf(WhileStatement::class, $while);
        $this->assertInstanceOf(SwitchStatement::class, $switch);

        $continue = $this->control($analysis, 'CONT', 1);
        $this->assertSame($while->loopId, $continue->loopId);
        $this->assertInstanceOf(SwitchContinueStatement::class, $switch->cases[0]->body[0]);
        $this->assertNotInstanceOf(ContinueStatement::class, $switch->cases[0]->body[0]);
        $this->assertNotInstanceOf(SwitchBreakStatement::class, $switch->cases[0]->body[0]);
        $this->assertSame(1, $switch->cases[0]->body[0]->level);
        $this->assertSame($switch->switchId, $switch->cases[0]->body[0]->switchId);
        $this->assertSame($continue->opline, $switch->cases[0]->body[0]->opline);
        $this->assertInstanceOf(ContinueStatement::class, $switch->cases[1]->body[0]);
        $this->assertSame(2, $switch->cases[1]->body[0]->level);
        $this->assertSame($while->loopId, $switch->cases[1]->body[0]->loopId);

        $row = $this->switchRow($analysis, $switch->opline);
        $this->assertNotNull($row);
        $this->assertSame($row->breakTarget, $row->continueTarget);
        $this->assertSame($continue->targetOpline, $row->continueTarget);

        [$only] = $this->only('continueOnly');
        $aloneAnalysis = $this->prepare('continueOnly')[2];
        $this->assertInstanceOf(SwitchContinueStatement::class, $only->cases[0]->body[0]);
        $this->assertSame(1, $only->cases[0]->body[0]->level);
        $this->assertSame($only->switchId, $only->cases[0]->body[0]->switchId);
        $this->assertSame([], $this->of($this->structured('continueOnly')->statements, ContinueStatement::class));
        $this->assertSame([], $this->of($this->structured('continueOnly')->statements, SwitchBreakStatement::class));
        $aloneRow = $this->switchRow($aloneAnalysis, $only->opline);
        $this->assertNotNull($aloneRow);
        $this->assertSame($aloneRow->breakTarget, $aloneRow->continueTarget);
        $this->assertFalse($only->cases[0]->fallsThrough);
    }

    public function testAnUnsupportedJumpTableFallsBackWithoutDroppingBodies(): void
    {
        $constant = $this->structured('switchTrue');
        $duplicate = $this->structured('duplicate');

        $this->assertSame([], $this->switches($constant->statements));
        $this->assertSame([], $this->switches($duplicate->statements));
        $this->assertNotEmpty($this->diagnostics($constant, 'unstructured'));
        $this->assertNotEmpty($this->diagnostics($duplicate, 'unstructured'));
        $this->assertNotSame([], $this->opcodes($constant, 'SWITCH_LONG'));
        $constantReturns = $this->returns($constant->statements);
        $this->assertCount(6, $constantReturns);
        $this->assertSame([1, 2, 3, 4, 5], array_map(
            fn (ReturnStatement $return): mixed => $this->literal($return->value),
            array_slice($constantReturns, 0, 5),
        ));
        $this->assertInstanceOf(VariableRead::class, $constantReturns[5]->value);
        $this->assertSame('value', $constantReturns[5]->value->name);
        $this->assertSame([1, 11, 3, 4, 5, 0], array_map(
            fn (ReturnStatement $return): mixed => $this->literal($return->value),
            $this->returns($duplicate->statements),
        ));
        $this->assertSame([], $this->of($constant->statements, SwitchBreakStatement::class));
        $this->assertSame([], $this->of($constant->statements, BreakStatement::class));
    }

    public function testAComparisonChainStaysAConditional(): void
    {
        $structured = $this->structured('comparisonChain');

        $this->assertSame([], $this->switches($structured->statements));
        $this->assertNotSame([], $this->of($structured->statements, IfStatement::class));
        $this->assertSame([], $this->diagnostics($structured, 'unstructured'));
    }

    public function testAnAmbiguousSwitchDoesNotFlattenTheLoopOrStealItsBreak(): void
    {
        [, , $analysis, $structured] = $this->prepare('ambiguousBreak');
        $while = $structured->statements[1];

        $this->assertInstanceOf(WhileStatement::class, $while);
        $this->assertSame([], $this->switches($structured->statements));
        $breaks = $this->of($while->body, BreakStatement::class);
        $this->assertCount(1, $breaks);
        $this->assertSame(2, $breaks[0]->level);
        $this->assertSame($while->loopId, $breaks[0]->loopId);
        $local = [];
        foreach ($this->walk($while->body) as $statement) {
            if ($statement instanceof OpaqueStatement && $statement->instruction->opcode === 'BRK') {
                $local[] = $statement;
            }
        }
        $this->assertCount(4, $local);
        foreach ($local as $statement) {
            $this->assertNotInstanceOf(BreakStatement::class, $statement);
            $this->assertNotInstanceOf(SwitchBreakStatement::class, $statement);
        }
        $this->assertNotEmpty($this->diagnostics($structured, 'unresolved'));
        $this->assertNotEmpty($this->diagnostics($structured, 'unstructured'));
        $this->assertNotNull($this->control($analysis, 'BRK', 2)->loopId);
        $this->assertNull($this->control($analysis, 'BRK', 1)->loopId);
    }

    public function testSequentialSwitchesKeepDistinctIds(): void
    {
        $switches = $this->switches($this->structured('sequential')->statements);

        $this->assertCount(2, $switches);
        $this->assertSame('S0', $switches[0]->switchId);
        $this->assertSame('S1', $switches[1]->switchId);
        foreach ($switches[0]->cases as $case) {
            $this->assertFalse($case->isDefault);
        }
        $this->assertTrue($switches[1]->cases[4]->isDefault);
        $this->assertLessThan($switches[1]->opline, $switches[0]->opline);
    }

    public function testTheConditionalStructurerStillRefusesAJumpTable(): void
    {
        [, $reconstruction] = $this->prepare('simpleInt');
        $structured = (new ConditionalStructurer())->structure($this->graph('simpleInt'), $reconstruction);

        $this->assertSame([], $this->switches($structured->statements));
        $failure = $structured->diagnostics[array_key_last($structured->diagnostics)];
        $this->assertSame('unstructured', $failure->kind);
        $this->assertSame('SWITCH_LONG', $failure->opcode);
        $this->assertStringContainsString('not structured as a conditional', $failure->reason);
    }

    #[DataProvider('functions')]
    public function testEachOriginalStatementAppearsOnce(string $name): void
    {
        [$graph, $reconstruction, $analysis, $structured] = $this->prepare($name);

        $this->assertSame($this->expectedLeaves($reconstruction, $structured), $this->actualLeaves($structured));
        $oplines = array_map(static fn (Statement $statement): int => $statement->opline, $this->actualLeaves($structured));
        $this->assertSame($oplines, array_values(array_unique($oplines)), $name);
        $this->assertSame($analysis->dominators->reachable, $this->prepare($name)[2]->dominators->reachable);
        $this->assertNotSame('', $graph->blocks[0]->id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function functions(): array
    {
        $names = [
            'pick', 'simpleInt', 'simpleString', 'withDefault', 'breakEvery', 'sharedLabels',
            'emptyCases', 'fallthrough', 'throwInCase', 'nested', 'defaultFirst', 'caseOrder',
            'callSubject', 'exprSubject', 'switchTrue', 'duplicate', 'continueAtEnd', 'continueOnly',
            'breakTwoSwitch', 'switchInWhile', 'switchInDo', 'switchInFor', 'switchInForeach',
            'switchInIf', 'ifInside', 'loopInside', 'sequential', 'emptyDefaultLast',
            'comparisonChain', 'ambiguousBreak',
        ];
        $cases = [];
        foreach ($names as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /**
     * @return array{0: SwitchStatement, 1: Reconstruction, 2: StructuredStatements}
     */
    private function only(string $name): array
    {
        [, $reconstruction, , $structured] = $this->prepare($name);
        $switches = $this->switches($structured->statements);
        $this->assertCount(1, $switches, $name);
        $this->assertSame([], $this->diagnostics($structured, 'unstructured'), $name);

        return [$switches[0], $reconstruction, $structured];
    }

    private function structured(string $name): StructuredStatements
    {
        return $this->prepare($name)[3];
    }

    private function graph(string $name): Graph
    {
        return $this->prepare($name)[0];
    }

    /**
     * @return array{0: Graph, 1: Reconstruction, 2: LoopAnalysis, 3: StructuredStatements}
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
        $this->assertSame($expressions->diagnostics, $expressions->diagnostics, $name);

        return [$graph, $reconstruction, $analysis, $structured];
    }

    private function document(): Document
    {
        static $document = null;
        if ($document !== null) {
            return $document;
        }
        $json = (string) file_get_contents(dirname(__DIR__, 2) . '/Expected/PHP/8.4/Common/Switches.json');
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);
        $document = (new Reader())->readString($json);

        return $document;
    }

    /**
     * @return list<array{string, int, int, list<array{string, string, mixed}>, list<string>}>
     */
    private function edges(Graph $graph): array
    {
        $rows = [];
        foreach ($graph->blocks as $block) {
            $successors = [];
            foreach ($block->successors as $edge) {
                $successors[] = [$edge->kind, $edge->to, $edge->case];
            }
            $rows[] = [$block->id, $block->start, $block->end, $successors, $block->predecessors];
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

    private function switchSubject(Reconstruction $reconstruction, int $opline): Expression
    {
        foreach ($reconstruction->blocks as $block) {
            foreach ($block->statements as $statement) {
                if (!$statement instanceof OpaqueStatement || $statement->opline !== $opline) {
                    continue;
                }
                $resolved = $statement->instruction->op1->resolved;
                $this->assertInstanceOf(Expression::class, $resolved);

                return $resolved;
            }
        }
        $this->fail('Missing switch subject at opline ' . $opline);
    }

    /**
     * @param list<Statement> $statements
     * @return list<SwitchStatement>
     */
    private function switches(array $statements): array
    {
        return $this->of($statements, SwitchStatement::class);
    }

    /**
     * @param list<Statement> $statements
     * @param class-string<Statement> $class
     * @return list<Statement>
     */
    private function of(array $statements, string $class): array
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
     * @param list<Statement> $statements
     * @return list<Statement>
     */
    private function walk(array $statements): array
    {
        $found = [];
        foreach ($statements as $statement) {
            $found[] = $statement;
            if ($statement instanceof SwitchStatement) {
                foreach ($statement->cases as $case) {
                    foreach ($this->walk($case->body) as $nested) {
                        $found[] = $nested;
                    }
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
            } elseif ($statement instanceof ForStatement || $statement instanceof WhileStatement || $statement instanceof DoWhileStatement || $statement instanceof ForeachStatement) {
                foreach ($this->walk($statement->body) as $nested) {
                    $found[] = $nested;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<int|string>
     */
    private function labelValues(SwitchStatement $switch): array
    {
        $values = [];
        foreach ($switch->cases as $case) {
            foreach ($this->values($case) as $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @return list<int|string>
     */
    private function values(SwitchCase $case): array
    {
        $values = [];
        foreach ($case->labels as $label) {
            $this->assertTrue(is_int($label->value) || is_string($label->value));
            $values[] = $label->value;
        }

        return $values;
    }

    private function assertFallbackLabels(SwitchStatement $switch): void
    {
        $labels = [];
        foreach ($switch->cases as $index => $case) {
            foreach ($case->labels as $label) {
                $labels[] = [$index, $label];
            }
        }
        $this->assertCount(count($labels), $switch->typeFallback);
        foreach ($switch->typeFallback as $position => $arm) {
            $this->assertSame($labels[$position][0], $arm->caseIndex);
            $this->assertSame($labels[$position][1], $arm->value);
            $this->assertSame($arm->value->line, $arm->line);
            $this->assertSame($arm->value->opline, $arm->opline);
            $this->assertNotSame($switch->subject, $arm->value);
        }
    }

    /**
     * @return list<BreakStatement>
     */
    private function loopBreaks(SwitchStatement $switch): array
    {
        $found = [];
        foreach ($switch->cases as $case) {
            foreach ($this->walk($case->body) as $statement) {
                if ($statement instanceof BreakStatement) {
                    $found[] = $statement;
                }
            }
        }

        return $found;
    }

    /**
     * @param list<object> $haystack
     */
    private function assertObjectNotIn(object $needle, array $haystack): void
    {
        foreach ($haystack as $item) {
            $this->assertNotSame($needle, $item);
        }
    }

    private function literal(Expression $expression): mixed
    {
        $this->assertInstanceOf(Literal::class, $expression);

        return $expression->value;
    }

    /**
     * @param list<Statement> $statements
     * @return list<ReturnStatement>
     */
    private function returns(array $statements): array
    {
        return $this->of($statements, ReturnStatement::class);
    }

    /**
     * @param list<Statement> $statements
     */
    private function occurrences(array $statements, object $needle): int
    {
        $count = 0;
        foreach ($this->walk($statements) as $statement) {
            if ($statement instanceof SwitchStatement && $statement->subject === $needle) {
                $count++;
            }
            if ($statement instanceof ExpressionStatement && $statement->expression === $needle) {
                $count++;
            }
            if ($statement instanceof ReturnStatement && $statement->value === $needle) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<object>
     */
    private function statementExpressions(Reconstruction $reconstruction): array
    {
        $found = [];
        foreach ($reconstruction->blocks as $block) {
            foreach ($block->statements as $statement) {
                if ($statement instanceof ExpressionStatement) {
                    $found[] = $statement->expression;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<object>
     */
    private function ownedExpressions(SwitchStatement $switch): array
    {
        $found = [$switch->subject];
        foreach ($switch->cases as $case) {
            foreach ($this->walk($case->body) as $statement) {
                if ($statement instanceof ExpressionStatement) {
                    $found[] = $statement->expression;
                }
            }
        }

        return $found;
    }

    private function control(LoopAnalysis $analysis, string $opcode, int $level): LoopControl
    {
        foreach ($analysis->controls as $control) {
            if ($control->opcode === $opcode && $control->level === $level) {
                return $control;
            }
        }
        $this->fail('Missing ' . $opcode . ' level ' . $level);
    }

    private function switchRow(LoopAnalysis $analysis, int $opline): ?\Rephper\Ir\BreakContinue
    {
        $best = null;
        foreach ($analysis->breaks ?? [] as $row) {
            if (!$row->switch || $row->breakTarget <= $opline) {
                continue;
            }
            if ($best === null || $row->breakTarget < $best->breakTarget) {
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * @return list<Statement>
     */
    private function actualLeaves(StructuredStatements $structured): array
    {
        $leaves = [];
        foreach ($this->walk($structured->statements) as $statement) {
            if ($statement instanceof SwitchStatement || $statement instanceof IfStatement || $statement instanceof ForStatement || $statement instanceof WhileStatement || $statement instanceof DoWhileStatement || $statement instanceof ForeachStatement) {
                continue;
            }
            if ($statement instanceof BreakStatement || $statement instanceof ContinueStatement || $statement instanceof SwitchBreakStatement || $statement instanceof SwitchContinueStatement) {
                continue;
            }
            if ($statement instanceof OpaqueStatement && ($statement->instruction->opcode === 'BRK' || $statement->instruction->opcode === 'CONT')) {
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
        foreach ($this->walk($structured->statements) as $statement) {
            $expressions = [];
            if ($statement instanceof SwitchStatement) {
                $expressions[] = $statement->subject;
            } elseif ($statement instanceof ForStatement) {
                $expressions = [...$statement->initializers, ...$statement->updates];
                if ($statement->condition instanceof Expression) {
                    $expressions[] = $statement->condition;
                }
            } elseif ($statement instanceof WhileStatement || $statement instanceof DoWhileStatement) {
                $expressions[] = $statement->condition;
            } elseif ($statement instanceof IfStatement) {
                foreach ($statement->branches as $branch) {
                    $expressions[] = $branch->condition;
                }
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
                    && in_array($opcode, ['JMPZ', 'JMPNZ', 'JMP', 'FREE', 'CASE', 'SWITCH_LONG', 'SWITCH_STRING', 'FE_FREE', 'BRK', 'CONT'], true)
                    && !in_array($statement, $actual, true)
                ) {
                    continue;
                }
                if ($opcode !== '' && (str_starts_with($opcode, 'FE_RESET') || str_starts_with($opcode, 'FE_FETCH')) && !in_array($statement, $actual, true)) {
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

    /**
     * @return list<\Rephper\Expr\Diagnostic>
     */
    private function diagnostics(StructuredStatements $structured, string $kind): array
    {
        $found = [];
        foreach ($structured->diagnostics as $diagnostic) {
            if ($diagnostic->kind === $kind) {
                $found[] = $diagnostic;
            }
        }

        return $found;
    }

    /**
     * @return list<OpaqueStatement>
     */
    private function opcodes(StructuredStatements $structured, string $opcode): array
    {
        $found = [];
        foreach ($this->walk($structured->statements) as $statement) {
            if ($statement instanceof OpaqueStatement && $statement->instruction->opcode === $opcode) {
                $found[] = $statement;
            }
        }

        return $found;
    }
}
