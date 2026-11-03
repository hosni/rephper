<?php

declare(strict_types=1);

namespace Rephper\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephper\Tests\Support\FixtureSets;
use Rephper\Tests\Support\Process;
use UnexpectedValueException;

final class ExporterTest extends TestCase
{
    private static ?string $release = null;

    protected function setUp(): void
    {
        if (!is_file($this->extensionPath())) {
            $this->markTestSkipped('Rephper extension is not built: ' . $this->extensionPath());
        }

        $release = $this->release();
        $runner = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $tool = preg_replace('/^(\d+\.\d+).*$/', '$1', $release);
        $this->assertSame($runner, $tool, "PHPUnit PHP {$runner} does not match {$this->phpBinary()} ({$tool}).");
        $this->assertTrue(
            FixtureSets::releaseIsSupported($release),
            'PHP ' . $release . ' is not supported. Supported versions: ' . implode(', ', FixtureSets::SUPPORTED)
        );
    }

    public function testExtensionLoadsWithoutStartupOutput(): void
    {
        $load = Process::run([
            $this->phpBinary(),
            '-n',
            '-d',
            'zend_extension=' . $this->extensionPath(),
            '-r',
            'echo "ok\n";',
        ]);

        $this->assertSame(0, $load['exit'], $load['stderr']);
        $this->assertSame("ok\n", $load['stdout']);
        $this->assertSame('', $load['stderr']);
    }

    public function testPhpModulesListsRephper(): void
    {
        $modules = Process::run([
            $this->phpBinary(),
            '-n',
            '-d',
            'zend_extension=' . $this->extensionPath(),
            '-m',
        ]);

        $this->assertSame(1, preg_match('/^Rephper$/m', $modules['stdout']));
    }

    #[DataProvider('fixtures')]
    public function testFixture(string $fixture): void
    {
        $root = $this->root();
        $relative = substr($fixture, strlen($root . '/tests/Fixtures/'));
        $release = $this->release();
        $irFile = tempnam(sys_get_temp_dir(), 'rephper-ir-');
        $this->assertNotFalse($irFile);
        putenv('REPHPER_IR=' . $irFile);

        try {
            $result = $this->lint($fixture);
            $dump = $this->normalizeDump($result['stderr'], $fixture);
            $this->assertSame(0, $result['exit'], $result['stdout'] . "\n" . $dump);
            $this->assertStringContainsString('No syntax errors detected', $result['stdout']);
            $this->assertStringContainsString("Rephper: Compiled op array\n", $dump);
            $this->assertSame(1, preg_match('/^[0-9]+ [A-Z][A-Z0-9_]* line=/m', $dump));

            foreach (FixtureSets::snippets()[$relative] ?? [] as $snippet) {
                $this->assertStringContainsString($snippet, $dump, $relative . ' is missing ' . $snippet);
            }

            $expectedFile = FixtureSets::expected($root, $release, $fixture, '.txt');
            if (getenv('REPHPER_UPDATE_EXPECTED') === '1') {
                $this->writeExpected($expectedFile, $dump);
            }
            $this->assertFileExists($expectedFile);
            $this->assertSame((string) file_get_contents($expectedFile), $dump);

            $irRaw = (string) file_get_contents($irFile);
            $this->assertNotSame('', $irRaw, $relative . ' did not write an IR file');
            $decoded = json_decode($irRaw, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $this->assertIrDocument($decoded, $release);
            $this->assertFixtureIr($relative, $decoded);

            $normalized = $this->normalizeIr($irRaw, $fixture, $release);
            $irExpected = FixtureSets::expected($root, $release, $fixture, '.json');
            if (getenv('REPHPER_UPDATE_EXPECTED') === '1') {
                $this->writeExpected($irExpected, $normalized);
            }
            $this->assertFileExists($irExpected);
            $this->assertSame((string) file_get_contents($irExpected), $normalized);

            $again = $this->lint($fixture);
            $second = (string) file_get_contents($irFile);
            $this->assertSame(0, $again['exit']);
            $this->assertSame($irRaw, $second, $relative . ' IR output is not deterministic');
        } finally {
            putenv('REPHPER_IR');
            @unlink($irFile);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fixtures(): array
    {
        $root = dirname(__DIR__, 2);
        $release = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.0';
        $cases = [];
        foreach (FixtureSets::discover($root, $release) as $fixture) {
            $relative = substr($fixture, strlen($root . '/tests/Fixtures/'));
            $cases[$relative] = [$fixture];
        }

        return $cases;
    }

    public function testIrExportRefusesADirectory(): void
    {
        $directory = sys_get_temp_dir() . '/rephper-ir-dir-' . getmypid();
        $this->assertTrue(mkdir($directory, 0700, true) || is_dir($directory));
        putenv('REPHPER_IR=' . $directory);

        try {
            $blocked = $this->lint($this->root() . '/tests/Fixtures/Common/Arithmetic.php');
            $this->assertSame(0, $blocked['exit']);
            $this->assertDirectoryExists($directory);
            $this->assertStringContainsString('IR path is not a regular file', $blocked['stderr']);
        } finally {
            putenv('REPHPER_IR');
            @rmdir($directory);
        }
    }

    public function testLintDoesNotExecuteTheFileOrCompileRequiredFiles(): void
    {
        $directory = sys_get_temp_dir() . '/rephper-probe-' . getmypid();
        $this->assertTrue(mkdir($directory, 0700, true) || is_dir($directory));
        $main = $directory . '/main.php';
        $other = $directory . '/other.php';

        try {
            file_put_contents($other, "<?php\nfunction rephper_other(): int { return 2; }\n");
            file_put_contents(
                $main,
                "<?php\nrequire __DIR__ . '/other.php';\nexit(7);\nfunction rephper_main(): int { return 1; }\n"
            );
            $probe = $this->lint($main);
            $this->assertSame(0, $probe['exit'], $probe['stdout'] . "\n" . $probe['stderr']);
            $this->assertStringContainsString('No syntax errors detected', $probe['stdout']);
            $this->assertStringContainsString('Function: rephper_main', $probe['stderr']);
            $this->assertStringContainsString('Kind: main', $probe['stderr']);
            $this->assertStringContainsString('INCLUDE_OR_EVAL', $probe['stderr']);
            $this->assertStringNotContainsString('Function: rephper_other', $probe['stderr']);
        } finally {
            @unlink($main);
            @unlink($other);
            @rmdir($directory);
        }
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function lint(string $fixture): array
    {
        return Process::run([
            $this->phpBinary(),
            '-n',
            '-d',
            'display_errors=1',
            '-d',
            'zend_extension=' . $this->extensionPath(),
            '-l',
            $fixture,
        ]);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function phpBinary(): string
    {
        $php = getenv('REPHPER_PHP');

        return is_string($php) && $php !== '' ? $php : PHP_BINARY;
    }

    private function extensionPath(): string
    {
        $extension = getenv('REPHPER_EXTENSION');

        return is_string($extension) && $extension !== ''
            ? $extension
            : $this->root() . '/extension/modules/rephper.so';
    }

    private function release(): string
    {
        if (self::$release !== null) {
            return self::$release;
        }

        $result = Process::run([$this->phpBinary(), '-n', '-r', 'echo PHP_VERSION;']);
        $this->assertSame(0, $result['exit'], $result['stderr']);
        self::$release = trim($result['stdout']);

        return self::$release;
    }

    private function writeExpected(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            $this->fail('Unable to create ' . $directory);
        }
        file_put_contents($path, $contents);
    }

    private function normalizeDump(string $dump, string $fixture): string
    {
        $root = $this->root();
        $relative = substr($fixture, strlen($root) + 1);
        $paths = [$fixture];
        $real = realpath($fixture);
        if ($real !== false) {
            $paths[] = $real;
        }

        foreach (array_unique($paths) as $path) {
            $dump = str_replace($path, $relative, $dump);
            $dump = str_replace(strtolower($path), strtolower($relative), $dump);
        }

        return str_replace("\r\n", "\n", $dump);
    }

    private function normalizeIr(string $json, string $fixture, string $release): string
    {
        $json = $this->normalizeDump($json, $fixture);
        $json = preg_replace(
            '/"version": "' . preg_quote($release, '/') . '"/',
            '"version": "PHP_VERSION"',
            $json,
            1
        ) ?? $json;

        return preg_replace(
            '/"version_id": ' . PHP_VERSION_ID . '\b/',
            '"version_id": 0',
            $json,
            1
        ) ?? $json;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function assertIrDocument(array $document, string $release): void
    {
        $this->assertSame(1, $document['ir_version'] ?? null);
        $php = $document['php'] ?? null;
        $this->assertIsArray($php);
        $this->assertSame($release, $php['version'] ?? null);
        $this->assertSame(PHP_VERSION_ID, $php['version_id'] ?? null);
        $this->assertSame(20240924, $php['api'] ?? null);
        $this->assertSame(20240924, $php['zend_module_api'] ?? null);
        $this->assertSame(420240924, $php['zend_extension_api'] ?? null);
        $this->assertSame((bool) ZEND_THREAD_SAFE, $php['zts'] ?? null);
        $this->assertSame((bool) ZEND_DEBUG_BUILD, $php['debug'] ?? null);
        $compilation = $document['compilation'] ?? null;
        $this->assertIsArray($compilation);
        $this->assertSame('pre_pass_two', $compilation['stage'] ?? null);
        $this->assertSame('absolute_opline', $compilation['jumps'] ?? null);
        $this->assertIsArray($document['op_arrays'] ?? null);
        $this->assertNotEmpty($document['op_arrays']);
        $this->assertIsArray($document['classes'] ?? null);

        $literalTypes = ['null', 'bool', 'int', 'float', 'string', 'array', 'constant_ast', 'undef', 'unsupported'];
        $operandKinds = ['unused', 'this', 'const', 'tmp', 'var', 'cv', 'jump', 'try_catch', 'level', 'label', 'unknown'];
        foreach ($document['op_arrays'] as $opArray) {
            $this->assertIsArray($opArray);
            $this->assertIsArray($opArray['instructions'] ?? null);
            $this->assertIsArray($opArray['literals'] ?? null);
            $instructionCount = count($opArray['instructions']);
            $literalCount = count($opArray['literals']);
            $variableCount = is_array($opArray['variables'] ?? null) ? count($opArray['variables']) : 0;
            foreach ($opArray['literals'] as $literal) {
                $this->assertIsArray($literal);
                $this->assertContains($literal['type'] ?? null, $literalTypes);
            }
            foreach ($opArray['instructions'] as $instruction) {
                $this->assertIsString($instruction['opcode'] ?? null);
                $this->assertIsInt($instruction['opcode_number'] ?? null);
                $this->assertIsInt($instruction['line'] ?? null);
                $this->assertIsInt($instruction['extended_value'] ?? null);
                foreach (['op1', 'op2', 'result'] as $slot) {
                    $operand = $instruction[$slot] ?? null;
                    $this->assertIsArray($operand);
                    $this->assertContains($operand['kind'] ?? null, $operandKinds);
                    if ($operand['kind'] === 'jump') {
                        $target = $operand['target'] ?? null;
                        $this->assertIsInt($target);
                        $this->assertGreaterThanOrEqual(0, $target);
                        $this->assertLessThanOrEqual($instructionCount, $target);
                    }
                    if ($operand['kind'] === 'const') {
                        $index = $operand['index'] ?? null;
                        $this->assertIsInt($index);
                        $this->assertGreaterThanOrEqual(0, $index);
                        $this->assertLessThan($literalCount, $index);
                    }
                    if ($operand['kind'] === 'cv' && array_key_exists('index', $operand) && $operand['index'] !== null) {
                        $this->assertIsInt($operand['index']);
                        $this->assertGreaterThanOrEqual(0, $operand['index']);
                        $this->assertLessThan($variableCount, $operand['index']);
                    }
                }
                if (array_key_exists('jump_target', $instruction)) {
                    $target = $instruction['jump_target'];
                    $this->assertIsInt($target);
                    $this->assertGreaterThanOrEqual(0, $target);
                    $this->assertLessThanOrEqual($instructionCount, $target);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private function assertFixtureIr(string $relative, array $document): void
    {
        $find = function (string $kind, ?string $name) use ($document, $relative): array {
            $opArray = $this->findOpArray($document, $kind, $name);
            if ($opArray === null) {
                throw new UnexpectedValueException($relative . " is missing {$kind} " . ($name ?? '<main>'));
            }

            return $opArray;
        };

        switch ($relative) {
            case 'Common/Arithmetic.php':
                $calculate = $find('function', 'calculate');
                $this->assertTrue($this->hasOpcode($calculate, 'ADD'));
                $this->assertSame(['a', 'b'], $calculate['variables'] ?? null);
                $main = $find('main', null);
                $this->assertTrue($this->valueContains(['literals' => $main['literals']], 'int', 10));
                $this->assertTrue($this->valueContains(['literals' => $main['literals']], 'int', 20));
                break;
            case 'Common/Scalars.php':
                $scale = $find('function', 'scale');
                $this->assertTrue($this->hasOpcode($scale, 'ASSIGN_OP'));
                $this->assertTrue($this->valueContains(['literals' => $scale['literals']], 'float', 0.5));
                $main = $find('main', null);
                $encoded = json_encode($main['literals']);
                $this->assertTrue($this->valueContains(['literals' => $main['literals']], 'null', null));
                $this->assertTrue($this->valueContains(['literals' => $main['literals']], 'bool', true));
                $this->assertTrue($this->valueContains(['literals' => $main['literals']], 'bool', false));
                $this->assertTrue($this->valueContains(['literals' => $main['literals']], 'string', 'inner'));
                $this->assertTrue($this->valueContains(['literals' => $main['literals']], 'float', '-0'));
                $this->assertTrue($this->valueContains(['literals' => $main['literals']], 'float', 'NaN'));
                $this->assertTrue($this->valueContains(['literals' => $main['literals']], 'float', 'Infinity'));
                $this->assertStringContainsString('"binary":true', (string) $encoded);
                break;
            case 'Common/Parameters.php':
                $build = $find('function', 'build');
                $this->assertSame(0, $build['arguments'][1]['default_literal'] ?? null);
                $this->assertTrue($build['arguments'][2]['variadic'] ?? null);
                $this->assertTrue($this->valueContains(['literals' => $build['literals']], 'int', 2));
                break;
            case 'Common/ControlFlow.php':
                $walk = $find('function', 'walk');
                $this->assertTrue($this->hasOpcode($walk, 'JMPZ'));
                $this->assertTrue($this->hasOpcode($walk, 'JMP'));
                $this->assertTrue($this->hasOpcode($walk, 'FE_FETCH_R'));
                $this->assertTrue($this->hasOpcode($walk, 'BRK'));
                $this->assertTrue($this->hasOpcode($walk, 'CONT'));
                $this->assertIsArray($walk['breaks']);
                $this->assertNotEmpty($walk['breaks']);
                break;
            case 'Common/ArrowFunctions.php':
                $closure = null;
                foreach ($document['op_arrays'] as $opArray) {
                    if (($opArray['kind'] ?? null) === 'closure') {
                        $closure = $opArray;
                    }
                }
                $this->assertIsArray($closure);
                $this->assertTrue($this->hasOpcode($closure, 'MUL'));
                break;
            case 'Common/Oop.php':
                $item = null;
                foreach ($document['classes'] as $class) {
                    if (($class['name'] ?? null) === 'Item') {
                        $item = $class;
                    }
                }
                $this->assertIsArray($item);
                $this->assertSame('Base', $item['parent'] ?? null);
                $this->assertContains('Named', $item['interfaces'] ?? []);
                $this->assertContains('Label', $item['traits'] ?? []);
                break;
            case 'Common/Exceptions.php':
                $guard = $find('function', 'guard');
                $this->assertContains('has_finally', $guard['flags'] ?? []);
                $this->assertNotEmpty($guard['try_catch'] ?? []);
                break;
            case 'Common/Generators.php':
                $sequence = $find('function', 'sequence');
                $this->assertContains('generator', $sequence['flags'] ?? []);
                $this->assertTrue($this->hasOpcode($sequence, 'YIELD'));
                break;
            case 'Common/AnonymousClass.php':
                $anonymous = false;
                foreach ($document['classes'] as $class) {
                    if (in_array('anonymous', $class['flags'] ?? [], true)) {
                        $anonymous = true;
                    }
                }
                $this->assertTrue($anonymous);
                break;
            case 'PHP/8.0/MatchExpression.php':
                $classify = $find('function', 'classify');
                $table = false;
                foreach ($classify['instructions'] as $instruction) {
                    if (($instruction['opcode'] ?? null) === 'MATCH' && !empty($instruction['op2']['jump_table'])) {
                        $table = true;
                    }
                }
                $this->assertTrue($table);
                break;
            case 'PHP/8.1/Enums.php':
                $status = null;
                foreach ($document['classes'] as $class) {
                    if (($class['kind'] ?? null) === 'enum' && ($class['name'] ?? null) === 'Status') {
                        $status = $class;
                    }
                }
                $this->assertIsArray($status);
                $this->assertSame('string', $status['enum_backing'] ?? null);
                $this->assertContains('Colorful', $status['interfaces'] ?? []);
                $open = false;
                foreach ($status['constants'] as $constant) {
                    if (($constant['name'] ?? null) === 'Open' && in_array('enum_case', $constant['flags'] ?? [], true) && $this->valueContains($constant['value'] ?? [], 'string', 'open')) {
                        $open = true;
                    }
                }
                $this->assertTrue($open);
                break;
            case 'PHP/8.2/Readonly.php':
                $point = null;
                foreach ($document['classes'] as $class) {
                    if (($class['name'] ?? null) === 'Point') {
                        $point = $class;
                    }
                }
                $this->assertIsArray($point);
                $this->assertContains('readonly', $point['flags'] ?? []);
                $promoted = false;
                foreach ($point['properties'] as $property) {
                    if (($property['name'] ?? null) === 'x' && in_array('promoted', $property['flags'] ?? [], true) && in_array('readonly', $property['flags'] ?? [], true)) {
                        $promoted = true;
                    }
                }
                $this->assertTrue($promoted);
                break;
            case 'PHP/8.4/PropertyHooks.php':
                $get = $find('property-hook', '$value::get');
                $set = $find('property-hook', '$value::set');
                $this->assertSame('get', $get['property_hook']['hook'] ?? null);
                $this->assertSame('set', $set['property_hook']['hook'] ?? null);
                $box = null;
                foreach ($document['classes'] as $class) {
                    if (($class['name'] ?? null) === 'Box') {
                        $box = $class;
                    }
                }
                $this->assertIsArray($box);
                $this->assertSame(['get', 'set'], $box['properties'][0]['hooks'] ?? null);
                break;
        }
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null
     */
    private function findOpArray(array $document, string $kind, ?string $name): ?array
    {
        foreach ($document['op_arrays'] as $opArray) {
            if (($opArray['kind'] ?? null) === $kind && ($opArray['name'] ?? null) === $name) {
                return $opArray;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $opArray
     */
    private function hasOpcode(array $opArray, string $opcode): bool
    {
        foreach ($opArray['instructions'] as $instruction) {
            if (($instruction['opcode'] ?? null) === $opcode) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function valueContains(array $value, string $type, mixed $expected): bool
    {
        if (($value['type'] ?? null) === $type) {
            if ($type === 'null' || (array_key_exists('value', $value) && $value['value'] === $expected)) {
                return true;
            }
        }
        foreach ($value as $child) {
            if (is_array($child) && $this->valueContains($child, $type, $expected)) {
                return true;
            }
        }

        return false;
    }
}
