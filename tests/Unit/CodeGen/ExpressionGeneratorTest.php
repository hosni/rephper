<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\CodeGen;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephper\CodeGen\PhpSourceGenerator;
use Rephper\CodeGen\SourceGenerationException;
use Rephper\Expr\Argument;
use Rephper\Expr\Assignment;
use Rephper\Expr\Binary;
use Rephper\Expr\Expression;
use Rephper\Expr\FunctionCall;
use Rephper\Expr\Literal;
use Rephper\Expr\MatchArm;
use Rephper\Expr\MatchExpression;
use Rephper\Expr\Opaque;
use Rephper\Expr\OperandUse;
use Rephper\Expr\ReturnValue;
use Rephper\Expr\Unary;
use Rephper\Expr\Unresolved;
use Rephper\Expr\VariableRead;
use Rephper\Stmt\ExpressionStatement;
use Rephper\Stmt\ReturnStatement;
use Rephper\Stmt\StructuredStatements;
use Rephper\Tests\Support\Process;

final class ExpressionGeneratorTest extends TestCase
{
    #[DataProvider('precedence')]
    public function testPrecedenceAndAssociativity(Expression $expression, string $body): void
    {
        $this->assertRenders($expression, $body);
    }

    /**
     * @return array<string, array{Expression, string}>
     */
    public static function precedence(): array
    {
        $a = self::v('a');
        $b = self::v('b');
        $c = self::v('c');
        $d = self::v('d');

        return [
            'multiplication binds tighter than addition' => [self::bin('+', $a, self::bin('*', $b, $c)), '$a + $b * $c'],
            'addition on the left of multiplication is grouped' => [self::bin('*', self::bin('+', $a, $b), $c), '($a + $b) * $c'],
            'addition on the right of multiplication is grouped' => [self::bin('*', $a, self::bin('+', $b, $c)), '$a * ($b + $c)'],
            'subtraction associates left' => [self::bin('-', self::bin('-', $a, $b), $c), '$a - $b - $c'],
            'subtraction on the right is grouped' => [self::bin('-', $a, self::bin('-', $b, $c)), '$a - ($b - $c)'],
            'division associates left' => [self::bin('/', self::bin('/', $a, $b), $c), '$a / $b / $c'],
            'division on the right is grouped' => [self::bin('/', $a, self::bin('/', $b, $c)), '$a / ($b / $c)'],
            'multiplication and division associate left' => [self::bin('/', self::bin('*', $a, $b), $c), '$a * $b / $c'],
            'division on the right of multiplication is grouped' => [self::bin('*', $a, self::bin('/', $b, $c)), '$a * ($b / $c)'],
            'modulo associates left' => [self::bin('%', self::bin('%', $a, $b), $c), '$a % $b % $c'],
            'concatenation associates left' => [self::bin('.', self::bin('.', $a, $b), $c), '$a . $b . $c'],
            'concatenation on the right is grouped' => [self::bin('.', $a, self::bin('.', $b, $c)), '$a . ($b . $c)'],
            'concatenation and addition share a level and associate left' => [self::bin('+', self::bin('.', $a, $b), $c), '$a . $b + $c'],
            'addition and concatenation share a level and associate left' => [self::bin('.', self::bin('+', $a, $b), $c), '$a + $b . $c'],
            'concatenation on the right of addition is grouped' => [self::bin('+', $a, self::bin('.', $b, $c)), '$a + ($b . $c)'],
            'addition on the right of concatenation is grouped' => [self::bin('.', $a, self::bin('+', $b, $c)), '$a . ($b + $c)'],
            'a relational operator does not associate on the left' => [self::bin('<', self::bin('<', $a, $b), $c), '($a < $b) < $c'],
            'a relational operator does not associate on the right' => [self::bin('<', $a, self::bin('<', $b, $c)), '$a < ($b < $c)'],
            'a relational operator binds tighter than equality' => [self::bin('===', self::bin('<', $a, $b), $c), '$a < $b === $c'],
            'a relational operator on the right of equality binds tighter' => [self::bin('===', $a, self::bin('<', $b, $c)), '$a === $b < $c'],
            'equality does not associate on the left' => [self::bin('===', self::bin('===', $a, $b), $c), '($a === $b) === $c'],
            'equality does not associate on the right' => [self::bin('===', $a, self::bin('===', $b, $c)), '$a === ($b === $c)'],
            'loose equality does not associate' => [self::bin('!=', self::bin('==', $a, $b), $c), '($a == $b) != $c'],
            'not binds tighter than equality' => [self::bin('===', self::un('!', $a), $b), '!$a === $b'],
            'not of an equality is grouped' => [self::un('!', self::bin('===', $a, $b)), '!($a === $b)'],
            'not of a sum is grouped' => [self::un('!', self::bin('+', $a, $b)), '!($a + $b)'],
            'not associates to the right' => [self::un('!', self::un('!', $a)), '!!$a'],
            'bitwise not of not needs no extra parentheses' => [self::un('~', self::un('!', $a)), '~!$a'],
            'not of bitwise not needs no extra parentheses' => [self::un('!', self::un('~', $a)), '!~$a'],
            'a cast of a variable needs no extra parentheses' => [self::un('bool', $a), '(bool)$a'],
            'a cast of a sum is grouped' => [self::un('bool', self::bin('+', $a, $b)), '(bool)($a + $b)'],
            'a cast of not needs no extra parentheses' => [self::un('bool', self::un('!', $a)), '(bool)!$a'],
            'not of a cast needs no extra parentheses' => [self::un('!', self::un('bool', $a)), '!(bool)$a'],
            'a cast of bitwise not needs no extra parentheses' => [self::un('bool', self::un('~', $a)), '(bool)~$a'],
            'bitwise not of a cast needs no extra parentheses' => [self::un('~', self::un('bool', $a)), '~(bool)$a'],
            'casts associate to the right' => [self::un('bool', self::un('bool', $a)), '(bool)(bool)$a'],
            'not of a call needs no extra parentheses' => [self::un('!', self::call('strtolower', [self::arg($a)])), '!strtolower($a)'],
            'a cast of a call needs no extra parentheses' => [self::un('bool', self::call('strtolower', [self::arg($a)])), '(bool)strtolower($a)'],
            'a call binds tighter than addition' => [self::bin('+', self::call('strtolower', [self::arg($a)]), $b), 'strtolower($a) + $b'],
            'assignment of a sum needs no extra parentheses' => [self::assign('a', self::bin('+', $b, $c)), '$a = $b + $c'],
            'assignment associates to the right' => [self::assign('a', self::assign('b', $c)), '$a = $b = $c'],
            'assignment on the left of addition is grouped' => [self::bin('+', self::assign('a', $b), $c), '($a = $b) + $c'],
            'assignment of an equality needs no extra parentheses' => [self::assign('a', self::bin('===', $b, $c)), '$a = $b === $c'],
            'compound assignment uses the operator' => [self::assign('a', self::int(1), '+'), '$a += 1'],
            'compound assignment associates to the right' => [self::assign('a', self::assign('b', self::int(1), '+'), '+'), '$a += $b += 1'],
            'compound assignment on the left of addition is grouped' => [self::bin('+', self::assign('a', $b, '+'), $c), '($a += $b) + $c'],
            'compiled unary minus stays a multiplication' => [self::bin('*', $a, self::int(-1)), '$a * -1'],
            'compiled unary plus stays a multiplication' => [self::bin('*', $a, self::int(1)), '$a * 1'],
            'a sum of a match and a variable is grouped inside multiplication' => [
                self::bin('*', self::bin('+', self::match($a, [
                    self::arm([self::int(1)], self::match($b, [
                        self::arm([self::int(2)], self::str('x')),
                        self::arm([], self::str('y'), true),
                    ])),
                    self::arm([], self::str('z'), true),
                ]), $c), $d),
                <<<'PHP'
(match ($a) {
    1 => match ($b) {
        2 => 'x',
        default => 'y',
    },
    default => 'z',
} + $c) * $d
PHP,
            ],
        ];
    }

    public function testLiteralsKeepTheirPhpTypes(): void
    {
        $this->assertValue(self::lit('null', null), null);
        $this->assertValue(self::lit('bool', true), true);
        $this->assertValue(self::lit('bool', false), false);
        $this->assertValue(self::lit('int', 0), 0);
        $this->assertValue(self::lit('int', 10), 10);
        $this->assertValue(self::lit('int', -4), -4);
        $this->assertValue(self::lit('int', '9223372036854775807'), PHP_INT_MAX);
        $this->assertValue(self::lit('int', '-9223372036854775808'), PHP_INT_MIN);
        $this->assertValue(self::lit('float', 0.5), 0.5);
        $this->assertValue(self::lit('float', 0.1), 0.1);
        $this->assertValue(self::lit('float', 2), 2.0);
        $this->assertValue(self::lit('float', 'Infinity'), INF);
        $this->assertValue(self::lit('float', '-Infinity'), -INF);
        $this->assertNotANumber(self::lit('float', 'NaN'));
        $this->assertNotANumber(self::lit('float', NAN));
        $this->assertNegativeZero(self::lit('float', -0.0));
        $this->assertNegativeZero(self::lit('float', '-0'));
        $this->assertValue(self::lit('string', ''), '');
        $this->assertValue(self::lit('string', 'John'), 'John');
        $this->assertValue(self::lit('string', "a'b\\c"), "a'b\\c");
        $this->assertValue(self::lit('string', 'hello $name'), 'hello $name');
        $this->assertValue(self::lit('string', "a\nb\t\"c"), "a\nb\t\"c");
        $this->assertValue(self::lit('string', "a\0b"), "a\0b");
        $this->assertValue(self::lit('string', "\x7F"), "\x7F");
        $this->assertValue(self::lit('string', 'hello 世界'), 'hello 世界');
        $this->assertValue(self::lit('string', "\xFF\xFE"), "\xFF\xFE");
        $this->assertValue(self::lit('string', "\u{00FF}", null, true), "\xFF");
        $this->assertSame('"\xff"', $this->body(self::lit('string', "\u{00FF}", null, true)));
        $this->assertValue(self::lit('string', 'ab', null, true), 'ab');
        $this->assertSame(
            '"\x61\x62"',
            $this->body(self::lit('string', 'ab', null, true)),
        );
        $this->assertSame(
            '"\xff\xfe"',
            $this->body(self::lit('string', "\xFF\xFE")),
        );
    }

    public function testArraysKeepKeyTypesAndOrder(): void
    {
        $this->assertValue(self::arr([]), []);
        $this->assertValue(self::arr([
            self::pair(self::int(0), self::int(1)),
            self::pair(self::int(1), self::int(2)),
            self::pair(self::int(2), self::int(3)),
        ]), [1, 2, 3]);
        $this->assertSame('[1, 2, 3]', $this->body(self::arr([
            self::pair(self::int(0), self::int(1)),
            self::pair(self::int(1), self::int(2)),
            self::pair(self::int(2), self::int(3)),
        ])));
        $this->assertValue(self::arr([
            self::pair(self::int(1), self::str('a')),
            self::pair(self::int(3), self::str('b')),
        ]), [1 => 'a', 3 => 'b']);
        $this->assertValue(self::arr([
            self::pair(self::str('0'), self::int(1)),
        ]), ['0' => 1]);
        $this->assertSame("['0' => 1]", $this->body(self::arr([
            self::pair(self::str('0'), self::int(1)),
        ])));
        $this->assertValue(self::arr([
            self::pair(self::int(1), self::str('a')),
            self::pair(self::int(0), self::str('b')),
        ]), [1 => 'a', 0 => 'b']);
        $this->assertSame("[1 => 'a', 0 => 'b']", $this->body(self::arr([
            self::pair(self::int(1), self::str('a')),
            self::pair(self::int(0), self::str('b')),
        ])));
        $nested = self::arr([
            self::pair(self::int(0), self::lit('null', null)),
            self::pair(self::int(1), self::lit('bool', true)),
            self::pair(self::int(2), self::lit('bool', false)),
            self::pair(self::int(3), self::arr([
                self::pair(self::str('inner'), self::arr([
                    self::pair(self::int(0), self::int(1)),
                    self::pair(self::int(1), self::int(2)),
                ])),
            ])),
        ]);
        $this->assertValue($nested, [null, true, false, ['inner' => [1, 2]]]);
        $this->assertSame('[null, true, false, [\'inner\' => [1, 2]]]', $this->body($nested));
    }

    public function testDirectCallsNamedArgumentsAndUnpacking(): void
    {
        $this->assertRenders(self::call('greet', []), 'greet()');
        $this->assertRenders(
            self::call('calculate', [self::arg(self::v('price')), self::arg(self::v('quantity'))]),
            'calculate($price, $quantity)',
        );
        $this->assertRenders(
            self::call('calculate', [self::arg(self::bin('+', self::v('a'), self::v('b'))), self::arg(self::v('c'))]),
            'calculate($a + $b, $c)',
        );
        $this->assertRenders(
            self::call('calculate', [self::arg(self::call('calculate', [self::arg(self::v('a'))])), self::arg(self::v('b'))]),
            'calculate(calculate($a), $b)',
        );
        $this->assertRenders(
            self::call('unknown_func', [
                self::arg(self::v('quantity'), 'quantity'),
                self::arg(self::v('price'), 'price'),
            ]),
            'unknown_func(quantity: $quantity, price: $price)',
        );
        $this->assertRenders(
            self::call('unknown_func', [self::arg(self::int(1)), self::arg(self::v('label'), 'label')]),
            'unknown_func(1, label: $label)',
        );
        $this->assertRenders(
            self::call('calculate', [self::arg(self::v('args'), null, Argument::VALUE, true)]),
            'calculate(...$args)',
        );
        $this->assertRenders(
            self::call('unknown_func', [
                self::arg(self::v('price')),
                self::arg(self::v('args'), null, Argument::VALUE, true),
            ]),
            'unknown_func($price, ...$args)',
        );
        $this->assertRenders(
            self::call('Foo\\Bar', [self::arg(self::v('n'))]),
            'Foo\\Bar($n)',
        );
        $reference = self::call('byReference', [self::arg(self::v('n'), null, Argument::REFERENCE)]);
        $this->assertRenders($reference, 'byReference($n)');
        $this->assertStringNotContainsString('&', $this->body($reference));
    }

    public function testMatchArmsKeepOrderConditionsAndDefaultPlacement(): void
    {
        $match = self::match(self::v('value'), [
            self::arm([self::int(1), self::int(2)], self::str('small')),
            self::arm([self::int(3)], self::str('medium')),
            self::arm([], self::str('large'), true),
        ]);
        $this->assertRenders(self::assign('result', $match), <<<'PHP'
$result = match ($value) {
    1, 2 => 'small',
    3 => 'medium',
    default => 'large',
}
PHP);

        $withoutDefault = self::match(self::v('value'), [
            self::arm([self::int(1)], self::str('a')),
            self::arm([self::str('1')], self::str('b')),
        ], 40);
        $body = $this->body($withoutDefault);
        $this->assertSame(<<<'PHP'
match ($value) {
    1 => 'a',
    '1' => 'b',
}
PHP, $body);
        $this->assertStringNotContainsString('default', $body);

        $defaultFirst = self::match(self::v('value'), [
            self::arm([], self::str('large'), true),
            self::arm([self::int(1)], self::str('small')),
        ]);
        $this->assertRenders($defaultFirst, <<<'PHP'
match ($value) {
    default => 'large',
    1 => 'small',
}
PHP);
    }

    public function testAnUnsupportedExpressionFailsWithoutReturningSource(): void
    {
        $strlen = new Opaque(
            2,
            8,
            'STRLEN',
            73,
            0,
            null,
            self::use(self::v('text'), 'cv'),
            self::use(null),
            self::use(null),
            [],
            'opcode is not reconstructed as an expression',
        );

        try {
            $source = $this->source(self::bin('+', $strlen, self::v('n')));
            $this->fail('Generated source: ' . $source);
        } catch (SourceGenerationException $exception) {
            $this->assertSame('STRLEN', $exception->opcode);
            $this->assertSame(2, $exception->opline);
            $this->assertSame(8, $exception->sourceLine);
            $this->assertStringContainsString('opline 2', $exception->getMessage());
            $this->assertStringContainsString('line 8', $exception->getMessage());
        }
    }

    public function testAnUnresolvedExpressionFailsWithoutReturningSource(): void
    {
        $unresolved = new Unresolved(5, 12, 'tmp', 2, 'tmp 2 is not defined in this block');

        try {
            $source = $this->source(self::assign('result', $unresolved));
            $this->fail('Generated source: ' . $source);
        } catch (SourceGenerationException $exception) {
            $this->assertNull($exception->opcode);
            $this->assertSame(5, $exception->opline);
            $this->assertSame(12, $exception->sourceLine);
            $this->assertStringContainsString('unresolved tmp 2', $exception->getMessage());
            $this->assertStringContainsString('tmp 2 is not defined in this block', $exception->getMessage());
        }
    }

    public function testAnArrayWithoutElementsAndAConstantAstFail(): void
    {
        $array = self::lit('array', null, null);
        $ast = self::lit('constant_ast', null);

        try {
            $this->source($array);
            $this->fail('An array without elements was rendered.');
        } catch (SourceGenerationException $exception) {
            $this->assertSame(1, $exception->opline);
            $this->assertStringContainsString('array elements are not available', $exception->getMessage());
        }

        try {
            $this->source($ast);
            $this->fail('A constant AST was rendered.');
        } catch (SourceGenerationException $exception) {
            $this->assertStringContainsString('constant AST literals cannot be rendered', $exception->getMessage());
        }
    }

    public function testAReturnValueInsideAnExpressionFails(): void
    {
        $this->expectException(SourceGenerationException::class);
        $this->expectExceptionMessage('return is a statement');

        $this->source(new ReturnValue(3, 4, self::int(1)));
    }

    private function assertRenders(Expression $expression, string $body): void
    {
        $source = "<?php\n" . $body . ";\n";
        $this->assertSame($source, $this->source($expression));
        $this->assertLints($source);
    }

    private function assertValue(Expression $expression, mixed $expected): void
    {
        $this->assertSame($expected, $this->evaluate($expression));
    }

    private function assertNotANumber(Expression $expression): void
    {
        $value = $this->evaluate($expression);
        $this->assertIsFloat($value);
        $this->assertTrue(is_nan($value));
    }

    private function assertNegativeZero(Expression $expression): void
    {
        $value = $this->evaluate($expression);
        $this->assertIsFloat($value);
        $this->assertSame(pack('d', -0.0), pack('d', $value));
    }

    private function evaluate(Expression $expression): mixed
    {
        $source = (new PhpSourceGenerator())->generate(new StructuredStatements([
            new ReturnStatement(1, 1, $expression),
        ], []));
        $this->assertLints($source);
        $file = tempnam(sys_get_temp_dir(), 'rephper-expr-');
        $this->assertNotFalse($file);
        file_put_contents($file, $source);
        try {
            return include $file;
        } finally {
            unlink($file);
        }
    }

    private function source(Expression $expression): string
    {
        return (new PhpSourceGenerator())->generate(new StructuredStatements([
            new ExpressionStatement(1, 1, $expression),
        ], []));
    }

    private function body(Expression $expression): string
    {
        $source = $this->source($expression);
        $this->assertStringStartsWith("<?php\n", $source);
        $this->assertStringEndsWith(";\n", $source);

        return substr($source, 6, -2);
    }

    private function assertLints(string $source): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rephper-lint-');
        $this->assertNotFalse($file);
        file_put_contents($file, $source);
        $result = Process::run([PHP_BINARY, '-n', '-l', $file]);
        unlink($file);
        $this->assertSame(0, $result['exit'], $source . "\n" . $result['stdout'] . $result['stderr']);
    }

    private static function v(string $name): VariableRead
    {
        return new VariableRead(1, 1, $name, 0);
    }

    private static function int(int $value): Literal
    {
        return self::lit('int', $value);
    }

    private static function str(string $value): Literal
    {
        return self::lit('string', $value);
    }

    private static function lit(string $type, mixed $value, ?array $elements = null, bool $binary = false): Literal
    {
        return new Literal(1, 1, $type, $value, $elements, $binary);
    }

    /**
     * @param list<array{key: Literal, value: Literal}> $elements
     */
    private static function arr(array $elements): Literal
    {
        return self::lit('array', null, $elements);
    }

    /**
     * @return array{key: Literal, value: Literal}
     */
    private static function pair(Literal $key, Literal $value): array
    {
        return ['key' => $key, 'value' => $value];
    }

    private static function bin(string $operator, Expression $left, Expression $right): Binary
    {
        return new Binary(1, 1, $operator, $left, $right);
    }

    private static function un(string $operator, Expression $operand): Unary
    {
        return new Unary(1, 1, $operator, $operand);
    }

    private static function assign(string $name, Expression $value, ?string $operator = null): Assignment
    {
        return new Assignment(1, 1, $name, $value, $operator);
    }

    /**
     * @param list<Argument> $arguments
     */
    private static function call(string $name, array $arguments): FunctionCall
    {
        return new FunctionCall(1, 1, $name, $arguments, 'INIT_FCALL', 0, count($arguments), 'DO_ICALL', 0, false);
    }

    private static function arg(
        Expression $value,
        ?string $name = null,
        string $mode = Argument::VALUE,
        bool $unpack = false,
    ): Argument {
        return new Argument(1, 1, $unpack ? 'SEND_UNPACK' : 'SEND_VAL', $value, $name, $mode, $unpack, $unpack ? null : 1);
    }

    /**
     * @param list<MatchArm> $arms
     */
    private static function match(Expression $subject, array $arms, ?int $unhandled = null): MatchExpression
    {
        return new MatchExpression(1, 1, $subject, $arms, $unhandled, 20);
    }

    /**
     * @param list<Literal> $conditions
     */
    private static function arm(array $conditions, Expression $result, bool $default = false): MatchArm
    {
        return new MatchArm(1, 1, $conditions, $result, $default);
    }

    private static function use(?Expression $resolved, string $kind = 'unused'): OperandUse
    {
        return new OperandUse($kind, null, null, null, null, null, false, [], null, null, [], $resolved);
    }
}
