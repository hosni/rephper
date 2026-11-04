<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Stmt;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephper\Cfg\Builder;
use Rephper\Cfg\Graph;
use Rephper\Expr\Assignment;
use Rephper\Expr\Binary;
use Rephper\Expr\Diagnostic;
use Rephper\Expr\Expression;
use Rephper\Expr\Literal;
use Rephper\Expr\Opaque;
use Rephper\Expr\Reconstructor as ExpressionReconstructor;
use Rephper\Expr\Unary;
use Rephper\Expr\VariableRead;
use Rephper\Ir\Document;
use Rephper\Ir\OpArray;
use Rephper\Ir\Reader;
use Rephper\Stmt\ConditionalBranch;
use Rephper\Stmt\ConditionalStructurer;
use Rephper\Stmt\ExpressionStatement;
use Rephper\Stmt\IfStatement;
use Rephper\Stmt\OpaqueStatement;
use Rephper\Stmt\Reconstructor;
use Rephper\Stmt\Reconstruction;
use Rephper\Stmt\ReturnStatement;
use Rephper\Stmt\Statement;
use Rephper\Stmt\StructuredStatements;
use Rephper\Stmt\ThrowStatement;

final class ConditionalStructurerTest extends TestCase
{
    public function testAnIfWithoutElseKeepsTheFollowingStatementOutside(): void
    {
        [, $original, $structured] = $this->prepare('plainIf');
        $this->assertStructuredPreserves($original, $structured);
        $this->assertSame([], $structured->diagnostics);
        $this->assertSame('JMPZ', $original->diagnostics[0]->opcode);

        $this->assertCount(3, $structured->statements);
        $this->assertAssignment($structured->statements[0], 'x', 0, 5);
        $conditional = $this->conditional($structured->statements[1]);
        $this->assertSame(2, $conditional->opline);
        $this->assertSame(6, $conditional->line);
        $this->assertNull($conditional->elseStatements);
        $this->assertNull($conditional->elseOpline);
        $this->assertNull($conditional->elseLine);
        $this->assertCount(1, $conditional->branches);
        $branch = $conditional->branches[0];
        $this->assertVariableCondition($original, $branch, 'a');
        $this->assertCount(1, $branch->statements);
        $this->assertAssignment($branch->statements[0], 'x', 1, 7);
        $this->assertReturnLiteral($structured->statements[2], 0, 'x', 10);
        $this->assertNoNestedConditional($conditional);
    }

    public function testAnIfWithElseJoinsAtTheSharedSuccessor(): void
    {
        [, $original, $structured] = $this->prepare('ifElse');
        $this->assertStructuredPreserves($original, $structured);
        $this->assertSame([], $structured->diagnostics);

        $this->assertCount(2, $structured->statements);
        $conditional = $this->conditional($structured->statements[0]);
        $this->assertSame(15, $conditional->line);
        $this->assertSame(1, $conditional->opline);
        $this->assertCount(1, $conditional->branches);
        $this->assertVariableCondition($original, $conditional->branches[0], 'a');
        $this->assertCount(1, $conditional->branches[0]->statements);
        $this->assertAssignment($conditional->branches[0]->statements[0], 'x', 1, 16);
        $this->assertNotNull($conditional->elseStatements);
        $this->assertCount(1, $conditional->elseStatements);
        $this->assertSame(4, $conditional->elseOpline);
        $this->assertSame(18, $conditional->elseLine);
        $this->assertAssignment($conditional->elseStatements[0], 'x', 2, 18);
        $this->assertReturnLiteral($structured->statements[1], null, 'x', 21);
        $this->assertNoNestedConditional($conditional);
    }

    public function testElseifIsAnotherBranchOfTheSameStatement(): void
    {
        [, $original, $structured] = $this->prepare('ifElseIf');
        $this->assertStructuredPreserves($original, $structured);

        $this->assertCount(2, $structured->statements);
        $conditional = $this->conditional($structured->statements[0]);
        $this->assertSame(26, $conditional->line);
        $this->assertCount(2, $conditional->branches);
        $this->assertComparison($original, $conditional->branches[0], '===', 'a', 1, 26);
        $this->assertComparison($original, $conditional->branches[1], '===', 'a', 2, 28);
        $this->assertAssignment($conditional->branches[0]->statements[0], 'x', 1, 27);
        $this->assertAssignment($conditional->branches[1]->statements[0], 'x', 2, 29);
        $this->assertNotNull($conditional->elseStatements);
        $this->assertCount(1, $conditional->elseStatements);
        $this->assertSame(31, $conditional->elseLine);
        $this->assertSame(9, $conditional->elseOpline);
        $this->assertAssignment($conditional->elseStatements[0], 'x', 3, 31);
        $this->assertReturnLiteral($structured->statements[1], null, 'x', 34);
        $this->assertNoNestedConditional($conditional);
    }

    public function testSeveralElseifBranchesStayInOrder(): void
    {
        [, $original, $structured] = $this->prepare('manyElseIf');
        $this->assertStructuredPreserves($original, $structured);

        $conditional = $this->conditional($structured->statements[0]);
        $this->assertCount(3, $conditional->branches);
        $this->assertSame([39, 41, 43], array_map(static fn (ConditionalBranch $branch): int => $branch->line, $conditional->branches));
        $this->assertSame([2, 6, 10], array_map(static fn (ConditionalBranch $branch): int => $branch->opline, $conditional->branches));
        $this->assertComparison($original, $conditional->branches[0], '===', 'a', 1, 39);
        $this->assertComparison($original, $conditional->branches[1], '===', 'a', 2, 41);
        $this->assertComparison($original, $conditional->branches[2], '===', 'a', 3, 43);
        $this->assertAssignment($conditional->branches[0]->statements[0], 'x', 1, 40);
        $this->assertAssignment($conditional->branches[1]->statements[0], 'x', 2, 42);
        $this->assertAssignment($conditional->branches[2]->statements[0], 'x', 3, 44);
        $this->assertNotNull($conditional->elseStatements);
        $this->assertAssignment($conditional->elseStatements[0], 'x', 4, 46);
        $this->assertSame(46, $conditional->elseLine);
        $this->assertSame(13, $conditional->elseOpline);
        $this->assertReturnLiteral($structured->statements[1], null, 'x', 49);
        $this->assertNoNestedConditional($conditional);
    }

    public function testAnElseifWithoutElseLeavesTheContinuationOutside(): void
    {
        [, $original, $structured] = $this->prepare('elseifNoElse');
        $this->assertStructuredPreserves($original, $structured);

        $this->assertCount(2, $structured->statements);
        $conditional = $this->conditional($structured->statements[0]);
        $this->assertCount(2, $conditional->branches);
        $this->assertNull($conditional->elseStatements);
        $this->assertComparison($original, $conditional->branches[0], '===', 'a', 1, 228);
        $this->assertComparison($original, $conditional->branches[1], '===', 'a', 2, 230);
        $this->assertAssignment($conditional->branches[0]->statements[0], 'x', 1, 229);
        $this->assertAssignment($conditional->branches[1]->statements[0], 'x', 2, 231);
        $this->assertReturnLiteral($structured->statements[1], null, 'x', 234);
        $this->assertNoNestedConditional($conditional);
    }

    public function testNestedConditionalsStayInsideTheBranchThatContainsThem(): void
    {
        [, $original, $structured] = $this->prepare('nestedIf');
        $this->assertStructuredPreserves($original, $structured);

        $outer = $this->conditional($structured->statements[0]);
        $this->assertSame(54, $outer->line);
        $this->assertCount(1, $outer->branches);
        $this->assertVariableCondition($original, $outer->branches[0], 'a');
        $this->assertCount(1, $outer->branches[0]->statements);
        $inner = $this->conditional($outer->branches[0]->statements[0]);
        $this->assertSame(55, $inner->line);
        $this->assertSame(3, $inner->opline);
        $this->assertVariableCondition($original, $inner->branches[0], 'b');
        $this->assertAssignment($inner->branches[0]->statements[0], 'x', 1, 56);
        $this->assertNotNull($inner->elseStatements);
        $this->assertSame(58, $inner->elseLine);
        $this->assertAssignment($inner->elseStatements[0], 'x', 2, 58);
        $this->assertNotNull($outer->elseStatements);
        $this->assertCount(1, $outer->elseStatements);
        $this->assertSame(61, $outer->elseLine);
        $this->assertAssignment($outer->elseStatements[0], 'x', 3, 61);
        $this->assertReturnLiteral($structured->statements[1], null, 'x', 64);
        $this->assertNoNestedConditional($inner);
    }

    public function testAnElseThatStartsWithAStatementKeepsTheInnerConditionalNested(): void
    {
        [, $original, $structured] = $this->prepare('elseThenIf');
        $this->assertStructuredPreserves($original, $structured);

        $outer = $this->conditional($structured->statements[0]);
        $this->assertCount(1, $outer->branches);
        $this->assertVariableCondition($original, $outer->branches[0], 'a');
        $this->assertAssignment($outer->branches[0]->statements[0], 'x', 1, 202);
        $this->assertNotNull($outer->elseStatements);
        $this->assertCount(2, $outer->elseStatements);
        $this->assertSame(204, $outer->elseLine);
        $this->assertAssignment($outer->elseStatements[0], 'y', 2, 204);
        $inner = $this->conditional($outer->elseStatements[1]);
        $this->assertSame(205, $inner->line);
        $this->assertVariableCondition($original, $inner->branches[0], 'b');
        $this->assertAssignment($inner->branches[0]->statements[0], 'x', 3, 206);
        $this->assertNotNull($inner->elseStatements);
        $this->assertAssignment($inner->elseStatements[0], 'x', 4, 208);
        $this->assertReturnLiteral($structured->statements[1], null, 'x', 212);
    }

    public function testEmptyBranchesKeepTheConditionAndTheMerge(): void
    {
        [, $originalThen, $emptyThen] = $this->prepare('emptyThen');
        $this->assertStructuredPreserves($originalThen, $emptyThen);
        $this->assertAssignment($emptyThen->statements[0], 'x', 0, 69);
        $then = $this->conditional($emptyThen->statements[1]);
        $this->assertSame(70, $then->line);
        $this->assertVariableCondition($originalThen, $then->branches[0], 'a');
        $this->assertSame([], $then->branches[0]->statements);
        $this->assertNotNull($then->elseStatements);
        $this->assertSame(72, $then->elseLine);
        $this->assertAssignment($then->elseStatements[0], 'x', 1, 72);
        $this->assertReturnLiteral($emptyThen->statements[2], null, 'x', 75);

        [, $originalElse, $emptyElse] = $this->prepare('emptyElse');
        $this->assertStructuredPreserves($originalElse, $emptyElse);
        $deleted = $this->conditional($emptyElse->statements[0]);
        $this->assertSame(80, $deleted->line);
        $this->assertNull($deleted->elseStatements);
        $this->assertVariableCondition($originalElse, $deleted->branches[0], 'a');
        $this->assertAssignment($deleted->branches[0]->statements[0], 'x', 1, 81);
        $this->assertReturnLiteral($emptyElse->statements[1], 0, null, 85);

        [, $originalIf, $emptyIf] = $this->prepare('emptyIf');
        $this->assertStructuredPreserves($originalIf, $emptyIf);
        $empty = $this->conditional($emptyIf->statements[0]);
        $this->assertSame(90, $empty->line);
        $this->assertSame(1, $empty->opline);
        $this->assertNull($empty->elseStatements);
        $this->assertVariableCondition($originalIf, $empty->branches[0], 'a');
        $this->assertSame([], $empty->branches[0]->statements);
        $this->assertReturnLiteral($emptyIf->statements[1], 0, null, 93);
        $this->assertSame([], $emptyIf->diagnostics);
    }

    public function testAReturnInTheTrueBranchLeavesTheNextReturnOutside(): void
    {
        [, $original, $structured] = $this->prepare('returnTrue');
        $this->assertStructuredPreserves($original, $structured);

        $this->assertCount(2, $structured->statements);
        $conditional = $this->conditional($structured->statements[0]);
        $this->assertSame(98, $conditional->line);
        $this->assertNull($conditional->elseStatements);
        $this->assertVariableCondition($original, $conditional->branches[0], 'a');
        $this->assertCount(1, $conditional->branches[0]->statements);
        $this->assertReturnLiteral($conditional->branches[0]->statements[0], 1, null, 99);
        $this->assertReturnLiteral($structured->statements[1], 0, null, 102);
    }

    public function testAFollowingReturnIsNotAnElseWithoutTheCompilerElseMarker(): void
    {
        [, $original, $structured] = $this->prepare('returnBoth');
        $this->assertStructuredPreserves($original, $structured);

        $conditional = $this->conditional($structured->statements[0]);
        $this->assertSame(107, $conditional->line);
        $this->assertNull($conditional->elseStatements);
        $this->assertReturnLiteral($conditional->branches[0]->statements[0], 1, null, 108);
        $this->assertReturnLiteral($structured->statements[1], 2, null, 111);
    }

    public function testReturnsInBothBranchesOfAnExplicitElseStayInside(): void
    {
        [, $original, $structured] = $this->prepare('returnBothElse');
        $this->assertStructuredPreserves($original, $structured);
        $this->assertCount(1, $structured->statements);

        $conditional = $this->conditional($structured->statements[0]);
        $this->assertSame(116, $conditional->line);
        $this->assertSame(1, $conditional->opline);
        $this->assertVariableCondition($original, $conditional->branches[0], 'a');
        $this->assertReturnLiteral($conditional->branches[0]->statements[0], 1, null, 117);
        $this->assertNotNull($conditional->elseStatements);
        $this->assertCount(1, $conditional->elseStatements);
        $this->assertSame(119, $conditional->elseLine);
        $this->assertSame(4, $conditional->elseOpline);
        $this->assertReturnLiteral($conditional->elseStatements[0], 2, null, 119);
    }

    public function testAReturnInTheTrueBranchAndAnAssignmentInTheElseJoinAfterward(): void
    {
        [, $original, $structured] = $this->prepare('elseAssign');
        $this->assertStructuredPreserves($original, $structured);

        $conditional = $this->conditional($structured->statements[0]);
        $this->assertSame(217, $conditional->line);
        $this->assertReturnLiteral($conditional->branches[0]->statements[0], 1, null, 218);
        $this->assertNotNull($conditional->elseStatements);
        $this->assertSame(220, $conditional->elseLine);
        $this->assertAssignment($conditional->elseStatements[0], 'x', 2, 220);
        $this->assertReturnLiteral($structured->statements[1], null, 'x', 223);
    }

    public function testAThrowEndsTheTrueBranchAndTheReturnStaysOutside(): void
    {
        [, $original, $structured] = $this->prepare('thrown');
        $this->assertStructuredPreserves($original, $structured);
        $this->assertCount(2, $structured->diagnostics);
        $this->assertSame(
            ['SEND_VAL_EX', 'DO_FCALL'],
            array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->opcode, $structured->diagnostics),
        );
        $this->assertContains('JMPZ', array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->opcode, $original->diagnostics));

        $conditional = $this->conditional($structured->statements[0]);
        $this->assertSame(125, $conditional->line);
        $this->assertSame(2, $conditional->opline);
        $this->assertNull($conditional->elseStatements);
        $this->assertComparison($original, $conditional->branches[0], '<', 'a', 0, 125);
        $body = $conditional->branches[0]->statements;
        $this->assertCount(3, $body);
        $this->assertInstanceOf(OpaqueStatement::class, $body[0]);
        $this->assertSame('SEND_VAL_EX', $body[0]->instruction->opcode);
        $this->assertSame(126, $body[0]->line);
        $this->assertInstanceOf(OpaqueStatement::class, $body[1]);
        $this->assertSame('DO_FCALL', $body[1]->instruction->opcode);
        $this->assertInstanceOf(ThrowStatement::class, $body[2]);
        $this->assertSame(126, $body[2]->line);
        $this->assertSame(6, $body[2]->opline);
        $this->assertInstanceOf(Opaque::class, $body[2]->exception);
        $this->assertSame('NEW', $body[2]->exception->opcode);
        $this->assertReturnLiteral($structured->statements[1], null, 'a', 129);
    }

    public function testAComparisonConditionIsTheCompiledExpression(): void
    {
        [, $original, $structured] = $this->prepare('compared');
        $this->assertStructuredPreserves($original, $structured);

        $conditional = $this->conditional($structured->statements[0]);
        $this->assertSame(134, $conditional->line);
        $this->assertSame(3, $conditional->opline);
        $this->assertNull($conditional->elseStatements);
        $condition = $this->assertComparison($original, $conditional->branches[0], '<', 'b', 'a', 134);
        $this->assertSame(2, $condition->opline);
        $this->assertInstanceOf(VariableRead::class, $condition->left);
        $this->assertInstanceOf(VariableRead::class, $condition->right);
        $this->assertReturnLiteral($conditional->branches[0]->statements[0], 1, null, 135);
        $this->assertReturnLiteral($structured->statements[1], 0, null, 138);

        [, $equalOriginal, $equal] = $this->prepare('comparedEqual');
        $this->assertStructuredPreserves($equalOriginal, $equal);
        $identical = $this->conditional($equal->statements[0]);
        $this->assertComparison($equalOriginal, $identical->branches[0], '===', 'a', 'b', 143);
        $this->assertSame(3, $identical->opline);
        $this->assertReturnLiteral($identical->branches[0]->statements[0], 1, null, 144);
        $this->assertReturnLiteral($equal->statements[1], 0, null, 147);
    }

    public function testAVariableConditionIsTheBranchOperand(): void
    {
        [, $original, $structured] = $this->prepare('variable');
        $this->assertStructuredPreserves($original, $structured);

        $conditional = $this->conditional($structured->statements[0]);
        $this->assertSame(152, $conditional->line);
        $this->assertSame(1, $conditional->opline);
        $condition = $this->assertVariableCondition($original, $conditional->branches[0], 'a');
        $this->assertSame(1, $condition->opline);
        $this->assertSame(152, $condition->line);
        $this->assertReturnLiteral($conditional->branches[0]->statements[0], 1, null, 153);
        $this->assertReturnLiteral($structured->statements[1], 0, null, 156);
    }

    public function testLogicalNegationKeepsTheCompiledNotExpression(): void
    {
        [, $original, $structured] = $this->prepare('negated');
        $this->assertStructuredPreserves($original, $structured);

        $conditional = $this->conditional($structured->statements[0]);
        $this->assertSame(161, $conditional->line);
        $this->assertSame(2, $conditional->opline);
        $this->assertNull($conditional->elseStatements);
        $resolved = $this->branchOpaque($original, $conditional->branches[0]->opline)->instruction->op1->resolved;
        $this->assertSame($resolved, $conditional->branches[0]->condition);
        $this->assertInstanceOf(Unary::class, $conditional->branches[0]->condition);
        $this->assertSame('!', $conditional->branches[0]->condition->operator);
        $this->assertSame(161, $conditional->branches[0]->condition->line);
        $this->assertInstanceOf(VariableRead::class, $conditional->branches[0]->condition->operand);
        $this->assertSame('a', $conditional->branches[0]->condition->operand->name);
        $this->assertReturnLiteral($conditional->branches[0]->statements[0], 1, null, 162);
        $this->assertReturnLiteral($structured->statements[1], 0, null, 165);
    }

    public function testSequentialConditionalsStaySeparate(): void
    {
        [, $original, $structured] = $this->prepare('sequential');
        $this->assertStructuredPreserves($original, $structured);

        $this->assertCount(4, $structured->statements);
        $this->assertAssignment($structured->statements[0], 'x', 0, 170);
        $first = $this->conditional($structured->statements[1]);
        $second = $this->conditional($structured->statements[2]);
        $this->assertSame(171, $first->line);
        $this->assertSame(3, $first->opline);
        $this->assertSame(174, $second->line);
        $this->assertSame(5, $second->opline);
        $this->assertNull($first->elseStatements);
        $this->assertNull($second->elseStatements);
        $this->assertVariableCondition($original, $first->branches[0], 'a');
        $this->assertVariableCondition($original, $second->branches[0], 'b');
        $this->assertAssignment($first->branches[0]->statements[0], 'x', 1, 172);
        $this->assertAssignment($second->branches[0]->statements[0], 'x', 2, 175);
        $this->assertReturnLiteral($structured->statements[3], null, 'x', 178);
        $this->assertNoNestedConditional($first);
        $this->assertNoNestedConditional($second);
    }

    #[DataProvider('unsupportedFunctions')]
    public function testUnsupportedControlFlowStaysFlat(string $name, string $opcode, string $reason): void
    {
        [, $original, $structured] = $this->prepare($name);
        $this->assertSame($this->flatStatements($original), $structured->statements);
        $this->assertCount(count($original->diagnostics) + 1, $structured->diagnostics);
        foreach ($original->diagnostics as $index => $diagnostic) {
            $this->assertSame($diagnostic, $structured->diagnostics[$index]);
        }
        $failure = $structured->diagnostics[array_key_last($structured->diagnostics)];
        $this->assertInstanceOf(Diagnostic::class, $failure);
        $this->assertSame('unstructured', $failure->kind);
        $this->assertSame($opcode, $failure->opcode);
        $this->assertStringContainsString($reason, $failure->reason);
        $this->assertNotSame('', $failure->blockId);
        $this->assertGreaterThanOrEqual(0, $failure->opline);
        $this->assertGreaterThan(0, $failure->line);
        foreach ($structured->statements as $statement) {
            $this->assertNotInstanceOf(IfStatement::class, $statement);
        }
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function unsupportedFunctions(): array
    {
        return [
            'short-circuit and' => ['andCond', 'JMPZ_EX', 'short-circuit expression'],
            'short-circuit or' => ['orCond', 'JMPNZ_EX', 'short-circuit expression'],
            'loop' => ['counted', 'JMPNZ', 'not a forward edge'],
            'switch' => ['choose', 'SWITCH_STRING', 'not structured as a conditional'],
            'goto' => ['jumped', 'GOTO', 'not structured as a conditional'],
        ];
    }

    /**
     * @return array{0: Graph, 1: Reconstruction, 2: StructuredStatements}
     */
    private function prepare(string $name): array
    {
        $opArray = $this->opArray($name);
        $graph = (new Builder())->build($opArray);
        $expressions = (new ExpressionReconstructor())->reconstruct($opArray, $graph);
        $reconstruction = (new Reconstructor())->reconstruct($opArray, $graph, $expressions);
        $edges = $this->edges($graph);
        $statements = array_map(static fn (mixed $block): array => $block->statements, $reconstruction->blocks);
        $diagnostics = $reconstruction->diagnostics;
        $structured = (new ConditionalStructurer())->structure($graph, $reconstruction);
        $this->assertSame($edges, $this->edges($graph));
        $this->assertSame($statements, array_map(static fn (mixed $block): array => $block->statements, $reconstruction->blocks));
        $this->assertSame($diagnostics, $reconstruction->diagnostics);

        return [$graph, $reconstruction, $structured];
    }

    private function assertStructuredPreserves(Reconstruction $original, StructuredStatements $structured): void
    {
        $leaves = $this->leaves($structured->statements);
        $expected = [];
        foreach ($original->blocks as $block) {
            foreach ($block->statements as $statement) {
                if ($this->isConsumedBranch($statement) && !in_array($statement, $leaves, true)) {
                    continue;
                }
                $expected[] = $statement;
            }
        }
        $this->assertSame($expected, $leaves);
        foreach ($leaves as $statement) {
            $this->assertFalse($this->isConsumedBranch($statement));
        }
    }

    /**
     * @param list<Statement> $statements
     * @return list<Statement>
     */
    private function leaves(array $statements): array
    {
        $leaves = [];
        foreach ($statements as $statement) {
            if (!$statement instanceof IfStatement) {
                $leaves[] = $statement;
                continue;
            }
            foreach ($statement->branches as $branch) {
                foreach ($this->leaves($branch->statements) as $leaf) {
                    $leaves[] = $leaf;
                }
            }
            if ($statement->elseStatements !== null) {
                foreach ($this->leaves($statement->elseStatements) as $leaf) {
                    $leaves[] = $leaf;
                }
            }
        }

        return $leaves;
    }

    /**
     * @return list<Statement>
     */
    private function flatStatements(Reconstruction $reconstruction): array
    {
        $statements = [];
        foreach ($reconstruction->blocks as $block) {
            foreach ($block->statements as $statement) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    private function isConsumedBranch(Statement $statement): bool
    {
        return $statement instanceof OpaqueStatement
            && in_array($statement->instruction->opcode, ['JMPZ', 'JMPNZ'], true);
    }

    private function conditional(Statement $statement): IfStatement
    {
        $this->assertInstanceOf(IfStatement::class, $statement);

        return $statement;
    }

    private function assertNoNestedConditional(IfStatement $statement): void
    {
        foreach ($statement->branches as $branch) {
            foreach ($branch->statements as $nested) {
                $this->assertNotInstanceOf(IfStatement::class, $nested);
            }
        }
        foreach ($statement->elseStatements ?? [] as $nested) {
            $this->assertNotInstanceOf(IfStatement::class, $nested);
        }
    }

    private function assertVariableCondition(Reconstruction $original, ConditionalBranch $branch, string $name): VariableRead
    {
        $resolved = $this->branchOpaque($original, $branch->opline)->instruction->op1->resolved;
        $this->assertSame($resolved, $branch->condition);
        $this->assertInstanceOf(VariableRead::class, $branch->condition);
        $this->assertSame($name, $branch->condition->name);
        $this->assertSame($branch->line, $branch->condition->line);

        return $branch->condition;
    }

    private function assertComparison(
        Reconstruction $original,
        ConditionalBranch $branch,
        string $operator,
        string $left,
        int|string $right,
        int $line,
    ): Binary {
        $resolved = $this->branchOpaque($original, $branch->opline)->instruction->op1->resolved;
        $this->assertSame($resolved, $branch->condition);
        $this->assertInstanceOf(Binary::class, $branch->condition);
        $this->assertSame($operator, $branch->condition->operator);
        $this->assertSame($line, $branch->line);
        $this->assertSame($line, $branch->condition->line);
        $this->assertSide($branch->condition->left, $left);
        $this->assertSide($branch->condition->right, $right);

        return $branch->condition;
    }

    private function assertSide(Expression $expression, int|string $expected): void
    {
        if (is_int($expected)) {
            $this->assertInstanceOf(Literal::class, $expression);
            $this->assertSame('int', $expression->type);
            $this->assertSame($expected, $expression->value);

            return;
        }

        $this->assertInstanceOf(VariableRead::class, $expression);
        $this->assertSame($expected, $expression->name);
    }

    private function assertAssignment(Statement $statement, string $variable, int $value, int $line): void
    {
        $this->assertInstanceOf(ExpressionStatement::class, $statement);
        $this->assertSame($line, $statement->line);
        $this->assertInstanceOf(Assignment::class, $statement->expression);
        $this->assertSame($variable, $statement->expression->variable);
        $this->assertNull($statement->expression->operator);
        $this->assertInstanceOf(Literal::class, $statement->expression->value);
        $this->assertSame('int', $statement->expression->value->type);
        $this->assertSame($value, $statement->expression->value->value);
    }

    private function assertReturnLiteral(Statement $statement, ?int $literal, ?string $variable, int $line): void
    {
        $this->assertInstanceOf(ReturnStatement::class, $statement);
        $this->assertSame($line, $statement->line);
        if ($variable !== null) {
            $this->assertInstanceOf(VariableRead::class, $statement->value);
            $this->assertSame($variable, $statement->value->name);

            return;
        }

        $this->assertInstanceOf(Literal::class, $statement->value);
        $this->assertSame('int', $statement->value->type);
        $this->assertSame($literal, $statement->value->value);
    }

    private function branchOpaque(Reconstruction $reconstruction, int $opline): OpaqueStatement
    {
        foreach ($reconstruction->blocks as $block) {
            foreach ($block->statements as $statement) {
                if ($statement instanceof OpaqueStatement && $statement->opline === $opline) {
                    return $statement;
                }
            }
        }

        $this->fail('Missing branch statement at opline ' . $opline);
    }

    /**
     * @return list<array{string, list<array{string, string}>}>
     */
    private function edges(Graph $graph): array
    {
        $edges = [];
        foreach ($graph->blocks as $block) {
            $successors = [];
            foreach ($block->successors as $edge) {
                $successors[] = [$edge->kind, $edge->to];
            }
            $edges[] = [$block->id, $successors];
        }

        return $edges;
    }

    private function opArray(string $name): OpArray
    {
        $opArray = $this->document()->opArrayNamed($name);
        $this->assertInstanceOf(OpArray::class, $opArray);

        return $opArray;
    }

    private function document(): Document
    {
        static $document = null;
        if ($document instanceof Document) {
            return $document;
        }
        $json = (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Conditionals.json');
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);
        $document = (new Reader())->readString($json);

        return $document;
    }
}
