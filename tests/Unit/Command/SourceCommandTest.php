<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Rephper\Command\SourceCommand;
use Rephper\Tests\Support\Process;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

final class SourceCommandTest extends TestCase
{
    public function testSourcePrintsOneOpArrayBody(): void
    {
        $file = $this->ir('Common/Arithmetic.json');
        $tester = $this->tester();
        $status = $tester->execute([
            'file' => $file,
            '--op-array' => 'calculate',
        ]);

        $this->assertSame(0, $status, $tester->getDisplay());
        $this->assertSame("<?php\nreturn \$a + \$b;\n", $tester->getDisplay());
        $again = $this->tester();
        $this->assertSame(0, $again->execute(['file' => $file, '--op-array' => '0']));
        $this->assertSame($tester->getDisplay(), $again->getDisplay());
        unlink($file);
    }

    public function testOutputFileReceivesTheBodyAndStdoutStaysEmpty(): void
    {
        $file = $this->ir('Common/Arithmetic.json');
        $output = tempnam(sys_get_temp_dir(), 'rephper-out-');
        $this->assertNotFalse($output);
        unlink($output);
        $tester = $this->tester();
        $status = $tester->execute([
            'file' => $file,
            '--op-array' => '1',
            '--output' => $output,
        ]);

        $this->assertSame(0, $status, $tester->getDisplay());
        $this->assertSame('', $tester->getDisplay());
        $this->assertSame("<?php\n\$result = calculate(10, 20);\n", (string) file_get_contents($output));
        unlink($file);
        unlink($output);
    }

    public function testHelpSaysTheExtensionIsNotLoaded(): void
    {
        $application = new Application('rephper');
        $application->setAutoExit(false);
        $application->add(new SourceCommand());
        $tester = new ApplicationTester($application);

        $status = $tester->run(['command' => 'source', '--help' => true]);

        $this->assertSame(0, $status);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('does not load the Zend extension', $display);
        $this->assertStringContainsString('does not execute the original PHP file', $display);
        $this->assertStringContainsString('--op-array', $display);
        $this->assertStringContainsString('--output', $display);
    }

    public function testAMissingOpArrayOptionFails(): void
    {
        $file = $this->ir('Common/Arithmetic.json');
        $tester = $this->tester();
        $status = $tester->execute(['file' => $file], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('--op-array', $tester->getErrorOutput());
        unlink($file);
    }

    public function testAnUnknownIdFails(): void
    {
        $file = $this->ir('Common/Arithmetic.json');
        $tester = $this->tester();
        $status = $tester->execute([
            'file' => $file,
            '--op-array' => '99',
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('No op array with id 99.', $tester->getErrorOutput());
        unlink($file);
    }

    public function testGenerationFailureDoesNotWriteAPartialFile(): void
    {
        $file = $this->ir('Common/Expressions.json');
        $output = tempnam(sys_get_temp_dir(), 'rephper-partial-');
        $this->assertNotFalse($output);
        file_put_contents($output, 'original');
        $missing = $output . '-missing';
        $tester = $this->tester();
        $status = $tester->execute([
            'file' => $file,
            '--op-array' => 'callThenAdd',
            '--output' => $missing,
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('STRLEN', $tester->getErrorOutput());
        $this->assertFileDoesNotExist($missing);
        $this->assertSame('', $tester->getDisplay());

        $status = $tester->execute([
            'file' => $file,
            '--op-array' => 'callThenAdd',
            '--output' => $output,
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $status);
        $this->assertSame('original', (string) file_get_contents($output));
        unlink($file);
        unlink($output);
    }

    public function testExecutablePrintsTheCalculatedBody(): void
    {
        $file = $this->ir('Common/Arithmetic.json');
        $result = Process::run([
            PHP_BINARY,
            '-n',
            dirname(__DIR__, 3) . '/bin/rephper',
            'source',
            $file,
            '--op-array=calculate',
        ]);

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame("<?php\nreturn \$a + \$b;\n", $result['stdout']);
        unlink($file);
    }

    public function testTheCommandListIncludesSource(): void
    {
        $result = Process::run([
            PHP_BINARY,
            '-n',
            dirname(__DIR__, 3) . '/bin/rephper',
        ]);

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertStringContainsString('source', $result['stdout']);
        $this->assertStringContainsString('graph', $result['stdout']);
    }

    private function tester(): CommandTester
    {
        $command = (new Application('rephper'))->add(new SourceCommand());
        $this->assertNotNull($command);

        return new CommandTester($command);
    }

    private function ir(string $relative): string
    {
        $file = tempnam(sys_get_temp_dir(), 'rephper-src-ir-');
        $this->assertNotFalse($file);
        $json = (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/' . $relative);
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);
        file_put_contents($file, $json);

        return $file;
    }
}
