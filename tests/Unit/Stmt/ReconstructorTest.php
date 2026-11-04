<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Stmt;

use PHPUnit\Framework\TestCase;
use Rephper\Cfg\Builder;
use Rephper\Expr\Assignment;
use Rephper\Expr\Binary;
use Rephper\Expr\Literal;
use Rephper\Expr\Argument;
use Rephper\Expr\FunctionCall;
use Rephper\Expr\Opaque;
use Rephper\Expr\Reconstructor as ExpressionReconstructor;
use Rephper\Expr\VariableRead;
use Rephper\Ir\Document;
use Rephper\Ir\OpArray;
use Rephper\Ir\Reader;
use Rephper\Stmt\ExpressionStatement;
use Rephper\Stmt\OpaqueStatement;
use Rephper\Stmt\Reconstructor;
use Rephper\Stmt\ReturnStatement;
use Rephper\Stmt\StatementException;
use Rephper\Stmt\ThrowStatement;

final class ReconstructorTest extends TestCase
{
    public function testALiteralAssignmentIsOneExpressionStatement(): void
    {
        [$expressions, $result] = $this->both('assignLiteral');
        $statements = $result->blockStartingAt(0)?->statements;
        $this->assertNotNull($statements);
        $this->assertCount(1, $statements);
        $statement = $statements[0];
        $this->assertInstanceOf(ExpressionStatement::class, $statement);
        $this->assertSame(0, $statement->opline);
        $this->assertSame(5, $statement->line);
        $this->assertInstanceOf(Assignment::class, $statement->expression);
        $this->assertSame('a', $statement->expression->variable);
        $this->assertInstanceOf(Literal::class, $statement->expression->value);
        $this->assertSame('int', $statement->expression->value->type);
        $this->assertSame(10, $statement->expression->value->value);
        $this->assertSame($expressions->blockStartingAt(0)?->expressions[0], $statement->expression);
        $this->assertSame([], $result->diagnostics);
    }

    public function testSequentialAssignmentsStayInOrder(): void
    {
        $statements = $this->statements('assignSequence')->blockStartingAt(0)?->statements;
        $this->assertNotNull($statements);
        $this->assertCount(3, $statements);
        $this->assertSame(['a', 'b', 'c'], array_map(function (mixed $statement): string {
            $this->assertInstanceOf(ExpressionStatement::class, $statement);
            $this->assertInstanceOf(Assignment::class, $statement->expression);

            return $statement->expression->variable;
        }, $statements));
        $this->assertSame([0, 1, 2], array_map(static fn (ExpressionStatement $statement): int => $statement->opline, $statements));
        $this->assertSame([10, 11, 12], array_map(static fn (ExpressionStatement $statement): int => $statement->line, $statements));
    }

    public function testAnArithmeticAssignmentKeepsTheAddition(): void
    {
        $statements = $this->statements('assignArithmetic')->blockStartingAt(0)?->statements;
        $this->assertNotNull($statements);
        $this->assertCount(1, $statements);
        $statement = $statements[0];
        $this->assertInstanceOf(ExpressionStatement::class, $statement);
        $this->assertInstanceOf(Assignment::class, $statement->expression);
        $this->assertSame('result', $statement->expression->variable);
        $this->assertInstanceOf(Binary::class, $statement->expression->value);
        $this->assertSame('+', $statement->expression->value->operator);
        $this->assertInstanceOf(VariableRead::class, $statement->expression->value->left);
        $this->assertSame('a', $statement->expression->value->left->name);
        $this->assertInstanceOf(VariableRead::class, $statement->expression->value->right);
        $this->assertSame('b', $statement->expression->value->right->name);
    }

    public function testAnAssignedCallIsNotEmittedTwice(): void
    {
        [$expressions, $result] = $this->both('assignCall');
        $statements = $result->blockStartingAt(0)?->statements;
        $roots = $expressions->blockStartingAt(0)?->expressions;
        $this->assertNotNull($statements);
        $this->assertNotNull($roots);
        $this->assertCount(1, $statements);
        $this->assertInstanceOf(ExpressionStatement::class, $statements[0]);
        $this->assertInstanceOf(Assignment::class, $statements[0]->expression);
        $this->assertSame('result', $statements[0]->expression->variable);
        $this->assertInstanceOf(FunctionCall::class, $statements[0]->expression->value);
        $this->assertSame('strtolower', $statements[0]->expression->value->name);
        $this->assertSame('DO_ICALL', $statements[0]->expression->value->executeOpcode);
        $this->assertCount(1, $statements[0]->expression->value->arguments);
        $this->assertSame(Argument::VALUE, $statements[0]->expression->value->arguments[0]->mode);
        $this->assertInstanceOf(VariableRead::class, $statements[0]->expression->value->arguments[0]->value);
        $this->assertSame('text', $statements[0]->expression->value->arguments[0]->value->name);
        $this->assertSame(22, $statements[0]->line);
        $assignment = null;
        foreach ($roots as $root) {
            if ($root instanceof Assignment) {
                $assignment = $root;
            }
        }
        $this->assertSame($assignment, $statements[0]->expression);
        $this->assertSame([], $result->diagnostics);
    }

    public function testAStandaloneCallRemainsAStatement(): void
    {
        [$expressions, $result] = $this->both('callSideEffect');
        $statements = $result->blockStartingAt(0)?->statements;
        $roots = $expressions->blockStartingAt(0)?->expressions;
        $this->assertNotNull($statements);
        $this->assertNotNull($roots);
        $this->assertCount(1, $statements);
        $this->assertInstanceOf(ExpressionStatement::class, $statements[0]);
        $this->assertInstanceOf(FunctionCall::class, $statements[0]->expression);
        $this->assertSame('strtolower', $statements[0]->expression->name);
        $this->assertSame('DO_ICALL', $statements[0]->expression->executeOpcode);
        $this->assertSame(0, $statements[0]->expression->executeExtendedValue);
        $this->assertCount(1, $statements[0]->expression->arguments);
        $this->assertSame($statements[0]->expression, $this->rootCall($roots));
        $this->assertSame($statements[0]->opline, $statements[0]->expression->opline);
        $this->assertSame([], $result->diagnostics);
    }

    public function testReturnLiteralVariableAndExpression(): void
    {
        $literal = $this->onlyStatement('returnLiteral');
        $this->assertInstanceOf(ReturnStatement::class, $literal);
        $this->assertSame(0, $literal->opline);
        $this->assertSame(32, $literal->line);
        $this->assertInstanceOf(Literal::class, $literal->value);
        $this->assertSame('int', $literal->value->type);
        $this->assertSame(7, $literal->value->value);

        $variable = $this->onlyStatement('returnVariable');
        $this->assertInstanceOf(ReturnStatement::class, $variable);
        $this->assertInstanceOf(VariableRead::class, $variable->value);
        $this->assertSame('value', $variable->value->name);
        $this->assertSame(37, $variable->line);

        $product = $this->onlyStatement('returnExpression');
        $this->assertInstanceOf(ReturnStatement::class, $product);
        $this->assertInstanceOf(Binary::class, $product->value);
        $this->assertSame('*', $product->value->operator);
        $this->assertInstanceOf(VariableRead::class, $product->value->left);
        $this->assertSame('price', $product->value->left->name);
        $this->assertInstanceOf(VariableRead::class, $product->value->right);
        $this->assertSame('quantity', $product->value->right->name);
        $this->assertLessThan($product->opline, $product->value->opline);
    }

    public function testABareReturnIsNullAndTheEpilogueIsOmitted(): void
    {
        $result = $this->statements('returnNothing');
        $statements = $result->blockStartingAt(0)?->statements;
        $this->assertNotNull($statements);
        $this->assertCount(1, $statements);
        $this->assertInstanceOf(ReturnStatement::class, $statements[0]);
        $this->assertInstanceOf(Literal::class, $statements[0]->value);
        $this->assertSame('null', $statements[0]->value->type);
        $this->assertNull($statements[0]->value->value);
        $this->assertSame([], $result->blockStartingAt(1)?->statements);
    }

    public function testThrowKeepsTheConstructorAndTheThrownValue(): void
    {
        [$expressions, $result] = $this->both('throwLiteral');
        $statements = $result->blockStartingAt(0)?->statements;
        $this->assertNotNull($statements);
        $this->assertCount(3, $statements);
        $this->assertInstanceOf(OpaqueStatement::class, $statements[0]);
        $this->assertSame('SEND_VAL_EX', $statements[0]->instruction->opcode);
        $this->assertInstanceOf(Literal::class, $statements[0]->instruction->op1->resolved);
        $this->assertSame('bad', $statements[0]->instruction->op1->resolved->value);
        $this->assertInstanceOf(OpaqueStatement::class, $statements[1]);
        $this->assertSame('DO_FCALL', $statements[1]->instruction->opcode);
        $this->assertInstanceOf(ThrowStatement::class, $statements[2]);
        $this->assertSame(52, $statements[2]->line);
        $this->assertInstanceOf(Opaque::class, $statements[2]->exception);
        $this->assertSame('NEW', $statements[2]->exception->opcode);
        $this->assertSame('Exception', $statements[2]->exception->op1->resolved->value ?? null);
        $thrown = null;
        foreach ($expressions->blockStartingAt(0)?->expressions ?? [] as $expression) {
            if ($expression instanceof Opaque && $expression->opcode === 'THROW') {
                $thrown = $expression->op1->resolved;
            }
        }
        $this->assertSame($thrown, $statements[2]->exception);
        $this->assertSame([], $result->blockStartingAt(4)?->statements);
        $opcodes = array_map(static fn ($diagnostic) => $diagnostic->opcode, $result->diagnostics);
        $this->assertNotContains('THROW', $opcodes);
        $this->assertContains('DO_FCALL', $opcodes);
    }

    public function testEchoStaysAnOpaqueStatement(): void
    {
        $result = $this->statements('echoValue');
        $statements = $result->blockStartingAt(0)?->statements;
        $this->assertNotNull($statements);
        $this->assertCount(1, $statements);
        $this->assertInstanceOf(OpaqueStatement::class, $statements[0]);
        $this->assertSame('ECHO', $statements[0]->instruction->opcode);
        $this->assertSame(57, $statements[0]->line);
        $this->assertSame(1, $statements[0]->opline);
        $this->assertInstanceOf(VariableRead::class, $statements[0]->instruction->op1->resolved);
        $this->assertSame('text', $statements[0]->instruction->op1->resolved->name);
        $this->assertSame('unused', $statements[0]->instruction->result->kind);
        $this->assertCount(1, $result->diagnostics);
        $this->assertSame('unsupported', $result->diagnostics[0]->kind);
        $this->assertSame('ECHO', $result->diagnostics[0]->opcode);
        $this->assertSame(1, $result->diagnostics[0]->opline);
        $this->assertSame(57, $result->diagnostics[0]->line);
        $this->assertSame('B0', $result->diagnostics[0]->blockId);
    }

    public function testCompilerBookkeepingDoesNotBecomeStatements(): void
    {
        [$expressions, $result] = $this->both('returnExpression');
        $roots = $expressions->blockStartingAt(0)?->expressions;
        $this->assertNotNull($roots);
        $opcodes = [];
        foreach ($roots as $root) {
            if ($root instanceof Opaque) {
                $opcodes[] = $root->opcode;
            }
        }
        $this->assertContains('RECV', $opcodes);
        $this->assertContains('VERIFY_RETURN_TYPE', $opcodes);

        $statements = $result->blockStartingAt(0)?->statements;
        $this->assertNotNull($statements);
        $this->assertCount(1, $statements);
        $this->assertInstanceOf(ReturnStatement::class, $statements[0]);
        $return = $roots[array_key_last($roots)];
        $this->assertInstanceOf(\Rephper\Expr\ReturnValue::class, $return);
        $this->assertSame($return->value, $statements[0]->value);
    }

    public function testAGraphForAnotherOpArrayIsRejected(): void
    {
        $document = $this->document();
        $literal = $document->opArrayNamed('assignLiteral');
        $sequence = $document->opArrayNamed('assignSequence');
        $this->assertInstanceOf(OpArray::class, $literal);
        $this->assertInstanceOf(OpArray::class, $sequence);
        $graph = (new Builder())->build($sequence);
        $expressions = (new ExpressionReconstructor())->reconstruct($literal, (new Builder())->build($literal));

        $this->expectException(StatementException::class);
        (new Reconstructor())->reconstruct($literal, $graph, $expressions);
    }

    private function onlyStatement(string $name): \Rephper\Stmt\Statement
    {
        $statements = $this->statements($name)->blockStartingAt(0)?->statements;
        $this->assertNotNull($statements);
        $this->assertCount(1, $statements);

        return $statements[0];
    }

    /**
     * @return array{0: \Rephper\Expr\Reconstruction, 1: \Rephper\Stmt\Reconstruction}
     */
    private function both(string $name): array
    {
        $opArray = $this->opArray($name);
        $graph = $this->graph($name);
        $expressions = (new ExpressionReconstructor())->reconstruct($opArray, $graph);

        return [$expressions, (new Reconstructor())->reconstruct($opArray, $graph, $expressions)];
    }

    private function statements(string $name): \Rephper\Stmt\Reconstruction
    {
        $opArray = $this->opArray($name);
        $graph = $this->graph($name);

        return (new Reconstructor())->reconstruct($opArray, $graph, $this->expressions($name));
    }

    private function expressions(string $name): \Rephper\Expr\Reconstruction
    {
        return (new ExpressionReconstructor())->reconstruct($this->opArray($name), $this->graph($name));
    }

    private function graph(string $name): \Rephper\Cfg\Graph
    {
        foreach ((new Builder())->buildDocument($this->document()) as $graph) {
            if ($graph->name === $name) {
                return $graph;
            }
        }

        $this->fail('Missing graph ' . $name);
    }

    /**
     * @param list<\Rephper\Expr\Expression> $expressions
     */
    private function rootCall(array $expressions): FunctionCall
    {
        foreach ($expressions as $expression) {
            if ($expression instanceof FunctionCall) {
                return $expression;
            }
        }

        $this->fail('Missing function call');
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
        $json = (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Statements.json');
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);
        $document = (new Reader())->readString($json);

        return $document;
    }
}
