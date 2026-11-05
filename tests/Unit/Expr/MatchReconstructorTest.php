<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Expr;

use PHPUnit\Framework\TestCase;
use Rephper\Cfg\Builder;
use Rephper\Cfg\Graph;
use Rephper\Cfg\LoopAnalyzer;
use Rephper\Expr\Assignment;
use Rephper\Expr\Binary;
use Rephper\Expr\Expression;
use Rephper\Expr\FunctionCall;
use Rephper\Expr\Literal;
use Rephper\Expr\MatchArm;
use Rephper\Expr\MatchExpression;
use Rephper\Expr\MatchReconstructor;
use Rephper\Expr\Opaque;
use Rephper\Expr\Reconstructor as ExpressionReconstructor;
use Rephper\Expr\ReturnValue;
use Rephper\Expr\Unary;
use Rephper\Expr\VariableRead;
use Rephper\Ir\Document;
use Rephper\Ir\OpArray;
use Rephper\Ir\Reader;
use Rephper\Stmt\ConditionalStructurer;
use Rephper\Stmt\ExpressionStatement;
use Rephper\Stmt\IfStatement;
use Rephper\Stmt\LoopStructurer;
use Rephper\Stmt\OpaqueStatement;
use Rephper\Stmt\Reconstructor as StatementReconstructor;
use Rephper\Stmt\ReturnStatement;
use Rephper\Stmt\StructuredStatements;
use Rephper\Stmt\ThrowStatement;
use Rephper\Stmt\WhileStatement;
use Rephper\Expr\Reconstruction;

final class MatchReconstructorTest extends TestCase
{
    public function testSimpleIntegerMatchPreservesArmsAndSubject(): void
    {
        [$opArray, , $expressions] = $this->prepare('simpleInt');
        $match = $this->returned($expressions);
        $instruction = $this->matchInstruction($opArray);

        $this->assertSame($instruction->index, $match->opline);
        $this->assertSame($instruction->line, $match->line);
        $this->assertSame($instruction->extendedValue, $match->fallbackOpline);
        $this->assertNull($match->unhandledOpline);
        $this->assertVariable($match->subject, 'value');
        $this->assertSame(1, $this->occurrences($match, $match->subject));
        $this->assertSame([1, 2, 3], $this->labels($match));
        $this->assertTrue($match->arms[3]->isDefault);
        $this->assertSame([], $match->arms[3]->conditions);
        $this->assertLiteral($match->arms[0]->result, 'string', 'one');
        $this->assertLiteral($match->arms[1]->result, 'string', 'two');
        $this->assertLiteral($match->arms[2]->result, 'string', 'three');
        $this->assertLiteral($match->arms[3]->result, 'string', 'other');
        $this->assertSame($match, $this->verified($expressions));
        $this->assertReconstructed($expressions->diagnostics);
    }

    public function testStringMatchPreservesStringKeysAndASharedArm(): void
    {
        $match = $this->returned($this->prepare('simpleString')[2]);

        $this->assertVariable($match->subject, 'value');
        $this->assertCount(3, $match->arms);
        $this->assertSame(['a'], $this->values($match->arms[0]));
        $this->assertSame(['b', 'c'], $this->values($match->arms[1]));
        $this->assertSame('string', $match->arms[1]->conditions[0]->type);
        $this->assertSame('string', $match->arms[1]->conditions[1]->type);
        $this->assertLiteral($match->arms[1]->result, 'string', 'few');
        $this->assertSame(1, $this->occurrences($match, $match->arms[1]->result));
        $this->assertTrue($match->arms[2]->isDefault);
        $this->assertNull($match->unhandledOpline);
    }

    public function testSeveralConditionsShareOneResult(): void
    {
        $match = $this->returned($this->prepare('sharedArm')[2]);

        $this->assertSame([1, 2], $this->values($match->arms[0]));
        $this->assertSame('int', $match->arms[0]->conditions[0]->type);
        $this->assertSame('int', $match->arms[0]->conditions[1]->type);
        $this->assertLiteral($match->arms[0]->result, 'string', 'few');
        $this->assertSame(1, $this->occurrences($match, $match->arms[0]->result));
        $this->assertSame([3], $this->values($match->arms[1]));
    }

    public function testDefaultArmIsOptionalAndOrdered(): void
    {
        $withDefault = $this->returned($this->prepare('withDefault')[2]);
        $first = $this->returned($this->prepare('defaultFirst')[2]);

        $this->assertNull($withDefault->unhandledOpline);
        $this->assertTrue($withDefault->arms[2]->isDefault);
        $this->assertLiteral($withDefault->arms[2]->result, 'int', 30);
        $this->assertTrue($first->arms[0]->isDefault);
        $this->assertSame([], $first->arms[0]->conditions);
        $this->assertLiteral($first->arms[0]->result, 'string', 'other');
        $this->assertSame([1, 2], $this->labels($first));
        $this->assertLessThan($first->arms[1]->opline, $first->arms[0]->opline);
    }

    public function testMissingDefaultRecordsUnhandledMatchError(): void
    {
        [, , $expressions, $structured] = $this->prepare('withoutDefault');
        $match = $this->returned($expressions);

        $this->assertSame($match->fallbackOpline, $match->unhandledOpline);
        $this->assertNotNull($match->unhandledOpline);
        $this->assertCount(2, $match->arms);
        foreach ($match->arms as $arm) {
            $this->assertFalse($arm->isDefault);
        }
        $this->assertSame([1, 2], $this->labels($match));
        $this->assertReconstructed($expressions->diagnostics);
        $this->assertSame([], $this->opcodes($structured, 'MATCH_ERROR'));
        $this->assertSame([], $this->throws($structured));
    }

    public function testSubjectIsTheExistingExpressionOnce(): void
    {
        $variable = $this->returned($this->prepare('subjectVar')[2]);
        $call = $this->returned($this->prepare('subjectCall')[2]);
        $sum = $this->returned($this->prepare('subjectExpr')[2]);
        $literal = $this->returned($this->prepare('literalSubject')[2]);

        $this->assertVariable($variable->subject, 'value');
        $this->assertSame(1, $this->occurrences($variable, $variable->subject));
        $this->assertInstanceOf(FunctionCall::class, $call->subject);
        $this->assertSame('getvalue', $call->subject->name);
        $this->assertLessThan($call->opline, $call->subject->opline);
        $this->assertSame(1, $this->occurrences($call, $call->subject));
        $this->assertInstanceOf(Binary::class, $sum->subject);
        $this->assertSame('+', $sum->subject->operator);
        $this->assertInstanceOf(VariableRead::class, $sum->subject->left);
        $this->assertSame(1, $this->occurrences($sum, $sum->subject));
        $this->assertLiteral($literal->subject, 'int', 1);
        $this->assertLiteral($literal->arms[0]->conditions[0], 'int', 1);
        $this->assertNotSame($literal->subject, $literal->arms[0]->conditions[0]);
    }

    public function testAssignedReturnedAndCombinedResultsUseOneMatch(): void
    {
        [, , $assignedExpressions, $assigned] = $this->prepare('assigned');
        $assignment = $assigned->statements[0];
        $returned = $assigned->statements[1];
        $this->assertInstanceOf(ExpressionStatement::class, $assignment);
        $this->assertInstanceOf(Assignment::class, $assignment->expression);
        $this->assertSame('result', $assignment->expression->variable);
        $match = $assignment->expression->value;
        $this->assertInstanceOf(MatchExpression::class, $match);
        $this->assertLiteral($match->arms[0]->result, 'string', 'one');
        $this->assertInstanceOf(FunctionCall::class, $match->arms[1]->result);
        $this->assertSame('calculate', $match->arms[1]->result->name);
        $this->assertInstanceOf(VariableRead::class, $match->arms[1]->result->arguments[0]->value);
        $this->assertNotSame($match->subject, $match->arms[1]->result->arguments[0]->value);
        $this->assertVariable($match->arms[2]->result, 'value');
        $this->assertTrue($match->arms[2]->isDefault);
        $this->assertInstanceOf(ReturnStatement::class, $returned);
        $this->assertInstanceOf(FunctionCall::class, $returned->value);
        $this->assertSame('label', $returned->value->name);
        $this->assertReconstructed($assignedExpressions->diagnostics);

        $arithmetic = $this->returnedExpression($this->prepare('inArithmetic')[2]);
        $this->assertInstanceOf(Binary::class, $arithmetic);
        $this->assertSame('+', $arithmetic->operator);
        $this->assertLiteral($arithmetic->left, 'int', 1);
        $this->assertInstanceOf(MatchExpression::class, $arithmetic->right);
        $this->assertLessThan($arithmetic->opline, $arithmetic->right->opline);

        $concat = $this->returnedExpression($this->prepare('inConcat')[2]);
        $this->assertInstanceOf(Binary::class, $concat);
        $this->assertSame('.', $concat->operator);
        $this->assertLiteral($concat->left, 'string', 'value: ');
        $this->assertInstanceOf(MatchExpression::class, $concat->right);
        $this->assertLessThan($concat->opline, $concat->right->opline);

        $argument = $this->returnedExpression($this->prepare('asArgument')[2]);
        $this->assertInstanceOf(FunctionCall::class, $argument);
        $this->assertSame('calculate', $argument->name);
        $this->assertCount(1, $argument->arguments);
        $this->assertInstanceOf(MatchExpression::class, $argument->arguments[0]->value);
        $this->assertSame(1, $argument->arguments[0]->position);
        $this->assertLessThan($argument->arguments[0]->value->opline, $argument->initOpline);
        $this->assertLessThan($argument->opline, $argument->arguments[0]->value->opline);
        $this->assertSame(1, $this->occurrences($argument, $argument->arguments[0]->value));
    }

    public function testArmResultsKeepTheirOwnExpressions(): void
    {
        $call = $this->returned($this->prepare('callInArm')[2]);
        $this->assertInstanceOf(FunctionCall::class, $call->arms[0]->result);
        $this->assertSame('calculate', $call->arms[0]->result->name);
        $this->assertSame(1, $this->occurrences($call, $call->arms[0]->result));
        $this->assertNotSame($call->subject, $call->arms[0]->result->arguments[0]->value);
        $this->assertLiteral($call->arms[1]->result, 'int', 2);
        $this->assertLiteral($call->arms[2]->result, 'int', 0);

        $mixed = $this->returned($this->prepare('mixedArmTypes')[2]);
        $this->assertLiteral($mixed->arms[0]->result, 'int', 10);
        $this->assertLiteral($mixed->arms[1]->result, 'string', 'two');
        $this->assertInstanceOf(Binary::class, $mixed->arms[2]->result);
        $this->assertSame('<', $mixed->arms[2]->result->operator);
        $this->assertLiteral($mixed->arms[3]->result, 'null', null);
        $this->assertTrue($mixed->arms[3]->isDefault);
    }

    public function testNestedMatchIsTheOuterArmResult(): void
    {
        $outer = $this->returned($this->prepare('nested')[2]);
        $inner = $outer->arms[0]->result;

        $this->assertInstanceOf(MatchExpression::class, $inner);
        $this->assertSame($inner, $outer->arms[0]->result);
        $this->assertVariable($outer->subject, 'value');
        $this->assertVariable($inner->subject, 'other');
        $this->assertNotSame($outer->subject, $inner->subject);
        $this->assertSame([1, 2], $this->labels($inner));
        $this->assertLiteral($inner->arms[2]->result, 'string', 'inner');
        $this->assertTrue($inner->arms[2]->isDefault);
        $this->assertLiteral($outer->arms[1]->result, 'string', 'outer');
        $this->assertSame(1, $this->occurrences($outer, $inner));
    }

    public function testTwoMatchesStayIndependent(): void
    {
        [, , $expressions, $structured] = $this->prepare('twoMatches');
        $found = $this->everyMatch($expressions);
        $this->assertCount(2, $found);
        $assignment = $structured->statements[0];
        $return = $structured->statements[1];
        $this->assertInstanceOf(ExpressionStatement::class, $assignment);
        $this->assertInstanceOf(Assignment::class, $assignment->expression);
        $this->assertSame('first', $assignment->expression->variable);
        $first = $assignment->expression->value;
        $this->assertInstanceOf(MatchExpression::class, $first);
        $this->assertInstanceOf(ReturnStatement::class, $return);
        $this->assertInstanceOf(MatchExpression::class, $return->value);
        $this->assertNotSame($first, $return->value);
        $this->assertVariable($return->value->subject, 'first');
        $this->assertNotSame($first, $return->value->subject);
        $this->assertSame([10, 20], $this->labels($return->value));
    }

    public function testMatchInsideAConditionalStaysInTheBranch(): void
    {
        [, , $expressions, $structured] = $this->prepare('matchInIf');
        $conditional = $structured->statements[0];
        $after = $structured->statements[1];

        $this->assertInstanceOf(IfStatement::class, $conditional);
        $this->assertNull($conditional->elseStatements);
        $this->assertNotInstanceOf(MatchExpression::class, $conditional->branches[0]->condition);
        $branch = $conditional->branches[0]->statements[0];
        $this->assertInstanceOf(ReturnStatement::class, $branch);
        $this->assertInstanceOf(MatchExpression::class, $branch->value);
        $this->assertVariable($branch->value->subject, 'value');
        $this->assertInstanceOf(ReturnStatement::class, $after);
        $this->assertLiteral($after->value, 'string', 'no');
        $this->assertReconstructed($expressions->diagnostics);
        $this->assertSame([], $this->reasons($structured, 'unstructured'));
    }

    public function testMatchInsideALoopStaysInTheBody(): void
    {
        [, , $expressions, $structured] = $this->prepare('matchInWhile');
        $loop = $structured->statements[0];
        $after = $structured->statements[1];

        $this->assertInstanceOf(WhileStatement::class, $loop);
        $this->assertInstanceOf(Binary::class, $loop->condition);
        $this->assertNotInstanceOf(MatchExpression::class, $loop->condition);
        $body = $loop->body[0];
        $this->assertInstanceOf(ExpressionStatement::class, $body);
        $this->assertInstanceOf(Assignment::class, $body->expression);
        $this->assertSame('n', $body->expression->variable);
        $this->assertInstanceOf(MatchExpression::class, $body->expression->value);
        $this->assertVariable($body->expression->value->subject, 'n');
        $this->assertSame([0, 1], $this->labels($body->expression->value));
        $this->assertInstanceOf(ReturnStatement::class, $after);
        $this->assertVariable($after->value, 'n');
        $this->assertSame([], $this->reasons($structured, 'unstructured'));
        foreach ($expressions->diagnostics as $diagnostic) {
            $this->assertNotSame('MATCH', $diagnostic->opcode);
        }
    }

    public function testComparisonChainsStayUnstructured(): void
    {
        foreach (['dynamicCond', 'oneCond', 'subjectCompare'] as $name) {
            [, , $expressions] = $this->prepare($name);
            $this->assertSame([], $this->everyMatch($expressions), $name);
        }

        [, , $expressions, $structured] = $this->prepare('subjectCompare');
        $this->assertNotSame([], $this->opcodes($structured, 'MATCH_ERROR'));
        $this->assertSame([], $this->throws($structured));
        $this->assertNotSame([], $this->literals($expressions, 'yes'));
    }

    public function testAmbiguousMatchesDoNotProduceAPartialExpression(): void
    {
        [, , $duplicateExpressions, $duplicate] = $this->prepare('duplicateCond');
        $this->assertSame([], $this->everyMatch($duplicateExpressions));
        $this->assertContains('match result has an extra arm.', $this->reasons($duplicateExpressions, 'unstructured'));
        $this->assertNotSame([], $this->literals($duplicateExpressions, 'first'));
        $this->assertNotSame([], $this->literals($duplicateExpressions, 'second'));
        $this->assertNotSame([], $this->opcodes($duplicate, 'MATCH'));
        $this->assertSame([], $this->throws($duplicate));

        [, , $boolExpressions, $boolStructured] = $this->prepare('boolArms');
        $this->assertSame([], $this->everyMatch($boolExpressions));
        $this->assertContains('match arm result is ambiguous.', $this->reasons($boolExpressions, 'unstructured'));
        $this->assertNotSame([], $this->literals($boolExpressions, 'zero'));
        $this->assertNotSame([], $this->literals($boolExpressions, 'other'));
        $this->assertNotSame([], $this->opcodes($boolStructured, 'MATCH'));
        $returned = $this->unresolvedReturn($boolExpressions);
        $this->assertNotInstanceOf(MatchExpression::class, $returned);
    }

    public function testIdentityKeysKeepIntegerAndStringTypes(): void
    {
        $mixed = $this->returned($this->prepare('mixedTypes')[2]);
        $numeric = $this->returned($this->prepare('numericString')[2]);

        $this->assertSame('int', $mixed->arms[0]->conditions[0]->type);
        $this->assertSame(1, $mixed->arms[0]->conditions[0]->value);
        $this->assertSame('string', $mixed->arms[1]->conditions[0]->type);
        $this->assertSame('a', $mixed->arms[1]->conditions[0]->value);
        $this->assertSame('string', $numeric->arms[0]->conditions[0]->type);
        $this->assertSame('1', $numeric->arms[0]->conditions[0]->value);
        $this->assertSame('string', $numeric->arms[1]->conditions[0]->type);
        $this->assertSame('2', $numeric->arms[1]->conditions[0]->value);
        $this->assertNull($mixed->unhandledOpline);
        $this->assertNull($numeric->unhandledOpline);
    }

    public function testAFunctionWithoutMatchIsUnchanged(): void
    {
        $opArray = $this->opArray('dynamicCond');
        $graph = (new Builder())->build($opArray);
        $reconstruction = (new ExpressionReconstructor())->reconstruct($opArray, $graph);

        $this->assertSame($reconstruction, (new MatchReconstructor())->reconstruct($opArray, $graph, $reconstruction));
    }

    public function testConditionalStructurerStillRefusesMatch(): void
    {
        [, $graph, , $statements] = $this->statements('simpleInt');
        $structured = (new ConditionalStructurer())->structure($graph, $statements);

        $this->assertContains('MATCH is not structured as a conditional.', $this->reasons($structured, 'unstructured'));
    }

    /**
     * @return array{0: OpArray, 1: Graph, 2: Reconstruction, 3: StructuredStatements}
     */
    private function prepare(string $name): array
    {
        [$opArray, $graph, $expressions, $statements] = $this->statements($name);
        $analysis = (new LoopAnalyzer())->analyze($graph, $opArray);
        $structured = (new LoopStructurer())->structure($graph, $analysis, $statements);

        return [$opArray, $graph, $expressions, $structured];
    }

    /**
     * @return array{0: OpArray, 1: Graph, 2: Reconstruction, 3: \Rephper\Stmt\Reconstruction}
     */
    private function statements(string $name): array
    {
        $opArray = $this->opArray($name);
        $graph = (new Builder())->build($opArray);
        $edges = [];
        foreach ($graph->blocks as $block) {
            $edges[$block->id] = $block->successors;
        }
        $expressions = (new ExpressionReconstructor())->reconstruct($opArray, $graph);
        foreach ($graph->blocks as $block) {
            $this->assertSame($edges[$block->id], $block->successors, $name);
        }
        $statements = (new StatementReconstructor())->reconstruct($opArray, $graph, $expressions);

        return [$opArray, $graph, $expressions, $statements];
    }

    private function returned(Reconstruction $expressions): MatchExpression
    {
        $value = $this->returnedExpression($expressions);
        $this->assertInstanceOf(MatchExpression::class, $value);

        return $value;
    }

    private function returnedExpression(Reconstruction $expressions): Expression
    {
        foreach ($expressions->blocks as $block) {
            foreach ($block->expressions as $expression) {
                if ($expression instanceof ReturnValue && $expression->value instanceof Expression && !$this->nullLiteral($expression->value)) {
                    return $expression->value;
                }
            }
        }
        $this->fail('No returned value');
    }

    private function verified(Reconstruction $expressions): ?Expression
    {
        foreach ($expressions->blocks as $block) {
            foreach ($block->expressions as $expression) {
                if (
                    $expression instanceof Opaque
                    && $expression->opcode === 'VERIFY_RETURN_TYPE'
                    && $expression->op1->resolved instanceof MatchExpression
                ) {
                    return $expression->op1->resolved;
                }
            }
        }

        return null;
    }

    private function unresolvedReturn(Reconstruction $expressions): ?Expression
    {
        foreach ($expressions->blocks as $block) {
            foreach ($block->expressions as $expression) {
                if ($expression instanceof ReturnValue && $expression->value instanceof Expression && !$this->nullLiteral($expression->value)) {
                    return $expression->value;
                }
            }
        }

        return null;
    }

    /**
     * @return list<MatchExpression>
     */
    private function everyMatch(Reconstruction $expressions): array
    {
        $found = [];
        foreach ($expressions->blocks as $block) {
            foreach ($block->expressions as $expression) {
                $this->collect($expression, $found);
            }
        }

        return array_values($found);
    }

    /**
     * @param array<int, MatchExpression> $found
     */
    private function collect(Expression $expression, array &$found): void
    {
        if ($expression instanceof MatchExpression) {
            $found[spl_object_id($expression)] = $expression;
            $this->collect($expression->subject, $found);
            foreach ($expression->arms as $arm) {
                $this->collect($arm->result, $found);
            }

            return;
        }
        if ($expression instanceof Binary) {
            $this->collect($expression->left, $found);
            $this->collect($expression->right, $found);
        } elseif ($expression instanceof Unary) {
            $this->collect($expression->operand, $found);
        } elseif ($expression instanceof Assignment) {
            $this->collect($expression->value, $found);
        } elseif ($expression instanceof ReturnValue && $expression->value instanceof Expression) {
            $this->collect($expression->value, $found);
        } elseif ($expression instanceof FunctionCall) {
            foreach ($expression->arguments as $argument) {
                $this->collect($argument->value, $found);
            }
        } elseif ($expression instanceof Opaque) {
            foreach ([$expression->op1, $expression->op2, $expression->result] as $operand) {
                if ($operand->resolved instanceof Expression) {
                    $this->collect($operand->resolved, $found);
                }
            }
        }
    }

    /**
     * @param list<\Rephper\Expr\Diagnostic> $diagnostics
     */
    private function assertReconstructed(array $diagnostics): void
    {
        foreach ($diagnostics as $diagnostic) {
            $this->assertNotContains($diagnostic->opcode, ['MATCH', 'MATCH_ERROR', 'QM_ASSIGN', 'FREE']);
        }
    }

    /**
     * @return list<int|string>
     */
    private function labels(MatchExpression $match): array
    {
        $labels = [];
        foreach ($match->arms as $arm) {
            if ($arm->isDefault && $arm->conditions === []) {
                continue;
            }
            foreach ($arm->conditions as $condition) {
                $this->assertContains($condition->type, ['int', 'string']);
                $labels[] = $condition->value;
            }
        }

        return $labels;
    }

    /**
     * @return list<int|string>
     */
    private function values(MatchArm $arm): array
    {
        $values = [];
        foreach ($arm->conditions as $condition) {
            $values[] = $condition->value;
        }

        return $values;
    }

    private function occurrences(Expression $expression, object $target): int
    {
        if ($expression === $target) {
            return 1;
        }
        $count = 0;
        if ($expression instanceof MatchExpression) {
            $count += $this->occurrences($expression->subject, $target);
            foreach ($expression->arms as $arm) {
                $count += $this->occurrences($arm->result, $target);
            }
        } elseif ($expression instanceof Binary) {
            $count += $this->occurrences($expression->left, $target);
            $count += $this->occurrences($expression->right, $target);
        } elseif ($expression instanceof Unary) {
            $count += $this->occurrences($expression->operand, $target);
        } elseif ($expression instanceof Assignment) {
            $count += $this->occurrences($expression->value, $target);
        } elseif ($expression instanceof ReturnValue && $expression->value instanceof Expression) {
            $count += $this->occurrences($expression->value, $target);
        } elseif ($expression instanceof FunctionCall) {
            foreach ($expression->arguments as $argument) {
                $count += $this->occurrences($argument->value, $target);
            }
        } elseif ($expression instanceof Opaque) {
            foreach ([$expression->op1, $expression->op2, $expression->result] as $operand) {
                if ($operand->resolved instanceof Expression) {
                    $count += $this->occurrences($operand->resolved, $target);
                }
            }
        }

        return $count;
    }

    private function assertVariable(Expression $expression, string $name): void
    {
        $this->assertInstanceOf(VariableRead::class, $expression);
        $this->assertSame($name, $expression->name);
    }

    private function assertLiteral(Expression $expression, string $type, mixed $value): void
    {
        $this->assertInstanceOf(Literal::class, $expression);
        $this->assertSame($type, $expression->type);
        $this->assertSame($value, $expression->value);
    }

    private function nullLiteral(Expression $expression): bool
    {
        return $expression instanceof Literal && $expression->type === 'null';
    }

    /**
     * @return list<mixed>
     */
    private function literals(Reconstruction $expressions, mixed $value): array
    {
        $found = [];
        $walk = function (Expression $expression) use (&$walk, &$found, $value): void {
            if ($expression instanceof Literal && $expression->value === $value) {
                $found[] = $expression->value;
            }
            if ($expression instanceof Binary) {
                $walk($expression->left);
                $walk($expression->right);
            } elseif ($expression instanceof Unary) {
                $walk($expression->operand);
            } elseif ($expression instanceof Assignment) {
                $walk($expression->value);
            } elseif ($expression instanceof ReturnValue && $expression->value instanceof Expression) {
                $walk($expression->value);
            } elseif ($expression instanceof FunctionCall) {
                foreach ($expression->arguments as $argument) {
                    $walk($argument->value);
                }
            } elseif ($expression instanceof Opaque) {
                foreach ([$expression->op1, $expression->op2, $expression->result] as $operand) {
                    if ($operand->resolved instanceof Expression) {
                        $walk($operand->resolved);
                    }
                }
            }
        };
        foreach ($expressions->blocks as $block) {
            foreach ($block->expressions as $expression) {
                $walk($expression);
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function reasons(Reconstruction|StructuredStatements $result, string $kind): array
    {
        $reasons = [];
        foreach ($result->diagnostics as $diagnostic) {
            if ($diagnostic->kind === $kind) {
                $reasons[] = $diagnostic->reason;
            }
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function opcodes(StructuredStatements $result, string $opcode): array
    {
        $found = [];
        $walk = function (array $statements) use (&$walk, &$found, $opcode): void {
            foreach ($statements as $statement) {
                if ($statement instanceof OpaqueStatement && $statement->instruction->opcode === $opcode) {
                    $found[] = $opcode;
                }
                if ($statement instanceof IfStatement) {
                    foreach ($statement->branches as $branch) {
                        $walk($branch->statements);
                    }
                    if ($statement->elseStatements !== null) {
                        $walk($statement->elseStatements);
                    }
                }
                if ($statement instanceof WhileStatement) {
                    $walk($statement->body);
                }
            }
        };
        $walk($result->statements);

        return $found;
    }

    /**
     * @return list<ThrowStatement>
     */
    private function throws(StructuredStatements $result): array
    {
        $found = [];
        $walk = function (array $statements) use (&$walk, &$found): void {
            foreach ($statements as $statement) {
                if ($statement instanceof ThrowStatement) {
                    $found[] = $statement;
                }
                if ($statement instanceof IfStatement) {
                    foreach ($statement->branches as $branch) {
                        $walk($branch->statements);
                    }
                }
                if ($statement instanceof WhileStatement) {
                    $walk($statement->body);
                }
            }
        };
        $walk($result->statements);

        return $found;
    }

    private function matchInstruction(OpArray $opArray): \Rephper\Ir\Instruction
    {
        foreach ($opArray->instructions as $instruction) {
            if ($instruction->opcode === 'MATCH') {
                return $instruction;
            }
        }
        $this->fail('No MATCH instruction');
    }

    private function opArray(string $name): OpArray
    {
        $opArray = $this->document()->opArrayNamed($name);
        $this->assertNotNull($opArray);

        return $opArray;
    }

    private function document(): Document
    {
        static $document = null;
        if ($document instanceof Document) {
            return $document;
        }
        $json = (string) file_get_contents(dirname(__DIR__, 2) . '/Expected/PHP/8.4/Common/MatchExpressions.json');
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);
        $document = (new Reader())->readString($json);

        return $document;
    }
}
