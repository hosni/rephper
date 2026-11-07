<?php

declare(strict_types=1);

namespace Rephper\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephper\Application\PhpFileGenerator;
use Rephper\CodeGen\SourceGenerationException;
use Rephper\Ir\Document;
use Rephper\Ir\Reader;
use Rephper\Tests\Support\Process;

final class FunctionFileTest extends TestCase
{
    #[DataProvider('files')]
    public function testGeneratedFileLintsAndIsDeterministic(string $fixture): void
    {
        $source = $this->generate($fixture);
        $again = $this->generate($fixture);

        $this->assertSame($source, $again);
        $this->assertSame(1, substr_count($source, '<?php'));
        $this->assertStringStartsWith("<?php\n\n", $source);
        $this->assertStringNotContainsString('?>', $source);
        $this->assertStringNotContainsString("\r", $source);
        $this->lint($source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function files(): array
    {
        return [
            'calculatror' => ['Common/Calculatror.json'],
            'signatures' => ['Common/FunctionSignatures.json'],
            'strict' => ['Common/FunctionStrict.json'],
            'arithmetic' => ['Common/Arithmetic.json'],
            'parameters' => ['Common/Parameters.json'],
            'functions' => ['Common/Functions.json'],
            'minimal' => ['Common/Minimal.json'],
        ];
    }

    public function testCalculatrorReturnsTheSum(): void
    {
        $source = $this->generate('Common/Calculatror.json');
        $file = tempnam(sys_get_temp_dir(), 'rephper-calc-');
        $this->assertNotFalse($file);
        $php = $file . '.php';
        rename($file, $php);
        file_put_contents($php, $source);
        $result = Process::run([
            'php8.4',
            '-n',
            '-r',
            'require ' . var_export($php, true) . '; echo calculatror(1, 2);',
        ]);
        unlink($php);

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame('3', $result['stdout']);
        $this->lint($source);
    }

    public function testAnUnsupportedDeclarationDoesNotLeaveASourceFile(): void
    {
        $output = tempnam(sys_get_temp_dir(), 'rephper-file-');
        $this->assertNotFalse($output);
        unlink($output);

        try {
            $source = $this->generate('Common/Classes.json');
            file_put_contents($output, $source);
            $this->fail('Generated a file that omitted a class.');
        } catch (SourceGenerationException $exception) {
            $this->assertStringContainsString('class Calculator is not supported', $exception->getMessage());
            $this->assertFileDoesNotExist($output);
        }
    }

    private function generate(string $fixture): string
    {
        return (new PhpFileGenerator())->generate($this->document($fixture));
    }

    private function document(string $fixture): Document
    {
        return (new Reader())->readFile(dirname(__DIR__, 2) . '/tests/Expected/PHP/8.4/' . $fixture);
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
