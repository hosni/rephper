<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\CodeGen;

use PHPUnit\Framework\TestCase;
use Rephper\CodeGen\PhpSourceGenerator;
use Rephper\CodeGen\SourceGenerationException;
use Rephper\Expr\Argument;
use Rephper\Expr\Assignment;
use Rephper\Expr\Binary;
use Rephper\Expr\Expression;
use Rephper\Expr\FunctionCall;
use Rephper\Expr\Literal;
use Rephper\Expr\Opaque;
use Rephper\Expr\OperandUse;
use Rephper\Expr\VariableRead;
use Rephper\Stmt\BreakStatement;
use Rephper\Stmt\CatchClause;
use Rephper\Stmt\CompiledVariableTarget;
use Rephper\Stmt\ConditionalBranch;
use Rephper\Stmt\ContinueStatement;
use Rephper\Stmt\DoWhileStatement;
use Rephper\Stmt\ExpressionStatement;
use Rephper\Stmt\FinallyClause;
use Rephper\Stmt\ForStatement;
use Rephper\Stmt\ForeachStatement;
use Rephper\Stmt\IfStatement;
use Rephper\Stmt\OpaqueStatement;
use Rephper\Stmt\ReturnStatement;
use Rephper\Stmt\Statement;
use Rephper\Stmt\StructuredStatements;
use Rephper\Stmt\SwitchBreakStatement;
use Rephper\Stmt\SwitchCase;
use Rephper\Stmt\SwitchContinueStatement;
use Rephper\Stmt\SwitchStatement;
use Rephper\Stmt\ThrowStatement;
use Rephper\Stmt\TryStatement;
use Rephper\Stmt\WhileStatement;
use Rephper\Tests\Support\Process;

final class StatementGeneratorTest extends TestCase
{
    public function testExpressionReturnAndThrowStatements(): void
    {
        $this->assertSource([
            $this->expr($this->assign('a', $this->int(10))),
            $this->expr($this->call('strtolower', [$this->arg($this->v('text'))])),
        ], <<<'PHP'
<?php
$a = 10;

strtolower($text);
PHP);
        $this->assertSource([
            new ReturnStatement(2, 3, $this->bin('+', $this->v('price'), $this->v('quantity'))),
        ], <<<'PHP'
<?php
return $price + $quantity;
PHP);
        $this->assertSource([
            new ReturnStatement(2, 3, $this->lit('null', null)),
        ], <<<'PHP'
<?php
return null;
PHP);
        $this->assertSource([
            new ThrowStatement(4, 5, $this->v('exception')),
        ], <<<'PHP'
<?php
throw $exception;
PHP);
    }

    public function testConditionalsKeepBranchesAndNesting(): void
    {
        $this->assertSource([
            $this->ifStmt([
                $this->branch($this->v('a'), [$this->expr($this->assign('x', $this->int(1)))]),
            ], null),
        ], <<<'PHP'
<?php
if ($a) {
    $x = 1;
}
PHP);
        $this->assertSource([
            $this->ifStmt([
                $this->branch($this->v('a'), [$this->expr($this->assign('x', $this->int(1)))]),
            ], [$this->expr($this->assign('x', $this->int(2)))]),
        ], <<<'PHP'
<?php
if ($a) {
    $x = 1;
} else {
    $x = 2;
}
PHP);
        $this->assertSource([
            $this->ifStmt([
                $this->branch($this->v('a'), [$this->expr($this->assign('x', $this->int(1)))]),
            ], []),
        ], <<<'PHP'
<?php
if ($a) {
    $x = 1;
} else {
}
PHP);
        $this->assertSource([
            $this->ifStmt([
                $this->branch($this->bin('===', $this->v('a'), $this->int(1)), [
                    $this->expr($this->assign('x', $this->int(1))),
                ]),
                $this->branch($this->bin('===', $this->v('a'), $this->int(2)), [
                    $this->expr($this->assign('x', $this->int(2))),
                ]),
            ], [$this->expr($this->assign('x', $this->int(3)))]),
        ], <<<'PHP'
<?php
if ($a === 1) {
    $x = 1;
} elseif ($a === 2) {
    $x = 2;
} else {
    $x = 3;
}
PHP);
        $this->assertSource([
            $this->ifStmt([
                $this->branch($this->v('a'), [
                    $this->ifStmt([
                        $this->branch($this->v('b'), [$this->expr($this->assign('x', $this->int(1)))]),
                    ], [$this->expr($this->assign('x', $this->int(2)))]),
                ]),
            ], [$this->expr($this->assign('x', $this->int(3)))]),
        ], <<<'PHP'
<?php
if ($a) {
    if ($b) {
        $x = 1;
    } else {
        $x = 2;
    }
} else {
    $x = 3;
}
PHP);
    }

    public function testLoopsKeepTheirClausesAndReferenceTargets(): void
    {
        $this->assertSource([
            new WhileStatement(1, 1, $this->v('n'), [
                $this->expr($this->assign('n', $this->bin('-', $this->v('n'), $this->int(1)))),
            ], 'L0'),
        ], <<<'PHP'
<?php
while ($n) {
    $n = $n - 1;
}
PHP);
        $this->assertSource([
            new WhileStatement(1, 1, $this->assign('line', $this->v('items')), [], 'L0'),
        ], <<<'PHP'
<?php
while ($line = $items) {
}
PHP);
        $this->assertSource([
            new DoWhileStatement(1, 1, $this->bin('<', $this->v('x'), $this->int(10)), [
                $this->expr($this->assign('x', $this->int(1))),
            ], 'L0'),
        ], <<<'PHP'
<?php
do {
    $x = 1;
} while ($x < 10);
PHP);
        $this->assertSource([
            new ForStatement(1, 1, [
                $this->assign('i', $this->int(0)),
                $this->assign('j', $this->int(1)),
            ], $this->bin('<', $this->v('i'), $this->int(10)), [
                $this->assign('i', $this->bin('+', $this->v('i'), $this->int(1))),
                $this->assign('j', $this->bin('+', $this->v('j'), $this->int(1))),
            ], [
                $this->expr($this->assign('x', $this->v('i'))),
            ], 'L0'),
        ], <<<'PHP'
<?php
for ($i = 0, $j = 1; $i < 10; $i = $i + 1, $j = $j + 1) {
    $x = $i;
}
PHP);
        $this->assertSource([
            new ForStatement(1, 1, [$this->assign('i', $this->int(0))], null, [
                $this->assign('i', $this->bin('+', $this->v('i'), $this->int(1))),
            ], [], 'L0'),
            new ForStatement(2, 2, [], null, [], [], 'L1'),
            new ForStatement(3, 3, [], $this->bin('<', $this->v('i'), $this->int(10)), [], [], 'L2'),
            new ForStatement(4, 4, [$this->assign('i', $this->int(0))], $this->lit('bool', true), [], [], 'L3'),
        ], <<<'PHP'
<?php
for ($i = 0;; $i = $i + 1) {
}

for (;;) {
}

for (; $i < 10;) {
}

for ($i = 0; true;) {
}
PHP);
        $items = $this->v('items');
        $this->assertSource([
            $this->foreachStmt($items, null, 'value', false, []),
            $this->foreachStmt($items, null, 'value', true, []),
            $this->foreachStmt($items, 'key', 'value', false, []),
            new WhileStatement(1, 1, $this->v('n'), [
                $this->foreachStmt($items, 'key', 'value', true, [
                    new BreakStatement(8, 9, 2, 'L1'),
                    new ContinueStatement(9, 9, 1, 'L0'),
                ]),
            ], 'L1'),
        ], <<<'PHP'
<?php
foreach ($items as $value) {
}

foreach ($items as &$value) {
}

foreach ($items as $key => $value) {
}

while ($n) {
    foreach ($items as $key => &$value) {
        break 2;
        continue;
    }
}
PHP);
    }

    public function testSwitchPreservesFallthroughSharedLabelsAndContinue(): void
    {
        $switch = new SwitchStatement(1, 1, $this->v('value'), [
            new SwitchCase(2, 2, [$this->int(1)], [
                $this->expr($this->assign('x', $this->int(1))),
            ], false, true),
            new SwitchCase(3, 3, [$this->int(2), $this->str('3')], [
                $this->expr($this->assign('x', $this->int(2))),
                new SwitchBreakStatement(6, 6, 1, 'S0'),
            ], false, false),
            new SwitchCase(7, 7, [$this->int(4)], [
                $this->expr($this->assign('x', $this->int(4))),
                new SwitchContinueStatement(9, 9, 2, 'S0'),
            ], true, false),
        ], [], 'S0');

        $this->assertSource([
            new WhileStatement(1, 1, $this->v('n'), [$switch], 'L0'),
        ], <<<'PHP'
<?php
while ($n) {
    switch ($value) {
        case 1:
            $x = 1;
        case 2:
        case '3':
            $x = 2;
            break;
        case 4:
        default:
            $x = 4;
            continue 2;
    }
}
PHP);
    }

    public function testTryCatchAndFinallyKeepClausesAndNesting(): void
    {
        $this->assertSource([
            new TryStatement(1, 1, [
                $this->expr($this->call('operation', [])),
            ], [
                new CatchClause(2, 2, ['RuntimeException', 'LogicException'], [2, 4], 'exception', [
                    $this->expr($this->call('recover', [])),
                ]),
                new CatchClause(5, 5, ['Error'], [5], null, [
                    $this->expr($this->call('report', [])),
                ]),
            ], 2, 10, new FinallyClause(8, 8, 9, 7, [
                $this->expr($this->call('cleanup', [])),
            ])),
        ], <<<'PHP'
<?php
try {
    operation();
} catch (RuntimeException|LogicException $exception) {
    recover();
} catch (Error) {
    report();
} finally {
    cleanup();
}
PHP);
        $this->assertSource([
            new TryStatement(1, 1, [
                $this->expr($this->call('operation', [])),
            ], [], null, 6, new FinallyClause(4, 4, 5, 3, [
                $this->expr($this->call('cleanup', [])),
            ])),
        ], <<<'PHP'
<?php
try {
    operation();
} finally {
    cleanup();
}
PHP);
        $inner = new TryStatement(3, 3, [
            $this->expr($this->call('inner', [])),
        ], [
            new CatchClause(4, 4, ['RuntimeException'], [4], 'exception', []),
        ], 4, 8);
        $this->assertSource([
            new TryStatement(1, 1, [$inner], [
                new CatchClause(6, 6, ['Exception'], [6], 'outer', [
                    new WhileStatement(7, 7, $this->v('n'), [
                        new BreakStatement(8, 8, 1, 'L0'),
                    ], 'L0'),
                ]),
            ], 6, 12),
        ], <<<'PHP'
<?php
try {
    try {
        inner();
    } catch (RuntimeException $exception) {
    }
} catch (Exception $outer) {
    while ($n) {
        break;
    }
}
PHP);
    }

    public function testEchoRendersAndOtherOpaqueStatementsFail(): void
    {
        $echo = new OpaqueStatement(3, 4, new Opaque(
            3,
            4,
            'ECHO',
            136,
            0,
            null,
            $this->use($this->v('text'), 'cv'),
            $this->use(null),
            $this->use(null),
            [],
            'opcode is not reconstructed as a statement',
        ));
        $this->assertSource([$echo], <<<'PHP'
<?php
echo $text;
PHP);

        $free = new OpaqueStatement(4, 9, new Opaque(
            4,
            9,
            'FREE',
            70,
            0,
            null,
            $this->use(null, 'tmp'),
            $this->use(null),
            $this->use(null),
            [],
            'opcode is not reconstructed as a statement',
        ));
        try {
            $source = (new PhpSourceGenerator())->generate(new StructuredStatements([$free], []));
            $this->fail('Generated source: ' . $source);
        } catch (SourceGenerationException $exception) {
            $this->assertSame('FREE', $exception->opcode);
            $this->assertSame(4, $exception->opline);
            $this->assertSame(9, $exception->sourceLine);
            $this->assertStringContainsString('opcode is not rendered as a statement', $exception->getMessage());
        }
    }

    public function testAJumpLevelBelowOneFails(): void
    {
        $this->expectException(SourceGenerationException::class);
        $this->expectExceptionMessage('jump level 0');

        (new PhpSourceGenerator())->generate(new StructuredStatements([
            new BreakStatement(1, 1, 0, 'L0'),
        ], []));
    }

    /**
     * @param list<Statement> $statements
     */
    private function assertSource(array $statements, string $body): void
    {
        $source = $body . "\n";
        $generated = (new PhpSourceGenerator())->generate(new StructuredStatements($statements, []));
        $this->assertSame($source, $generated);
        $this->assertStringNotContainsString("\r", $generated);
        $this->assertStringNotContainsString('?>', $generated);
        $file = tempnam(sys_get_temp_dir(), 'rephper-stmt-');
        $this->assertNotFalse($file);
        file_put_contents($file, $generated);
        $result = Process::run([PHP_BINARY, '-n', '-l', $file]);
        unlink($file);
        $this->assertSame(0, $result['exit'], $generated . "\n" . $result['stdout'] . $result['stderr']);
    }

    private function v(string $name): VariableRead
    {
        return new VariableRead(1, 1, $name, 0);
    }

    private function int(int $value): Literal
    {
        return $this->lit('int', $value);
    }

    private function str(string $value): Literal
    {
        return $this->lit('string', $value);
    }

    private function lit(string $type, mixed $value): Literal
    {
        return new Literal(1, 1, $type, $value);
    }

    private function bin(string $operator, Expression $left, Expression $right): Binary
    {
        return new Binary(1, 1, $operator, $left, $right);
    }

    private function assign(string $name, Expression $value): Assignment
    {
        return new Assignment(1, 1, $name, $value, null);
    }

    private function expr(Expression $expression): ExpressionStatement
    {
        return new ExpressionStatement(1, 1, $expression);
    }

    /**
     * @param list<Argument> $arguments
     */
    private function call(string $name, array $arguments): FunctionCall
    {
        return new FunctionCall(1, 1, $name, $arguments, 'INIT_FCALL', 0, count($arguments), 'DO_ICALL', 0, false);
    }

    private function arg(Expression $value): Argument
    {
        return new Argument(1, 1, 'SEND_VAL', $value, null, Argument::VALUE, false, 1);
    }

    /**
     * @param list<ConditionalBranch> $branches
     * @param list<Statement>|null $else
     */
    private function ifStmt(array $branches, ?array $else): IfStatement
    {
        return new IfStatement(1, 1, $branches, $else, $else === null ? null : 2, $else === null ? null : 2);
    }

    /**
     * @param list<Statement> $statements
     */
    private function branch(Expression $condition, array $statements): ConditionalBranch
    {
        return new ConditionalBranch(1, 1, $condition, $statements);
    }

    /**
     * @param list<Statement> $body
     */
    private function foreachStmt(Expression $iterable, ?string $key, string $value, bool $byReference, array $body): ForeachStatement
    {
        return new ForeachStatement(
            1,
            1,
            $iterable,
            $key === null ? null : new CompiledVariableTarget($key, 0, 1, 1),
            new CompiledVariableTarget($value, 1, 1, 1),
            $byReference,
            $body,
            'L0',
        );
    }

    private function use(?Expression $resolved, string $kind = 'unused'): OperandUse
    {
        return new OperandUse($kind, null, null, null, null, null, false, [], null, null, [], $resolved);
    }
}
