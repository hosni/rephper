<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Expr;

use PHPUnit\Framework\TestCase;
use Rephper\Application\OpArrayPipeline;
use Rephper\Cfg\Builder;
use Rephper\Expr\Argument;
use Rephper\Expr\Assignment;
use Rephper\Expr\Binary;
use Rephper\Expr\Expression;
use Rephper\Expr\FunctionCall;
use Rephper\Expr\Literal;
use Rephper\Expr\ParentPropertyHookCall;
use Rephper\Expr\Reconstructor;
use Rephper\Expr\ReturnValue;
use Rephper\Ir\Document;
use Rephper\Ir\OpArray;
use Rephper\Ir\Reader;
use Rephper\Tests\Support\Process;

final class ParentPropertyHookCallTest extends TestCase
{
    public function testAParentGetIsNotAFunctionOrMethodCall(): void
    {
        $call = $this->returnedCall('BackedChild', '$value::get');

        $this->assertSame('value', $call->property);
        $this->assertSame('get', $call->hook);
        $this->assertSame([], $call->arguments);
        $this->assertSame(0, $call->initExtendedValue);
        $this->assertFalse($call->checksUndefinedArguments);
        $this->assertLessThan($call->opline, $call->initOpline);
        $this->assertSame([], $this->diagnostics('BackedChild', '$value::get'));
    }

    public function testTwoParentGetsStayDistinctOperands(): void
    {
        $return = $this->userReturn('MultiChild', '$value::get');
        $this->assertInstanceOf(Binary::class, $return->value);
        $left = $return->value->left;
        $right = $return->value->right;

        $this->assertInstanceOf(ParentPropertyHookCall::class, $left);
        $this->assertInstanceOf(ParentPropertyHookCall::class, $right);
        $this->assertNotSame($left, $right);
        $this->assertSame('value', $left->property);
        $this->assertSame('value', $right->property);
        $this->assertSame('get', $left->hook);
        $this->assertSame('get', $right->hook);
        $this->assertSame([], $left->arguments);
        $this->assertSame([], $right->arguments);
        $this->assertLessThan($right->initOpline, $left->opline);
        $this->assertLessThan($return->value->opline, $right->opline);
    }

    public function testAParentSetKeepsANestedCallAndTheNoRefSend(): void
    {
        $call = $this->statementCall('CallChild', '$value::set');

        $this->assertSame('set', $call->hook);
        $this->assertSame(1, $call->initExtendedValue);
        $this->assertCount(1, $call->arguments);
        $argument = $call->arguments[0];
        $this->assertInstanceOf(Argument::class, $argument);
        $this->assertSame('SEND_VAR_NO_REF_EX', $argument->opcode);
        $this->assertSame(Argument::VALUE, $argument->mode);
        $this->assertNull($argument->name);
        $this->assertSame(1, $argument->position);
        $this->assertInstanceOf(FunctionCall::class, $argument->value);
        $this->assertSame('abs', $argument->value->name);
        $this->assertNotInstanceOf(FunctionCall::class, $call);
        $this->assertSame([], $this->diagnostics('CallChild', '$value::set'));
    }

    public function testANamedParentSetArgumentKeepsItsName(): void
    {
        $call = $this->statementCall('NamedChild', '$value::set');

        $this->assertSame('set', $call->hook);
        $this->assertSame(0, $call->initExtendedValue);
        $this->assertTrue($call->checksUndefinedArguments);
        $this->assertCount(1, $call->arguments);
        $this->assertSame('value', $call->arguments[0]->name);
        $this->assertSame('SEND_VAR_EX', $call->arguments[0]->opcode);
    }

    public function testAnAssignedParentGetKeepsTheResultSlot(): void
    {
        $expressions = $this->roots('AssignChild', '$value::get');
        $assignment = null;
        foreach ($expressions as $expression) {
            if ($expression instanceof Assignment) {
                $assignment = $expression;
            }
        }
        $this->assertInstanceOf(Assignment::class, $assignment);
        $this->assertSame('n', $assignment->variable);
        $this->assertInstanceOf(ParentPropertyHookCall::class, $assignment->value);
        $this->assertLessThan($assignment->opline, $assignment->value->opline);
    }

    public function testUnsupportedParentHookSequencesFailClosed(): void
    {
        $version = Process::run(['php8.4', '-n', '-r', 'echo PHP_VERSION;']);
        $this->assertSame(0, $version['exit'], $version['stderr']);
        $this->assertSame('8.4.27', $version['stdout']);

        $expected = [
            'AndChild' => 'parent property hook call does not finish in this block',
            'NullChild' => 'parent property hook call does not finish in this block',
            'MatchChild' => 'parent property hook call was interrupted by MATCH',
            'OtherChild' => 'parent property hook call was interrupted by CHECK_FUNC_ARG',
        ];
        $document = $this->document('PHP/8.4/PropertyHookParentUnsupported.json');
        $pipeline = new OpArrayPipeline();
        foreach ($expected as $class => $reason) {
            $opArray = $this->opArray($document, $class, '$value::set');
            $structured = $pipeline->structure($document, $opArray);
            $reasons = array_map(static fn ($diagnostic): string => $diagnostic->reason, $structured->diagnostics);
            $this->assertContains($reason, $reasons, $class . "\n" . implode("\n", $reasons));
        }
    }

    private function returnedCall(string $class, string $name): ParentPropertyHookCall
    {
        $return = $this->userReturn($class, $name);
        $this->assertInstanceOf(Binary::class, $return->value);
        $this->assertInstanceOf(ParentPropertyHookCall::class, $return->value->left);

        return $return->value->left;
    }

    private function userReturn(string $class, string $name): ReturnValue
    {
        $found = null;
        foreach ($this->roots($class, $name) as $expression) {
            if ($expression instanceof ReturnValue && !$this->nullLiteral($expression->value)) {
                $found = $expression;
            }
        }
        $this->assertInstanceOf(ReturnValue::class, $found);

        return $found;
    }

    private function statementCall(string $class, string $name): ParentPropertyHookCall
    {
        foreach ($this->roots($class, $name) as $expression) {
            if ($expression instanceof ParentPropertyHookCall) {
                return $expression;
            }
        }
        $this->fail($class . ' ' . $name . ' has no parent property hook call');
    }

    /**
     * @return list<string>
     */
    private function diagnostics(string $class, string $name): array
    {
        $document = $this->document('PHP/8.4/PropertyHookParent.json');
        $opArray = $this->opArray($document, $class, $name);
        $graphs = (new Builder())->buildDocument($document);
        $graph = null;
        foreach ($document->opArrays as $index => $candidate) {
            if ($candidate === $opArray) {
                $graph = $graphs[$index];
                break;
            }
        }
        $this->assertNotNull($graph);
        $reconstruction = (new Reconstructor())->reconstruct($opArray, $graph);

        return array_map(static fn ($diagnostic): string => $diagnostic->opcode . ' ' . $diagnostic->reason, $reconstruction->diagnostics);
    }

    /**
     * @return list<Expression>
     */
    private function roots(string $class, string $name): array
    {
        $document = $this->document('PHP/8.4/PropertyHookParent.json');
        $opArray = $this->opArray($document, $class, $name);
        $graphs = (new Builder())->buildDocument($document);
        $graph = null;
        foreach ($document->opArrays as $index => $candidate) {
            if ($candidate === $opArray) {
                $graph = $graphs[$index];
                break;
            }
        }
        $this->assertNotNull($graph);
        $reconstruction = (new Reconstructor())->reconstruct($opArray, $graph);
        $roots = [];
        foreach ($reconstruction->blocks as $block) {
            foreach ($block->expressions as $expression) {
                $roots[] = $expression;
            }
        }

        return $roots;
    }

    private function nullLiteral(mixed $expression): bool
    {
        return $expression instanceof Literal && $expression->type === 'null';
    }

    private function document(string $fixture): Document
    {
        return (new Reader())->readFile(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/' . $fixture);
    }

    private function opArray(Document $document, string $class, string $name): OpArray
    {
        foreach ($document->opArrays as $opArray) {
            if ($opArray->name === $name && $opArray->scope !== null && $opArray->scope->name === $class) {
                return $opArray;
            }
        }
        $this->fail('Missing op array ' . $class . '::' . $name);
    }
}
