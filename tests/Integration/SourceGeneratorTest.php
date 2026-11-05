<?php

declare(strict_types=1);

namespace Rephper\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephper\Application\OpArrayPipeline;
use Rephper\CodeGen\PhpSourceGenerator;
use Rephper\CodeGen\SourceGenerationException;
use Rephper\Ir\Document;
use Rephper\Ir\OpArray;
use Rephper\Ir\Reader;
use Rephper\Tests\Support\Process;

final class SourceGeneratorTest extends TestCase
{
    #[DataProvider('bodies')]
    public function testGeneratedBodyLintsAndIsDeterministic(string $fixture, string $opArray): void
    {
        $source = $this->generate($fixture, $opArray);
        $again = $this->generate($fixture, $opArray);

        $this->assertSame($source, $again);
        $this->assertStringStartsWith("<?php\n", $source);
        $this->assertStringNotContainsString("?>", $source);
        $this->assertStringNotContainsString("\r", $source);
        $this->lint($source);
    }

    public function testScalarBodyKeepsLiteralValues(): void
    {
        $source = $this->generate('Common/Scalars.json', 'main');

        $this->assertStringContainsString('-0.0', $source);
        $this->assertStringContainsString('NAN', $source);
        $this->assertStringContainsString('INF', $source);
        $this->assertStringContainsString("['inner' => [1, 2]]", $source);
        $this->lint($source);
    }

    public function testAGenerationFailureDoesNotLeaveASourceFile(): void
    {
        $output = tempnam(sys_get_temp_dir(), 'rephper-partial-');
        $this->assertNotFalse($output);
        unlink($output);

        try {
            $source = $this->generate('Common/Expressions.json', 'callThenAdd');
            file_put_contents($output, $source);
            $this->fail('Generated source for an unsupported expression.');
        } catch (SourceGenerationException $exception) {
            $this->assertStringContainsString('STRLEN', $exception->getMessage());
            $this->assertSame(2, $exception->opline);
            $this->assertFileDoesNotExist($output);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bodies(): array
    {
        $cases = [
            'Common/Arithmetic.json' => ['calculate', 'main'],
            'Common/Scalars.json' => ['scale', 'main'],
            'Common/Expressions.json' => ['arithmetic', 'precedence', 'strings', 'calledSideEffect'],
            'Common/Calls.json' => ['namedArgs', 'unpacked', 'referenced', 'callsByReference'],
            'Common/Conditionals.json' => ['plainIf', 'ifElseIf', 'nestedIf'],
            'Common/WhileDo.json' => ['emptyWhile', 'emptyDo'],
            'Common/ForLoops.json' => ['check', 'step'],
            'Common/ForeachLoops.json' => ['simple', 'withKey', 'breakTwo'],
            'Common/Switches.json' => ['fallthrough', 'sharedLabels', 'continueOnly', 'defaultFirst'],
            'Common/MatchExpressions.json' => ['withDefault', 'withoutDefault', 'nested', 'defaultFirst'],
            'Common/TryCatch.json' => ['union', 'noVar', 'tryFinally', 'tryCatchFinally', 'nested'],
            'Common/Finally.json' => ['simple', 'breakTwo', 'nested'],
            'Common/Statements.json' => ['echoValue', 'returnExpression'],
            'PHP/8.0/MatchExpression.json' => ['classify'],
        ];
        $provider = [];
        foreach ($cases as $fixture => $names) {
            foreach ($names as $name) {
                $provider[$fixture . ' ' . $name] = [$fixture, $name];
            }
        }

        return $provider;
    }

    private function generate(string $fixture, string $name): string
    {
        $document = $this->document($fixture);
        $opArray = $name === 'main' ? $document->opArrayNamed(null) : $document->opArrayNamed($name);
        $this->assertInstanceOf(OpArray::class, $opArray);

        return (new PhpSourceGenerator())->generate((new OpArrayPipeline())->structure($document, $opArray));
    }

    private function document(string $fixture): Document
    {
        $json = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/Expected/PHP/8.4/' . $fixture);
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);

        return (new Reader())->readString($json);
    }

    private function lint(string $source): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rephper-generated-');
        $this->assertNotFalse($file);
        $php = $file . '.php';
        rename($file, $php);
        file_put_contents($php, $source);
        $result = Process::run(['php8.4', '-n', '-l', $php]);
        unlink($php);
        $this->assertSame(0, $result['exit'], $source . "\n" . $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('No syntax errors detected', $result['stdout']);
    }
}
