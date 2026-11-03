<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Rephper\Command\GraphCommand;
use Rephper\Tests\Support\Process;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

final class GraphCommandTest extends TestCase
{
    public function testGraphPrintsTheRequestedOpArray(): void
    {
        $file = $this->classifyFile();
        $tester = $this->tester();
        $status = $tester->execute([
            'file' => $file,
            '--op-array' => 'classify',
        ]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('OpArray #0 classify (function)', $tester->getDisplay());
        $this->assertStringContainsString('2: JMPZ', $tester->getDisplay());
        $this->assertStringContainsString('B1 (true)', $tester->getDisplay());
        $this->assertStringContainsString('B2 (false)', $tester->getDisplay());
        unlink($file);
    }

    public function testHelpMentionsThatTheExtensionIsNotLoaded(): void
    {
        $application = new Application('rephper');
        $application->setAutoExit(false);
        $application->add(new GraphCommand());
        $tester = new ApplicationTester($application);

        $status = $tester->run(['command' => 'graph', '--help' => true]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('does not load the Zend extension', $tester->getDisplay());
        $this->assertStringContainsString('--op-array', $tester->getDisplay());
    }

    public function testNoCommandListsGraphAndSucceeds(): void
    {
        $application = new Application('rephper');
        $application->setAutoExit(false);
        $application->add(new GraphCommand());
        $tester = new ApplicationTester($application);

        $status = $tester->run([]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('graph', $tester->getDisplay());
    }

    public function testUnknownCommandFails(): void
    {
        $application = new Application('rephper');
        $application->setAutoExit(false);
        $application->add(new GraphCommand());
        $tester = new ApplicationTester($application);

        $status = $tester->run(['command' => 'inspect']);

        $this->assertSame(1, $status);
    }

    public function testMissingFileArgumentFails(): void
    {
        $application = new Application('rephper');
        $application->setAutoExit(false);
        $application->add(new GraphCommand());
        $tester = new ApplicationTester($application);

        $status = $tester->run(['command' => 'graph']);

        $this->assertSame(1, $status);
    }

    public function testMissingPathIsReported(): void
    {
        $tester = $this->tester();
        $status = $tester->execute(['file' => '/tmp/rephper-missing-ir.json'], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('IR file does not exist', $tester->getErrorOutput());
    }

    public function testInvalidJsonIsReported(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rephper-bad-');
        $this->assertNotFalse($file);
        file_put_contents($file, '{');

        $tester = $this->tester();
        $status = $tester->execute(['file' => $file], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('IR is not valid JSON', $tester->getErrorOutput());
        unlink($file);
    }

    public function testUnsupportedIrVersionIsReported(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rephper-version-');
        $this->assertNotFalse($file);
        file_put_contents($file, '{"ir_version":2}');

        $tester = $this->tester();
        $status = $tester->execute(['file' => $file], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Unsupported IR version 2', $tester->getErrorOutput());
        unlink($file);
    }

    public function testInvalidJumpIsReported(): void
    {
        $file = $this->classifyFile();
        $decoded = json_decode((string) file_get_contents($file), true);
        $decoded['op_arrays'][0]['instructions'][2]['op2']['target'] = 99;
        file_put_contents($file, json_encode($decoded));

        $tester = $this->tester();
        $status = $tester->execute(['file' => $file], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('99', $tester->getErrorOutput());
        unlink($file);
    }

    public function testUnknownOpArrayNameFails(): void
    {
        $file = $this->classifyFile();
        $tester = $this->tester();
        $status = $tester->execute([
            'file' => $file,
            '--op-array' => 'missing',
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('No op array named missing.', $tester->getErrorOutput());
        unlink($file);
    }

    public function testExpectedJsonPrintsWithoutRewritingTheVersion(): void
    {
        $tester = $this->tester();
        $status = $tester->execute([
            'file' => dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Oop.json',
            '--op-array' => 'name',
        ]);

        $this->assertSame(0, $status, $tester->getDisplay());
        $this->assertStringContainsString('OpArray #0 name (method)', $tester->getDisplay());
    }

    public function testExecutablePrintsClassify(): void
    {
        $file = $this->classifyFile();
        $result = Process::run([
            PHP_BINARY,
            '-n',
            dirname(__DIR__, 3) . '/bin/rephper',
            'graph',
            $file,
            '--op-array=classify',
        ]);

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertStringContainsString('OpArray #0 classify (function)', $result['stdout']);
        $this->assertStringContainsString('2: JMPZ', $result['stdout']);
        unlink($file);
    }

    private function tester(): CommandTester
    {
        $command = (new Application('rephper'))->add(new GraphCommand());
        $this->assertNotNull($command);

        return new CommandTester($command);
    }

    private function classifyFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'rephper-cfg-');
        $this->assertNotFalse($file);
        $json = (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Conditions.json');
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);
        file_put_contents($file, $json);

        return $file;
    }
}
