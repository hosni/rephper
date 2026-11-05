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
use Rephper\Expr\Assignment;
use Rephper\Expr\Binary;
use Rephper\Expr\Literal;
use Rephper\Expr\Reconstructor as ExpressionReconstructor;
use Rephper\Expr\Unary;
use Rephper\Expr\VariableRead;
use Rephper\Ir\Document;
use Rephper\Ir\Reader;
use Rephper\Stmt\BreakStatement;
use Rephper\Stmt\ConditionalStructurer;
use Rephper\Stmt\ContinueStatement;
use Rephper\Expr\Expression;
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

final class LoopStructurerTest extends TestCase
{
    public function testASimpleWhileReusesTheComparisonAndTheBodyStatement(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('LoopAnalysis.json', 'simpleWhile');
        $loop = $this->onlyLoop($analysis);

        $this->assertCount(3, $structured->statements);
        $this->assertAssignment($structured->statements[0], 'i');
        $while = $this->whileAt($structured->statements, 1);
        $this->assertSame($loop->id, $while->loopId);
        $this->assertSame($loop->headerLine, $while->line);
        $this->assertSame('<', $this->comparison($reconstruction, $while)->operator);
        $this->assertSame('i', $this->comparison($reconstruction, $while)->left->name);
        $this->assertSame('n', $this->comparison($reconstruction, $while)->right->name);
        $this->assertCount(1, $while->body);
        $this->assertSame('PRE_INC', $this->opcode($while->body[0]));
        $this->assertInstanceOf(ReturnStatement::class, $structured->statements[2]);
        $this->assertNotInstanceOf(Unary::class, $while->condition);
    }

    public function testAnEmptyWhileHasAnEmptyBodyAndIsNotADoWhile(): void
    {
        [, , $analysis, $structured] = $this->prepare('WhileDo.json', 'emptyWhile');
        $loop = $this->onlyLoop($analysis);
        $while = $this->onlyWhile($structured->statements);

        $this->assertSame(NaturalLoop::WHILE, $loop->kind);
        $this->assertSame('jump', $loop->entries[0]->kind);
        $this->assertSame('true', $loop->backEdges[0]->kind);
        $this->assertSame($loop->headerId, $loop->backEdges[0]->to);
        $this->assertSame([], $while->body);
        $this->assertSame('<', $this->binary($while->condition)->operator);
        $this->assertSame($loop->id, $while->loopId);
    }

    public function testAComparisonWhileKeepsTheCompiledOperator(): void
    {
        [, $reconstruction, , $structured] = $this->prepare('WhileDo.json', 'compareWhile');
        $while = $this->onlyWhile($structured->statements);

        $this->assertSame('<=', $this->comparison($reconstruction, $while)->operator);
        $this->assertSame('i', $this->comparison($reconstruction, $while)->left->name);
        $this->assertSame('n', $this->comparison($reconstruction, $while)->right->name);
    }

    public function testWhileTrueUsesTheLiteralCondition(): void
    {
        [, $reconstruction, , $structured] = $this->prepare('LoopAnalysis.json', 'whileTrue');
        $while = $this->onlyWhile($structured->statements);
        $condition = $this->branch($reconstruction, $while->opline)->instruction->op1->resolved;

        $this->assertSame($condition, $while->condition);
        $this->assertInstanceOf(Literal::class, $while->condition);
        $this->assertSame('bool', $while->condition->type);
        $this->assertTrue($while->condition->value);
        $break = $this->onlyBreak($while->body);
        $this->assertSame(1, $break->level);
        $this->assertSame($while->loopId, $break->loopId);
    }

    public function testAWhileWithAnIfElseKeepsBothArmsAndTheLatch(): void
    {
        [, , , $structured] = $this->prepare('LoopAnalysis.json', 'conditionalBody');
        $while = $this->onlyWhile($structured->statements);
        $conditional = $this->ifAt($while->body, 0);

        $this->assertCount(2, $while->body);
        $this->assertSame(1, count($conditional->branches));
        $this->assertNotNull($conditional->elseStatements);
        $this->assertCount(1, $conditional->branches[0]->statements);
        $this->assertCount(1, $conditional->elseStatements);
        $this->assertSame('PRE_INC', $this->opcode($conditional->branches[0]->statements[0]));
        $this->assertInstanceOf(ExpressionStatement::class, $conditional->elseStatements[0]);
        $this->assertSame('PRE_INC', $this->opcode($while->body[1]));
        $this->assertNotSame($conditional->branches[0]->statements[0], $while->body[1]);
    }

    public function testAnExplicitElseAroundContinueStaysInsideTheWhile(): void
    {
        [, , $analysis, $structured] = $this->prepare('WhileDo.json', 'whileElseContinue');
        $loop = $this->onlyLoop($analysis);
        $while = $this->onlyWhile($structured->statements);
        $conditional = $this->ifAt($while->body, 0);
        $continue = $this->onlyContinue($conditional->branches[0]->statements);

        $this->assertSame(NaturalLoop::WHILE, $loop->kind);
        $this->assertNotNull($conditional->elseStatements);
        $this->assertCount(1, $conditional->elseStatements);
        $this->assertSame('PRE_INC', $this->opcode($conditional->elseStatements[0]));
        $this->assertSame('PRE_INC', $this->opcode($while->body[1]));
        $this->assertSame(1, $continue->level);
        $this->assertSame($while->loopId, $continue->loopId);
        $this->assertFalse($this->control($analysis, 'CONT', 1)->outer);
    }

    public function testReturnAndThrowStayInsideTheWhile(): void
    {
        [, , , $returned] = $this->prepare('WhileDo.json', 'whileReturn');
        $while = $this->onlyWhile($returned->statements);
        $early = $this->ifAt($while->body, 0)->branches[0]->statements[0];
        $this->assertInstanceOf(ReturnStatement::class, $early);
        $this->assertInstanceOf(ReturnStatement::class, $returned->statements[2]);
        $this->assertNotSame($early, $returned->statements[2]);
        $this->assertSame('PRE_INC', $this->opcode($while->body[1]));

        [, , , $thrown] = $this->prepare('WhileDo.json', 'whileThrow');
        $throwWhile = $this->onlyWhile($thrown->statements);
        $this->assertInstanceOf(ThrowStatement::class, $this->ifAt($throwWhile->body, 0)->branches[0]->statements[2]);
        $this->assertInstanceOf(ReturnStatement::class, $thrown->statements[2]);
    }

    public function testBreakAndContinueTargetTheWhile(): void
    {
        [, , $brokenAnalysis, $broken] = $this->prepare('LoopAnalysis.json', 'broken');
        $while = $this->onlyWhile($broken->statements);
        $break = $this->onlyBreak($this->ifAt($while->body, 0)->branches[0]->statements);
        $this->assertSame(1, $break->level);
        $this->assertSame($while->loopId, $break->loopId);
        $this->assertSame($break->opline, $this->control($brokenAnalysis, 'BRK', 1)->opline);
        $this->assertSame($break->line, $this->control($brokenAnalysis, 'BRK', 1)->line);

        [, , $continuedAnalysis, $continued] = $this->prepare('LoopAnalysis.json', 'multiBack');
        $continuedWhile = $this->onlyWhile($continued->statements);
        $continue = $this->onlyContinue($this->ifAt($continuedWhile->body, 0)->branches[0]->statements);
        $this->assertSame(1, $continue->level);
        $this->assertSame($continuedWhile->loopId, $continue->loopId);
        $this->assertSame($continue->opline, $this->control($continuedAnalysis, 'CONT', 1)->opline);
    }

    public function testADoWhileRunsTheBodyBeforeTheCondition(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('LoopAnalysis.json', 'simpleDo');
        $loop = $this->onlyLoop($analysis);
        $do = $this->onlyDo($structured->statements);

        $this->assertSame(NaturalLoop::DO_WHILE, $loop->kind);
        $this->assertSame('true', $loop->backEdges[0]->kind);
        $this->assertSame($loop->id, $do->loopId);
        $this->assertSame('<', $this->comparison($reconstruction, $do)->operator);
        $this->assertCount(1, $do->body);
        $this->assertSame('PRE_INC', $this->opcode($do->body[0]));
        $this->assertLessThan($do->opline, $do->body[0]->opline);
    }

    public function testAnEmptyDoWhileHasAnEmptyBody(): void
    {
        [, , $analysis, $structured] = $this->prepare('WhileDo.json', 'emptyDo');
        $loop = $this->onlyLoop($analysis);
        $do = $this->onlyDo($structured->statements);

        $this->assertSame(NaturalLoop::DO_WHILE, $loop->kind);
        $this->assertSame('fallthrough', $loop->entries[0]->kind);
        $this->assertNotSame(NaturalLoop::WHILE, $loop->kind);
        $this->assertSame([], $do->body);
        $this->assertSame('true', $loop->backEdges[0]->kind);
    }

    public function testDoWhileNegationIsTheCompiledNotExpression(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('WhileDo.json', 'doNeg');
        $do = $this->onlyDo($structured->statements);
        $resolved = $this->branch($reconstruction, $do->opline)->instruction->op1->resolved;

        $this->assertSame('true', $this->onlyLoop($analysis)->backEdges[0]->kind);
        $this->assertSame($resolved, $do->condition);
        $this->assertInstanceOf(Unary::class, $do->condition);
        $this->assertSame('!', $do->condition->operator);
        $this->assertInstanceOf(VariableRead::class, $do->condition->operand);
        $this->assertSame('n', $do->condition->operand->name);
    }

    public function testAMultiBlockDoWhileKeepsContinueOnTheCondition(): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare('LoopAnalysis.json', 'doContinue');
        $do = $this->onlyDo($structured->statements);
        $continue = $this->onlyContinue($this->ifAt($do->body, 1)->branches[0]->statements);
        $control = $this->control($analysis, 'CONT', 1);

        $this->assertSame('PRE_INC', $this->opcode($do->body[0]));
        $this->assertSame('<', $this->comparison($reconstruction, $do)->operator);
        $this->assertSame('i', $this->comparison($reconstruction, $do)->left->name);
        $this->assertNotSame($do->condition, $this->ifAt($do->body, 1)->branches[0]->condition);
        $this->assertSame(1, $continue->level);
        $this->assertSame($do->loopId, $continue->loopId);
        $this->assertSame($control->opline, $continue->opline);
        $this->assertFalse($control->outer);
        $this->assertNotSame($this->onlyLoop($analysis)->headerId, $control->targetId);
    }

    public function testADoWhileReturnStaysInTheBody(): void
    {
        [, $reconstruction, , $structured] = $this->prepare('LoopAnalysis.json', 'doReturn');
        $do = $this->onlyDo($structured->statements);
        $conditional = $this->ifAt($do->body, 0);
        $early = $conditional->branches[0]->statements[0];

        $this->assertInstanceOf(ReturnStatement::class, $early);
        $this->assertSame('PRE_INC', $this->opcode($do->body[1]));
        $this->assertSame('i', $this->comparison($reconstruction, $do)->left->name);
        $this->assertSame('n', $this->comparison($reconstruction, $do)->right->name);
        $this->assertNotSame($do->condition, $conditional->branches[0]->condition);
        $this->assertInstanceOf(ReturnStatement::class, $structured->statements[2]);
        $this->assertNotSame($early, $structured->statements[2]);
    }

    public function testNestedWhilesKeepTheChildInsideTheParent(): void
    {
        [, , $analysis, $structured] = $this->prepare('LoopAnalysis.json', 'nestedWhile');
        [$inner, $outer] = $analysis->loops;
        $outerStatement = $this->onlyWhile($structured->statements);
        $innerStatement = $this->whileAt($outerStatement->body, 1);

        $this->assertSame($outer->id, $outerStatement->loopId);
        $this->assertSame($inner->id, $innerStatement->loopId);
        $this->assertSame($outer->id, $inner->parentId);
        $this->assertInstanceOf(ExpressionStatement::class, $outerStatement->body[0]);
        $this->assertSame('PRE_INC', $this->opcode($outerStatement->body[2]));
        $this->assertCount(2, $innerStatement->body);
    }

    public function testNestedDoWhilesAndMixedNestingDoNotDuplicateTheChild(): void
    {
        [, , $nestedAnalysis, $nested] = $this->prepare('WhileDo.json', 'nestedDo');
        $outerDo = $this->onlyDo($nested->statements);
        $innerDo = $this->doAt($outerDo->body, 1);
        $this->assertSame($nestedAnalysis->loops[0]->id, $outerDo->loopId);
        $this->assertSame($nestedAnalysis->loops[1]->id, $innerDo->loopId);
        $this->assertSame($outerDo->loopId, $nestedAnalysis->loops[1]->parentId);
        $this->assertInstanceOf(ExpressionStatement::class, $outerDo->body[0]);
        $this->assertSame('PRE_INC', $this->opcode($outerDo->body[2]));

        [, , $mixedAnalysis, $mixed] = $this->prepare('WhileDo.json', 'mixedNest');
        $outerWhile = $this->onlyWhile($mixed->statements);
        $inner = $this->doAt($outerWhile->body, 1);
        $this->assertSame($mixedAnalysis->loops[1]->id, $outerWhile->loopId);
        $this->assertSame($mixedAnalysis->loops[0]->id, $inner->loopId);
        $this->assertSame($outerWhile->loopId, $mixedAnalysis->loops[0]->parentId);
    }

    public function testALoopInsideAnIfStaysInThatBranch(): void
    {
        [, , , $structured] = $this->prepare('WhileDo.json', 'ifWhile');
        $conditional = $this->ifAt($structured->statements, 1);
        $while = $this->onlyWhile($conditional->branches[0]->statements);

        $this->assertNull($conditional->elseStatements);
        $this->assertCount(1, $conditional->branches[0]->statements);
        $this->assertInstanceOf(Binary::class, $conditional->branches[0]->condition);
        $this->assertSame('<', $conditional->branches[0]->condition->operator);
        $this->assertInstanceOf(Literal::class, $conditional->branches[0]->condition->left);
        $this->assertSame(0, $conditional->branches[0]->condition->left->value);
        $this->assertSame('n', $conditional->branches[0]->condition->right->name);
        $this->assertSame('<', $this->binary($while->condition)->operator);
        $this->assertInstanceOf(ReturnStatement::class, $structured->statements[2]);
    }

    public function testBreakTwoAndContinueTwoNameTheOuterLoop(): void
    {
        [, , $breakAnalysis, $broken] = $this->prepare('LoopAnalysis.json', 'breakTwo');
        $outer = $this->onlyWhile($broken->statements);
        $inner = $this->whileAt($outer->body, 1);
        $break = $this->onlyBreak($this->ifAt($inner->body, 0)->branches[0]->statements);
        $this->assertSame(2, $break->level);
        $this->assertSame($outer->loopId, $break->loopId);
        $this->assertNotSame($inner->loopId, $break->loopId);
        $this->assertTrue($this->control($breakAnalysis, 'BRK', 2)->outer);
        $this->assertSame($break->line, $this->control($breakAnalysis, 'BRK', 2)->line);

        [, , $continueAnalysis, $continued] = $this->prepare('WhileDo.json', 'continueTwoWhile');
        $outerWhile = $this->onlyWhile($continued->statements);
        $innerWhile = $this->whileAt($outerWhile->body, 1);
        $continue = $this->onlyContinue($this->ifAt($innerWhile->body, 0)->branches[0]->statements);
        $this->assertSame(2, $continue->level);
        $this->assertSame($outerWhile->loopId, $continue->loopId);
        $this->assertNotSame($innerWhile->loopId, $continue->loopId);
        $this->assertTrue($this->control($continueAnalysis, 'CONT', 2)->outer);
        $this->assertSame('PRE_INC', $this->opcode($outerWhile->body[2]));
    }

    public function testASwitchBreakIsNotALoopBreak(): void
    {
        [, , $analysis, $structured] = $this->prepare('WhileDo.json', 'switchBreak');
        $while = $this->onlyWhile($structured->statements);
        $conditional = $this->ifAt($while->body, 0);
        $break = $conditional->branches[0]->statements[0];
        $control = $this->control($analysis, 'BRK', 1);

        $this->assertNull($control->loopId);
        $this->assertTrue($this->onlyLoop($analysis)->contains($control->targetId));
        $this->assertInstanceOf(OpaqueStatement::class, $break);
        $this->assertSame('BRK', $break->instruction->opcode);
        $this->assertSame($control->opline, $break->opline);
        $this->assertSame($control->line, $break->line);
        $this->assertSame([], $this->breaks($structured->statements));
        $this->assertSame('PRE_INC', $this->opcode($while->body[1]));
        $this->assertSame('unresolved', $this->diagnosticFor($structured, 'BRK')->kind);
    }

    public function testBreakTwoFromASwitchLeavesTheWhile(): void
    {
        [, , $analysis, $structured] = $this->prepare('WhileDo.json', 'switchBreakTwo');
        $while = $this->onlyWhile($structured->statements);
        $break = $this->onlyBreak($this->ifAt($while->body, 0)->branches[0]->statements);
        $control = $this->control($analysis, 'BRK', 2);

        $this->assertSame(2, $break->level);
        $this->assertSame($while->loopId, $break->loopId);
        $this->assertSame($control->loopId, $break->loopId);
        $this->assertFalse($this->onlyLoop($analysis)->contains($control->targetId));
        $this->assertSame($control->opline, $break->opline);
        $this->assertSame('PRE_INC', $this->opcode($while->body[1]));
    }

    public function testContinueInsideASwitchDoesNotContinueTheWhile(): void
    {
        [, , $analysis, $structured] = $this->prepare('WhileDo.json', 'switchContinue');
        $while = $this->onlyWhile($structured->statements);
        $continue = $this->ifAt($while->body, 0)->branches[0]->statements[0];
        $control = $this->control($analysis, 'CONT', 1);

        $this->assertNull($control->loopId);
        $this->assertTrue($this->onlyLoop($analysis)->contains($control->targetId));
        $this->assertNotSame($this->onlyLoop($analysis)->headerId, $control->targetId);
        $this->assertInstanceOf(OpaqueStatement::class, $continue);
        $this->assertSame('CONT', $continue->instruction->opcode);
        $this->assertSame([], $this->continues($structured->statements));
        $this->assertSame('unresolved', $this->diagnosticFor($structured, 'CONT')->kind);
    }

    public function testContinueTwoFromASwitchTargetsTheWhile(): void
    {
        [, , $analysis, $structured] = $this->prepare('WhileDo.json', 'switchContinueTwo');
        $while = $this->onlyWhile($structured->statements);
        $continue = $this->onlyContinue($this->ifAt($while->body, 0)->branches[0]->statements);
        $control = $this->control($analysis, 'CONT', 2);

        $this->assertSame(2, $continue->level);
        $this->assertSame($while->loopId, $continue->loopId);
        $this->assertSame($this->onlyLoop($analysis)->headerId, $control->targetId);
        $this->assertSame($control->opline, $continue->opline);
    }

    public function testASwitchChainKeepsTheLocalBreakAndTheLoopBreak(): void
    {
        [, , $analysis, $structured] = $this->prepare('WhileDo.json', 'realSwitch');
        $while = $this->onlyWhile($structured->statements);
        $conditional = $this->ifAt($while->body, 0);
        $local = $conditional->branches[0]->statements[1];
        $this->assertNotNull($conditional->elseStatements);
        $inner = $conditional->elseStatements[0];
        $this->assertInstanceOf(IfStatement::class, $inner);
        $break = $this->onlyBreak($inner->branches[0]->statements);

        $this->assertInstanceOf(OpaqueStatement::class, $local);
        $this->assertSame('BRK', $local->instruction->opcode);
        $this->assertNull($this->control($analysis, 'BRK', 1)->loopId);
        $this->assertSame(2, $break->level);
        $this->assertSame($while->loopId, $break->loopId);
        $this->assertInstanceOf(ExpressionStatement::class, $conditional->elseStatements[1]);
        $this->assertSame([], array_slice($while->body, 1));
    }

    public function testAWhileBesideAForKeepsBothRegions(): void
    {
        [, , $analysis, $structured] = $this->prepare('WhileDo.json', 'whileThenFor');
        $while = $this->whileAt($structured->statements, 1);
        $for = $analysis->loops[1];

        $this->assertSame(NaturalLoop::WHILE, $analysis->loops[0]->kind);
        $this->assertSame(NaturalLoop::FOR, $for->kind);
        $this->assertSame($analysis->loops[0]->id, $while->loopId);
        $this->assertNull($analysis->loops[0]->parentId);
        $this->assertNull($for->parentId);
        $this->assertSame('PRE_INC', $this->opcode($while->body[0]));
        $this->assertAssignment($structured->statements[2], 'total');
        $loop = $structured->statements[3];
        $this->assertInstanceOf(ForStatement::class, $loop);
        $this->assertSame($for->id, $loop->loopId);
        $this->assertSame('j', $loop->initializers[0]->variable);
        $this->assertSame('<', $this->binary($loop->condition)->operator);
        $this->assertSame('PRE_INC', $loop->updates[0]->opcode);
        $this->assertSame('PRE_INC', $this->opcode($loop->body[0]));
        $this->assertSame(1, count($this->whiles($structured->statements)));
        $this->assertSame(1, count($this->fors($structured->statements)));
    }

    public function testSupportedLoopsAreStructuredAndUnknownLoopsStayUnstructured(): void
    {
        [, , $forAnalysis, $forStructured] = $this->prepare('LoopAnalysis.json', 'simpleFor');
        $this->assertSame(NaturalLoop::FOR, $this->onlyLoop($forAnalysis)->kind);
        $this->assertSame([], $this->whiles($forStructured->statements));
        $this->assertSame([], $this->dos($forStructured->statements));
        $this->assertCount(1, $this->fors($forStructured->statements));

        [, $eachReconstruction, $eachAnalysis, $eachStructured] = $this->prepare('LoopAnalysis.json', 'simpleForeach');
        $eachLoop = $this->onlyLoop($eachAnalysis);
        $each = $this->onlyForeach($eachStructured->statements);
        $this->assertSame(NaturalLoop::FOREACH, $eachLoop->kind);
        $this->assertSame([], $this->whiles($eachStructured->statements));
        $this->assertSame($eachLoop->id, $each->loopId);
        $this->assertNull($each->key);
        $this->assertFalse($each->byReference);
        $this->assertSame('value', $each->value->name);
        $this->assertInstanceOf(VariableRead::class, $each->iterable);
        $this->assertSame('values', $each->iterable->name);
        $this->assertSame($this->resetOf($eachReconstruction, $eachLoop)->instruction->op1->resolved, $each->iterable);
        $this->assertAssignment($eachStructured->statements[0], 'total');
        $this->assertInstanceOf(ExpressionStatement::class, $each->body[0]);
        $this->assertInstanceOf(Assignment::class, $each->body[0]->expression);
        $this->assertSame('total', $each->body[0]->expression->variable);
        $this->assertSame('+', $each->body[0]->expression->operator);
        $this->assertInstanceOf(ReturnStatement::class, $eachStructured->statements[2]);
        foreach ($eachStructured->diagnostics as $diagnostic) {
            $this->assertNotSame('unstructured', $diagnostic->kind);
        }

        [, , $unknownAnalysis, $unknown] = $this->prepare('LoopAnalysis.json', 'andWhile');
        $this->assertSame(NaturalLoop::UNKNOWN, $this->onlyLoop($unknownAnalysis)->kind);
        $this->assertSame([], $this->whiles($unknown->statements));
        $this->assertStringContainsString('JMPZ_EX', $this->diagnosticKind($unknown, 'unstructured')->reason);

        [, , $gotoAnalysis, $goto] = $this->prepare('LoopAnalysis.json', 'backwardGoto');
        $this->assertSame(NaturalLoop::REASON_GOTO, $this->onlyLoop($gotoAnalysis)->reason);
        $this->assertSame([], $this->whiles($goto->statements));
        $this->assertStringContainsString('GOTO', $goto->diagnostics[array_key_last($goto->diagnostics)]->reason);
    }

    public function testSequentialWhilesAreNotNested(): void
    {
        [, , $analysis, $structured] = $this->prepare('LoopAnalysis.json', 'sequential');
        $first = $this->whileAt($structured->statements, 1);
        $second = $this->whileAt($structured->statements, 3);

        $this->assertNull($analysis->loops[0]->parentId);
        $this->assertNull($analysis->loops[1]->parentId);
        $this->assertSame($analysis->loops[0]->id, $first->loopId);
        $this->assertSame($analysis->loops[1]->id, $second->loopId);
        $this->assertSame([], $this->whiles($first->body));
        $this->assertSame([], $this->whiles($second->body));
    }

    public function testConditionalStructurerStillRefusesALoop(): void
    {
        [, $reconstruction, , , $graph] = $this->prepare('LoopAnalysis.json', 'simpleWhile');
        $structured = (new ConditionalStructurer())->structure($graph, $reconstruction);

        $this->assertSame($this->flat($reconstruction), $structured->statements);
        $failure = $structured->diagnostics[array_key_last($structured->diagnostics)];
        $this->assertSame('unstructured', $failure->kind);
        $this->assertSame('JMPNZ', $failure->opcode);
        foreach ($structured->statements as $statement) {
            $this->assertNotInstanceOf(WhileStatement::class, $statement);
            $this->assertNotInstanceOf(IfStatement::class, $statement);
        }
    }

    #[DataProvider('functions')]
    public function testEveryOriginalStatementAppearsOnce(string $file, string $name): void
    {
        [, $reconstruction, $analysis, $structured] = $this->prepare($file, $name);

        $this->assertSame($this->expectedLeaves($reconstruction, $structured), $this->actualLeaves($structured), $name);
        foreach ($structured->diagnostics as $diagnostic) {
            $this->assertStringNotContainsString('reachable block was left outside', $diagnostic->reason, $name);
        }
        $this->assertStructuredControls($analysis, $structured, $name);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function functions(): array
    {
        $cases = [];
        foreach ([
            'LoopAnalysis.json' => [
                'simpleWhile', 'simpleDo', 'simpleFor', 'emptyFor', 'simpleForeach', 'nestedWhile',
                'nestedMixed', 'multiBack', 'broken', 'continued', 'breakTwo', 'continueTwo',
                'conditionalBody', 'sequential', 'backwardGoto', 'notALoop', 'dead', 'andWhile',
                'whileTrue', 'doReturn', 'doContinue',
            ],
            'WhileDo.json' => [
                'emptyWhile', 'compareWhile', 'whileReturn', 'whileThrow', 'whileElseContinue',
                'ifWhile', 'emptyDo', 'doNeg', 'nestedDo', 'mixedNest', 'continueTwoWhile',
                'switchBreak', 'switchBreakTwo', 'switchContinue', 'switchContinueTwo',
                'whileThenFor', 'realSwitch',
            ],
        ] as $file => $names) {
            foreach ($names as $name) {
                $cases[$name] = [$file, $name];
            }
        }

        return $cases;
    }

    /**
     * @return array{0: Graph, 1: Reconstruction, 2: LoopAnalysis, 3: StructuredStatements, 4: Graph}
     */
    private function prepare(string $file, string $name): array
    {
        $opArray = $this->document($file)->opArrayNamed($name);
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

    private function document(string $file): Document
    {
        static $documents = [];
        if (isset($documents[$file])) {
            return $documents[$file];
        }
        $json = (string) file_get_contents(dirname(__DIR__, 2) . '/Expected/PHP/8.4/Common/' . $file);
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);
        $documents[$file] = (new Reader())->readString($json);

        return $documents[$file];
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

    /**
     * @param list<Statement> $statements
     */
    private function whileAt(array $statements, int $index): WhileStatement
    {
        $this->assertInstanceOf(WhileStatement::class, $statements[$index]);

        return $statements[$index];
    }

    /**
     * @param list<Statement> $statements
     */
    private function ifAt(array $statements, int $index): IfStatement
    {
        $this->assertInstanceOf(IfStatement::class, $statements[$index]);

        return $statements[$index];
    }

    /**
     * @param list<Statement> $statements
     */
    private function doAt(array $statements, int $index): DoWhileStatement
    {
        $this->assertInstanceOf(DoWhileStatement::class, $statements[$index]);

        return $statements[$index];
    }

    /**
     * @param list<Statement> $statements
     */
    private function onlyWhile(array $statements): WhileStatement
    {
        $found = array_values(array_filter($statements, static fn (Statement $statement): bool => $statement instanceof WhileStatement));
        $this->assertCount(1, $found);

        return $found[0];
    }

    /**
     * @param list<Statement> $statements
     */
    private function onlyDo(array $statements): DoWhileStatement
    {
        $found = array_values(array_filter($statements, static fn (Statement $statement): bool => $statement instanceof DoWhileStatement));
        $this->assertCount(1, $found);

        return $found[0];
    }

    /**
     * @param list<Statement> $statements
     * @return list<WhileStatement>
     */
    private function whiles(array $statements): array
    {
        $found = [];
        foreach ($this->walk($statements) as $statement) {
            if ($statement instanceof WhileStatement) {
                $found[] = $statement;
            }
        }

        return $found;
    }

    /**
     * @param list<Statement> $statements
     * @return list<DoWhileStatement>
     */
    private function dos(array $statements): array
    {
        $found = [];
        foreach ($this->walk($statements) as $statement) {
            if ($statement instanceof DoWhileStatement) {
                $found[] = $statement;
            }
        }

        return $found;
    }

    /**
     * @param list<Statement> $statements
     * @return list<BreakStatement>
     */
    private function breaks(array $statements): array
    {
        $found = [];
        foreach ($this->walk($statements) as $statement) {
            if ($statement instanceof BreakStatement) {
                $found[] = $statement;
            }
        }

        return $found;
    }

    /**
     * @param list<Statement> $statements
     * @return list<ContinueStatement>
     */
    private function continues(array $statements): array
    {
        $found = [];
        foreach ($this->walk($statements) as $statement) {
            if ($statement instanceof ContinueStatement) {
                $found[] = $statement;
            }
        }

        return $found;
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

    private function resetOf(Reconstruction $reconstruction, NaturalLoop $loop): OpaqueStatement
    {
        $this->assertCount(1, $loop->entries);

        return $this->branch($reconstruction, $loop->entries[0]->opline);
    }

    private function isHiddenLoopOpcode(string $opcode): bool
    {
        return in_array($opcode, ['JMPZ', 'JMPNZ', 'FE_FREE'], true)
            || str_starts_with($opcode, 'FE_RESET')
            || str_starts_with($opcode, 'FE_FETCH');
    }

    /**
     * @param list<Statement> $statements
     */
    private function onlyBreak(array $statements): BreakStatement
    {
        $found = $this->breaks($statements);
        $this->assertCount(1, $found);

        return $found[0];
    }

    /**
     * @param list<Statement> $statements
     */
    private function onlyContinue(array $statements): ContinueStatement
    {
        $found = $this->continues($statements);
        $this->assertCount(1, $found);

        return $found[0];
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
            if ($statement instanceof WhileStatement || $statement instanceof DoWhileStatement || $statement instanceof ForStatement || $statement instanceof ForeachStatement) {
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

    private function onlyLoop(LoopAnalysis $analysis): NaturalLoop
    {
        $this->assertCount(1, $analysis->loops);

        return $analysis->loops[0];
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

    private function comparison(Reconstruction $reconstruction, WhileStatement|DoWhileStatement $loop): Binary
    {
        $resolved = $this->branch($reconstruction, $loop->opline)->instruction->op1->resolved;
        $this->assertSame($resolved, $loop->condition);
        $this->assertInstanceOf(Binary::class, $loop->condition);
        $this->assertInstanceOf(VariableRead::class, $loop->condition->left);
        $this->assertInstanceOf(VariableRead::class, $loop->condition->right);

        return $loop->condition;
    }

    private function binary(mixed $condition): Binary
    {
        $this->assertInstanceOf(Binary::class, $condition);

        return $condition;
    }

    private function branch(Reconstruction $reconstruction, int $opline): OpaqueStatement
    {
        foreach ($reconstruction->blocks as $block) {
            foreach ($block->statements as $statement) {
                if ($statement instanceof OpaqueStatement && $statement->opline === $opline) {
                    return $statement;
                }
            }
        }
        $this->fail('Missing branch at opline ' . $opline);
    }

    private function opcode(Statement $statement): string
    {
        $this->assertInstanceOf(OpaqueStatement::class, $statement);

        return $statement->instruction->opcode;
    }

    private function assertAssignment(Statement $statement, string $variable): void
    {
        $this->assertInstanceOf(ExpressionStatement::class, $statement);
        $this->assertInstanceOf(\Rephper\Expr\Assignment::class, $statement->expression);
        $this->assertSame($variable, $statement->expression->variable);
    }

    private function diagnosticFor(StructuredStatements $structured, string $opcode): \Rephper\Expr\Diagnostic
    {
        $found = [];
        foreach ($structured->diagnostics as $diagnostic) {
            if ($diagnostic->opcode === $opcode && $diagnostic->kind !== 'unsupported') {
                $found[] = $diagnostic;
            }
        }
        $this->assertCount(1, $found);

        return $found[0];
    }

    private function diagnosticKind(StructuredStatements $structured, string $kind): \Rephper\Expr\Diagnostic
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
        foreach ($this->walk($structured->statements) as $statement) {
            if (
                $statement instanceof WhileStatement
                || $statement instanceof DoWhileStatement
                || $statement instanceof ForStatement
                || $statement instanceof ForeachStatement
                || $statement instanceof IfStatement
            ) {
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
        $consumed = $this->consumedExpressions($structured);
        $keyOplines = [];
        foreach ($this->walk($structured->statements) as $statement) {
            if ($statement instanceof ForeachStatement && $statement->key !== null) {
                $keyOplines[$statement->key->opline] = true;
            }
        }
        $expected = [];
        foreach ($reconstruction->blocks as $block) {
            foreach ($block->statements as $statement) {
                if (
                    $statement instanceof OpaqueStatement
                    && $this->isHiddenLoopOpcode($statement->instruction->opcode)
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

    /**
     * @return array<int, true>
     */
    private function consumedExpressions(StructuredStatements $structured): array
    {
        $consumed = [];
        foreach ($this->walk($structured->statements) as $statement) {
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

        return $consumed;
    }

    private function assertStructuredControls(LoopAnalysis $analysis, StructuredStatements $structured, string $name): void
    {
        $structuredIds = [];
        foreach (array_merge($this->whiles($structured->statements), $this->dos($structured->statements), $this->fors($structured->statements), $this->foreaches($structured->statements)) as $loop) {
            $structuredIds[$loop->loopId] = true;
        }
        foreach ($analysis->controls as $control) {
            $statement = $this->statementAt($structured->statements, $control->opline);
            if ($control->loopId !== null && isset($structuredIds[$control->loopId])) {
                $this->assertInstanceOf($control->opcode === 'BRK' ? BreakStatement::class : ContinueStatement::class, $statement, $name);
                $this->assertSame($control->level, $statement->level, $name);
                $this->assertSame($control->loopId, $statement->loopId, $name);
                $this->assertSame($control->line, $statement->line, $name);
                continue;
            }
            if ($statement === null) {
                continue;
            }
            if ($control->loopId === null) {
                $this->assertInstanceOf(OpaqueStatement::class, $statement, $name);
                $this->assertSame($control->opcode, $statement->instruction->opcode, $name);
            }
        }
    }

    /**
     * @param list<Statement> $statements
     */
    private function statementAt(array $statements, int $opline): ?Statement
    {
        foreach ($this->walk($statements) as $statement) {
            if (
                $statement->opline === $opline
                && !$statement instanceof WhileStatement
                && !$statement instanceof DoWhileStatement
                && !$statement instanceof ForStatement
                && !$statement instanceof ForeachStatement
                && !$statement instanceof IfStatement
            ) {
                return $statement;
            }
        }

        return null;
    }
}
