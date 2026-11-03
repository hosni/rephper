<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Expr;

use PHPUnit\Framework\TestCase;
use Rephper\Cfg\Builder;
use Rephper\Expr\Assignment;
use Rephper\Expr\Binary;
use Rephper\Expr\ExpressionException;
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
