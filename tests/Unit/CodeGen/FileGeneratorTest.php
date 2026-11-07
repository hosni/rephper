<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\CodeGen;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephper\Application\PhpFileGenerator;
use Rephper\CodeGen\SourceGenerationException;
use Rephper\Ir\Document;
use Rephper\Ir\Reader;
use Rephper\Tests\Support\Process;

final class FileGeneratorTest extends TestCase
{
    public function testCalculatrorIsTheFunctionDeclaration(): void
    {
        $source = $this->generate('Common/Calculatror.json');

        $this->assertSame(<<<'PHP'
<?php

function calculatror(int $a, int $b): int
{
    return $a + $b;
}

PHP, $source);
        $this->assertSame($source, $this->generate('Common/Calculatror.json'));
        $this->assertSame(1, substr_count($source, '<?php'));
        $this->assertSame(1, substr_count($source, 'function calculatror'));
        $this->assertStringNotContainsString('?>', $source);
    }

    public function testCalculatrorReturnsTheSum(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rephper-calc-');
        $this->assertNotFalse($file);
        $php = $file . '.php';
        rename($file, $php);
        file_put_contents($php, $this->generate('Common/Calculatror.json'));
        try {
            $result = Process::run([
                'php8.4',
                '-n',
                '-r',
                'require ' . var_export($php, true) . '; echo calculatror(1, 2), " ", calculatror(10, 20), " ", calculatror(-1, 5);',
            ]);
        } finally {
            unlink($php);
        }

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame('3 30 4', $result['stdout']);
    }

    public function testStrictTypesIsDeclaredAndEnforced(): void
    {
        $source = $this->generate('Common/FunctionStrict.json');

        $this->assertSame(<<<'PHP'
<?php

declare(strict_types=1);

function calculatror(int $a, int $b): int
{
    return $a + $b;
}

PHP, $source);
        $file = tempnam(sys_get_temp_dir(), 'rephper-strict-');
        $this->assertNotFalse($file);
        $php = $file . '.php';
        rename($file, $php);
        file_put_contents($php, $source . "try { calculatror('1', 2); echo 'coerced'; } catch (TypeError) { echo 'strict'; }\n");
        $result = Process::run(['php8.4', '-n', $php]);
        unlink($php);

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame('strict', $result['stdout']);

        $weak = tempnam(sys_get_temp_dir(), 'rephper-weak-');
        $this->assertNotFalse($weak);
        $weakPhp = $weak . '.php';
        rename($weak, $weakPhp);
        file_put_contents($weakPhp, $this->generate('Common/Calculatror.json') . "echo calculatror('1', 2);\n");
        $coerced = Process::run(['php8.4', '-n', $weakPhp]);
        unlink($weakPhp);
        $this->assertSame(0, $coerced['exit'], $coerced['stderr']);
        $this->assertSame('3', $coerced['stdout']);
    }

    public function testAnExistingFunctionAndItsCallSiteStayTogether(): void
    {
        $this->assertSame(<<<'PHP'
<?php

function calculate(int $a, int $b): int
{
    return $a + $b;
}

$result = calculate(10, 20);

PHP, $this->generate('Common/Arithmetic.json'));
        $this->assertSame(<<<'PHP'
<?php

function build(string $name, int $times = 2, string ...$parts): string
{
    return $name;
}

PHP, $this->generate('Common/Parameters.json'));
        $this->assertSame(<<<'PHP'
<?php

function greet(string $name): string
{
    return 'Hello, ' . $name;
}

$message = greet('Rephper');

PHP, $this->generate('Common/Functions.json'));
        $this->assertSame("<?php\n\nreturn null;\n", $this->generate('Common/Minimal.json'));
    }

    public function testSignatureMetadataIsRenderedFromTheIr(): void
    {
        $source = $this->generate('Common/FunctionSignatures.json');
        $again = $this->generate('Common/FunctionSignatures.json');

        $this->assertSame($source, $again);
        $this->assertSame(1, substr_count($source, '<?php'));
        $this->assertStringNotContainsString('?>', $source);
        $this->assertStringNotContainsString("\r", $source);
        foreach ([
            'function calculatror(int $a, int $b): int',
            'function plain($a, $b)',
            'function nullable(?int $a, ?int $b): ?string',
            'function unions(string|int $value): string|int|null',
            'function intersections(Traversable&Countable $value): Traversable&Countable',
            'function dnf((Traversable&Countable)|int $value): (Traversable&Countable)|null',
            'function both((Traversable&Countable)|(Iterator&Stringable)|int|null $value): void',
            'function falseType(false $value): false',
            'function truthy(true $value): true',
            'function mixedish(mixed $value): mixed',
            'function voidish(): void',
            'function iterate(Traversable|array $values): Traversable|array',
            'function neverReturn(Throwable $error): never',
            'function refs(int &$a, string &...$rest): void',
            'function &refReturn(array &$items): array',
            'function collect(int $first, string ...$parts): int',
            'int $max = PHP_INT_MAX',
            'string $eol = PHP_EOL',
            'int $next = PHP_INT_MAX + 1',
            "mixed \$fallback = PHP_EOL ?? 'x'",
            '?int $maybe = null',
            "#[Deprecated(message: 'old', since: '1.0')]\nfunction marked(#[SensitiveParameter] string \$secret = 'x'): void",
            'function nestedFlow(int $n): int',
            'function calls(int $a, int $b): int',
            'return calculatror($a, $b);',
            'return match ($n) {',
            'switch ($value) {',
            'try {',
            'catch (RuntimeException $exception) {',
            'finally {',
            '$result = calculatror(1, 2);',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source, $needle);
            $this->assertSame(1, substr_count($source, $needle), $needle);
        }

        $position = -1;
        foreach ([
            'function calculatror',
            'function plain',
            'function nullable',
            'function unions',
            'function intersections',
            'function dnf',
            'function both',
            'function falseType',
            'function truthy',
            'function mixedish',
            'function voidish',
            'function iterate',
            'function neverReturn',
            'function refs',
            'function &refReturn',
            'function collect',
            'function defaults',
            'function marked',
            'function nestedFlow',
            'function calls',
            'function matched',
            'function switched',
            'function handled',
            '$result = calculatror(1, 2);',
        ] as $needle) {
            $found = strpos($source, $needle);
            $this->assertNotFalse($found, $needle);
            $this->assertGreaterThan($position, $found, $needle);
            $position = $found;
        }
    }

    #[DataProvider('rejectedFiles')]
    public function testUnsupportedDeclarationsFailTheFile(string $fixture, string $message): void
    {
        try {
            $source = $this->generate($fixture);
            $this->fail('Generated a file: ' . $source);
        } catch (SourceGenerationException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedFiles(): array
    {
        return [
            'class' => ['Common/Classes.json', 'class Calculator is not supported'],
            'closure' => ['Common/Closures.json', 'closure '],
            'arrow' => ['Common/ArrowFunctions.json', 'closure '],
            'generator' => ['Common/Generators.json', 'generator function sequence is not supported'],
            'namespace function' => ['Common/FunctionNamespace.json', 'namespace App is not supported'],
            'namespace statement' => ['Common/NamespaceStatement.json', 'namespace App is not supported'],
            'conditional' => ['Common/FunctionConditional.json', 'function hidden is nested or conditional'],
            'nested' => ['Common/FunctionNested.json', 'function inner is nested or conditional'],
            'const' => ['Common/FileConstant.json', 'const declaration in the main script is not supported'],
            'unreconstructed body' => ['Common/Expressions.json', 'Cannot generate PHP for function'],
        ];
    }

    public function testIncompleteSignatureMetadataFails(): void
    {
        $missingDisplay = $this->mutate('Common/Functions.json', static function (array &$data): void {
            $data['op_arrays'][0]['return_type']['display'] = null;
        });
        $missingReturn = $this->mutate('Common/Functions.json', static function (array &$data): void {
            $data['op_arrays'][0]['return_type'] = null;
        });
        $preferReference = $this->mutate('Common/Parameters.json', static function (array &$data): void {
            $data['op_arrays'][0]['arguments'][0]['send'] = 'prefer_reference';
        });
        $unsupportedDefault = $this->mutate('Common/Parameters.json', static function (array &$data): void {
            $data['op_arrays'][0]['literals'][0] = ['type' => 'unsupported', 'zend_type' => 8];
        });
        $missingTarget = $this->mutate('Common/Calculatror.json', static function (array &$data): void {
            $data['op_arrays'][0]['attributes'] = [[
                'name' => 'Deprecated',
                'line' => 3,
                'arguments' => [],
            ]];
        });
        $duplicate = $this->mutate('Common/Calculatror.json', static function (array &$data): void {
            $copy = $data['op_arrays'][0];
            $copy['id'] = 2;
            array_splice($data['op_arrays'], 1, 0, [$copy]);
        });
        $disagreement = $this->mutate('Common/FunctionStrict.json', static function (array &$data): void {
            $data['op_arrays'][0]['flags'] = array_values(array_filter(
                $data['op_arrays'][0]['flags'],
                static fn (string $flag): bool => $flag !== 'strict_types',
            ));
        });

        $this->assertGenerationFails($missingDisplay, 'display is missing');
        $this->assertGenerationFails($missingReturn, 'return type metadata is incomplete');
        $this->assertGenerationFails($preferReference, 'prefer_reference');
        $this->assertGenerationFails($unsupportedDefault, 'default value of $times cannot be rendered');
        $this->assertGenerationFails($missingTarget, 'attribute target is not in the IR');
        $this->assertGenerationFails($duplicate, 'function calculatror is declared more than once');
        $this->assertGenerationFails($disagreement, 'strict_types is not the same');
    }

    private function assertGenerationFails(Document $document, string $message): void
    {
        try {
            $source = (new PhpFileGenerator())->generate($document);
            $this->fail('Generated a file: ' . $source);
        } catch (SourceGenerationException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $this->assertStringNotContainsString('<?php', $exception->getMessage());
        }
    }

    private function generate(string $fixture): string
    {
        return (new PhpFileGenerator())->generate($this->document($fixture));
    }

    private function document(string $fixture): Document
    {
        return (new Reader())->readFile(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/' . $fixture);
    }

    /**
     * @param callable(array<string, mixed>): void $change
     */
    private function mutate(string $fixture, callable $change): Document
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/' . $fixture), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        $change($data);

        return (new Reader())->readString(json_encode($data, JSON_THROW_ON_ERROR));
    }
}
