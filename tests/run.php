<?php

/**
 * Compile each fixture with the Rephper Zend extension and compare the
 * opcode dump with the expected output for this PHP version.
 *
 * Supported versions are the versions Rephper implements. A fixture under
 * Fixtures/PHP/X.Y runs on PHP X.Y and every later supported version.
 * Fixtures/Common runs on every supported version.
 */

declare(strict_types=1);

const SUPPORTED_VERSIONS = ['8.4'];

$root = dirname(__DIR__);
$php = getenv('REPHPER_PHP') ?: PHP_BINARY;
$extension = getenv('REPHPER_EXTENSION') ?: $root . '/extension/modules/rephper.so';
$updateExpected = getenv('REPHPER_UPDATE_EXPECTED') === '1';

$failures = 0;

function fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function ir_fail(string $message): never
{
    throw new UnexpectedValueException($message);
}

function version_at_least(string $actual, string $minimum): bool
{
    return version_compare($actual, $minimum, '>=');
}

/**
 * @param list<string> $supported
 * @return list<string>
 */
function selectable_versions(string $actual, array $supported): array
{
    $selected = [];
    foreach ($supported as $version) {
        if (version_at_least($actual, $version)) {
            $selected[] = $version;
        }
    }

    return $selected;
}

function assert_same(string $label, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        fail($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function self_check(): void
{
    assert_same('8.4 >= 8.4', version_at_least('8.4.27', '8.4'), true);
    assert_same('8.4 >= 8.0', version_at_least('8.4.0', '8.0'), true);
    assert_same('8.3 >= 8.4', version_at_least('8.3.30', '8.4'), false);
    assert_same('8.10 > 8.9', version_compare('8.10', '8.9', '>'), true);
    assert_same('8.4 selects only 8.4', selectable_versions('8.4.27', ['8.4']), ['8.4']);
    assert_same(
        '8.4 selects earlier supported versions',
        selectable_versions('8.4.27', ['8.0', '8.3', '8.4']),
        ['8.0', '8.3', '8.4']
    );
    assert_same(
        '8.4 excludes a newer supported version',
        selectable_versions('8.4.27', ['8.4', '8.5']),
        ['8.4']
    );
}

/**
 * @return array{exit: int, stdout: string, stderr: string}
 */
function run_process(array $command): array
{
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['exit' => 1, 'stdout' => '', 'stderr' => 'failed to start process'];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    while (!feof($pipes[1]) || !feof($pipes[2])) {
        $read = [];
        if (!feof($pipes[1])) {
            $read[] = $pipes[1];
        }
        if (!feof($pipes[2])) {
            $read[] = $pipes[2];
        }
        if ($read === []) {
            break;
        }
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 1) === false) {
            break;
        }
        foreach ($read as $pipe) {
            $chunk = fread($pipe, 65536);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            if ($pipe === $pipes[1]) {
                $stdout .= $chunk;
            } else {
                $stderr .= $chunk;
            }
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit' => proc_close($process),
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

function php_release(string $php): string
{
    $result = run_process([$php, '-n', '-r', 'echo PHP_VERSION;']);
    if ($result['exit'] !== 0) {
        fail("Unable to read the version of {$php}: {$result['stderr']}");
    }

    return trim($result['stdout']);
}

function normalize_dump(string $dump, string $fixture, string $root): string
{
    $relative = substr($fixture, strlen($root) + 1);
    $paths = [$fixture];
    $real = realpath($fixture);
    if ($real !== false) {
        $paths[] = $real;
    }

    foreach (array_unique($paths) as $path) {
        $dump = str_replace($path, $relative, $dump);
        /*
         * Anonymous-class runtime keys store a lowercased filename. Replace
         * that form too, or the golden file keeps the machine-specific path.
         */
        $dump = str_replace(strtolower($path), strtolower($relative), $dump);
    }

    return str_replace("\r\n", "\n", $dump);
}

function show_diff(string $expected, string $actual): void
{
    $expectedLines = explode("\n", $expected);
    $actualLines = explode("\n", $actual);
    $limit = max(count($expectedLines), count($actualLines));
    $shown = 0;

    for ($i = 0; $i < $limit; $i++) {
        $expectedLine = $expectedLines[$i] ?? '<missing>';
        $actualLine = $actualLines[$i] ?? '<missing>';
        if ($expectedLine === $actualLine) {
            continue;
        }
        fwrite(STDERR, '  line ' . ($i + 1) . "\n");
        fwrite(STDERR, '    expected: ' . $expectedLine . "\n");
        fwrite(STDERR, '    actual:   ' . $actualLine . "\n");
        $shown++;
        if ($shown >= 40) {
            fwrite(STDERR, "  further differences omitted\n");
            break;
        }
    }
}

/**
 * Language fixtures are selected by the PHP version that introduced them.
 * A directory under Fixtures/PHP/X.Y runs when the active PHP is X.Y or newer.
 * That is separate from SUPPORTED_VERSIONS, which is the engine implementation.
 *
 * @return list<string>
 */
function language_fixture_versions(string $root, string $release): array
{
    $versions = [];
    foreach (glob($root . '/tests/Fixtures/PHP/*', GLOB_ONLYDIR) ?: [] as $directory) {
        $version = basename($directory);
        if (preg_match('/^\d+\.\d+$/', $version) !== 1) {
            continue;
        }
        if (version_at_least($release, $version)) {
            $versions[] = $version;
        }
    }

    usort($versions, static function (string $left, string $right): int {
        return version_compare($left, $right);
    });

    return $versions;
}

/**
 * @return list<string>
 */
function discover_fixtures(string $root, string $release): array
{
    $fixtures = glob($root . '/tests/Fixtures/Common/*.php') ?: [];
    foreach (language_fixture_versions($root, $release) as $version) {
        $versionFixtures = glob($root . '/tests/Fixtures/PHP/' . $version . '/*.php') ?: [];
        foreach ($versionFixtures as $fixture) {
            $fixtures[] = $fixture;
        }
    }

    sort($fixtures, SORT_STRING);
    return $fixtures;
}

function expected_path(string $root, string $release, string $fixture): string
{
    return expected_artifact($root, $release, $fixture, '.txt');
}

function ir_expected_path(string $root, string $release, string $fixture): string
{
    return expected_artifact($root, $release, $fixture, '.json');
}

function expected_artifact(string $root, string $release, string $fixture, string $extension): string
{
    $relative = substr($fixture, strlen($root . '/tests/Fixtures/'));
    $relative = preg_replace('/\.php$/', $extension, $relative);
    $minor = preg_replace('/^(\d+\.\d+).*$/', '$1', $release);

    return $root . '/tests/Expected/PHP/' . $minor . '/' . $relative;
}

/**
 * @return array<string, list<string>>
 */
function required_snippets(): array
{
    return [
        'Common/Arithmetic.php' => ['Function: calculate', 'Kind: main', ' ADD ', ' RETURN'],
        'Common/Functions.php' => ['Function: greet', 'Kind: main', 'CONST('],
        'Common/Conditions.php' => ['Function: classify', ' JMPZ '],
        'Common/Loops.php' => ['Function: calculateTotal', ' FE_'],
        'Common/Classes.php' => ['Kind: method', 'Class: Calculator', 'Function: add'],
        'Common/Closures.php' => ['Kind: closure', 'Kind: main'],
        'Common/AnonymousClass.php' => ['Kind: method', 'class@anonymous'],
        'PHP/8.4/PropertyHooks.php' => ['Kind: property-hook', '$value::get', '$value::set', 'Class: Box'],
        'Common/Scalars.php' => ['Function: scale', ' ASSIGN_OP '],
        'Common/ControlFlow.php' => ['Function: walk', ' JMPZ ', ' FE_'],
        'Common/Parameters.php' => ['Function: build', ' RECV_VARIADIC '],
        'Common/ArrowFunctions.php' => ['Kind: closure'],
        'Common/Oop.php' => ['Class: Item', 'Class: Base'],
        'Common/Exceptions.php' => [' CATCH ', ' FAST_CALL '],
        'Common/Generators.php' => [' YIELD '],
        'PHP/8.0/MatchExpression.php' => [' MATCH '],
        'PHP/8.1/Enums.php' => ['Class: Status'],
        'PHP/8.2/Readonly.php' => ['Class: Point', 'Function: __construct'],
    ];
}

function normalize_ir(string $json, string $fixture, string $root, string $release): string
{
    $json = normalize_dump($json, $fixture, $root);
    $json = preg_replace(
        '/"version": "' . preg_quote($release, '/') . '"/',
        '"version": "PHP_VERSION"',
        $json,
        1
    );
    $versionId = PHP_VERSION_ID;

    return preg_replace(
        '/"version_id": ' . $versionId . '\b/',
        '"version_id": 0',
        $json,
        1
    ) ?? $json;
}

/**
 * @param array<string, mixed> $document
 */
function assert_ir_document(array $document, string $release): void
{
    if (($document['ir_version'] ?? null) !== 1) {
        ir_fail('IR version is not 1');
    }
    $php = $document['php'] ?? null;
    if (!is_array($php)) {
        ir_fail('IR is missing php metadata');
    }
    if (($php['version'] ?? null) !== $release) {
        ir_fail('IR PHP version does not match the compiler');
    }
    if (($php['version_id'] ?? null) !== PHP_VERSION_ID) {
        ir_fail('IR PHP version id does not match the compiler');
    }
    if (($php['api'] ?? null) !== 20240924 || ($php['zend_module_api'] ?? null) !== 20240924) {
        ir_fail('IR PHP API metadata is not 20240924');
    }
    if (($php['zend_extension_api'] ?? null) !== 420240924) {
        ir_fail('IR Zend extension API is not 420240924');
    }
    if (($php['zts'] ?? null) !== (bool) ZEND_THREAD_SAFE || ($php['debug'] ?? null) !== (bool) ZEND_DEBUG_BUILD) {
        ir_fail('IR ZTS or debug metadata does not match this PHP build');
    }
    $compilation = $document['compilation'] ?? null;
    if (!is_array($compilation) || ($compilation['stage'] ?? null) !== 'pre_pass_two' || ($compilation['jumps'] ?? null) !== 'absolute_opline') {
        ir_fail('IR compilation metadata is missing');
    }
    if (!is_array($document['op_arrays'] ?? null) || $document['op_arrays'] === []) {
        ir_fail('IR has no op arrays');
    }
    if (!is_array($document['classes'] ?? null)) {
        ir_fail('IR classes field is missing');
    }

    $literalTypes = ['null', 'bool', 'int', 'float', 'string', 'array', 'constant_ast', 'undef', 'unsupported'];
    $operandKinds = ['unused', 'this', 'const', 'tmp', 'var', 'cv', 'jump', 'try_catch', 'level', 'label', 'unknown'];
    foreach ($document['op_arrays'] as $opArray) {
        if (!is_array($opArray) || !is_array($opArray['instructions'] ?? null) || !is_array($opArray['literals'] ?? null)) {
            ir_fail('IR op array is incomplete');
        }
        $instructionCount = count($opArray['instructions']);
        $literalCount = count($opArray['literals']);
        $variableCount = is_array($opArray['variables'] ?? null) ? count($opArray['variables']) : 0;
        foreach ($opArray['literals'] as $literal) {
            if (!is_array($literal) || !in_array($literal['type'] ?? null, $literalTypes, true)) {
                ir_fail('IR literal has an unknown type');
            }
        }
        foreach ($opArray['instructions'] as $instruction) {
            if (!is_string($instruction['opcode'] ?? null) || !is_int($instruction['opcode_number'] ?? null) || !is_int($instruction['line'] ?? null)) {
                ir_fail('IR instruction is missing opcode metadata');
            }
            if (!is_int($instruction['extended_value'] ?? null)) {
                ir_fail('IR instruction is missing extended_value');
            }
            foreach (['op1', 'op2', 'result'] as $slot) {
                $operand = $instruction[$slot] ?? null;
                if (!is_array($operand) || !in_array($operand['kind'] ?? null, $operandKinds, true)) {
                    ir_fail('IR operand kind is missing');
                }
                if ($operand['kind'] === 'jump') {
                    $target = $operand['target'] ?? null;
                    if (!is_int($target) || $target < 0 || $target > $instructionCount) {
                        ir_fail('IR jump target is outside the op array');
                    }
                }
                if ($operand['kind'] === 'const') {
                    $index = $operand['index'] ?? null;
                    if (!is_int($index) || $index < 0 || $index >= $literalCount) {
                        ir_fail('IR const operand does not point at a literal');
                    }
                }
                if ($operand['kind'] === 'cv' && array_key_exists('index', $operand) && $operand['index'] !== null) {
                    if (!is_int($operand['index']) || $operand['index'] < 0 || $operand['index'] >= $variableCount) {
                        ir_fail('IR compiled variable is outside the variable table');
                    }
                }
            }
            if (array_key_exists('jump_target', $instruction)) {
                $target = $instruction['jump_target'];
                if (!is_int($target) || $target < 0 || $target > $instructionCount) {
                    ir_fail('IR extended jump target is outside the op array');
                }
            }
        }
    }
}

/**
 * @param array<string, mixed> $document
 * @return array<string, mixed>|null
 */
function find_op_array(array $document, string $kind, ?string $name): ?array
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
function op_array_has_opcode(array $opArray, string $opcode): bool
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
function value_contains(array $value, string $type, mixed $expected): bool
{
    if (($value['type'] ?? null) === $type) {
        if ($type === 'null' || (array_key_exists('value', $value) && $value['value'] === $expected)) {
            return true;
        }
    }
    foreach ($value as $child) {
        if (is_array($child) && value_contains($child, $type, $expected)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $document
 */
function assert_fixture_ir(string $relative, array $document): void
{
    $find = static function (string $kind, ?string $name) use ($document): array {
        $opArray = find_op_array($document, $kind, $name);
        if ($opArray === null) {
            ir_fail($relative . " is missing {$kind} " . ($name ?? '<main>'));
        }

        return $opArray;
    };

    switch ($relative) {
        case 'Common/Arithmetic.php':
            $calculate = $find('function', 'calculate');
            if (!op_array_has_opcode($calculate, 'ADD') || ($calculate['variables'] ?? null) !== ['a', 'b']) {
                ir_fail('Arithmetic IR is missing the addition of $a and $b');
            }
            $main = $find('main', null);
            if (!value_contains(['literals' => $main['literals']], 'int', 10) || !value_contains(['literals' => $main['literals']], 'int', 20)) {
                ir_fail('Arithmetic IR is missing the call arguments 10 and 20');
            }
            break;
        case 'Common/Scalars.php':
            $scale = $find('function', 'scale');
            if (!op_array_has_opcode($scale, 'ASSIGN_OP') || !value_contains(['literals' => $scale['literals']], 'float', 0.5)) {
                ir_fail('Scalars IR is missing the compound float assignment');
            }
            $main = $find('main', null);
            $encoded = json_encode($main['literals']);
            if (!value_contains(['literals' => $main['literals']], 'null', null)
                || !value_contains(['literals' => $main['literals']], 'bool', true)
                || !value_contains(['literals' => $main['literals']], 'bool', false)
                || !value_contains(['literals' => $main['literals']], 'string', 'inner')
                || !value_contains(['literals' => $main['literals']], 'float', '-0')
                || !value_contains(['literals' => $main['literals']], 'float', 'NaN')
                || !value_contains(['literals' => $main['literals']], 'float', 'Infinity')
                || !str_contains((string) $encoded, '"binary":true')) {
                ir_fail('Scalars IR is missing a null, bool, nested array, binary string, or special float');
            }
            break;
        case 'Common/Parameters.php':
            $build = $find('function', 'build');
            $arguments = $build['arguments'];
            if (($arguments[1]['default_literal'] ?? null) !== 0 || ($arguments[2]['variadic'] ?? null) !== true) {
                ir_fail('Parameters IR is missing the default or the variadic argument');
            }
            if (!value_contains(['literals' => $build['literals']], 'int', 2)) {
                ir_fail('Parameters IR default literal is not the integer 2');
            }
            break;
        case 'Common/ControlFlow.php':
            $walk = $find('function', 'walk');
            if (!op_array_has_opcode($walk, 'JMPZ') || !op_array_has_opcode($walk, 'JMP') || !op_array_has_opcode($walk, 'FE_FETCH_R') || !op_array_has_opcode($walk, 'BRK') || !op_array_has_opcode($walk, 'CONT')) {
                ir_fail('Control-flow IR is missing a jump, break, continue, or foreach fetch');
            }
            if (!is_array($walk['breaks']) || $walk['breaks'] === []) {
                ir_fail('Control-flow IR is missing break and continue targets');
            }
            break;
        case 'Common/ArrowFunctions.php':
            $closure = null;
            foreach ($document['op_arrays'] as $opArray) {
                if (($opArray['kind'] ?? null) === 'closure') {
                    $closure = $opArray;
                }
            }
            if ($closure === null || !op_array_has_opcode($closure, 'MUL')) {
                ir_fail('Arrow function IR is missing the closure body');
            }
            break;
        case 'Common/Oop.php':
            $item = null;
            foreach ($document['classes'] as $class) {
                if (($class['name'] ?? null) === 'Item') {
                    $item = $class;
                }
            }
            if ($item === null || ($item['parent'] ?? null) !== 'Base' || !in_array('Named', $item['interfaces'] ?? [], true) || !in_array('Label', $item['traits'] ?? [], true)) {
                ir_fail('OOP IR is missing Item extends Base implements Named use Label');
            }
            break;
        case 'Common/Exceptions.php':
            $guard = $find('function', 'guard');
            if (!in_array('has_finally', $guard['flags'] ?? [], true) || ($guard['try_catch'] ?? []) === []) {
                ir_fail('Exception IR is missing the try/catch/finally table');
            }
            break;
        case 'Common/Generators.php':
            $sequence = $find('function', 'sequence');
            if (!in_array('generator', $sequence['flags'] ?? [], true) || !op_array_has_opcode($sequence, 'YIELD')) {
                ir_fail('Generator IR is missing the generator flag or YIELD');
            }
            break;
        case 'Common/AnonymousClass.php':
            $anonymous = false;
            foreach ($document['classes'] as $class) {
                if (in_array('anonymous', $class['flags'] ?? [], true)) {
                    $anonymous = true;
                }
            }
            if (!$anonymous) {
                ir_fail('Anonymous class IR is missing the anonymous flag');
            }
            break;
        case 'PHP/8.0/MatchExpression.php':
            $classify = $find('function', 'classify');
            $table = false;
            foreach ($classify['instructions'] as $instruction) {
                if (($instruction['opcode'] ?? null) === 'MATCH' && !empty($instruction['op2']['jump_table'])) {
                    $table = true;
                }
            }
            if (!$table) {
                ir_fail('Match IR is missing the jump table');
            }
            break;
        case 'PHP/8.1/Enums.php':
            $status = null;
            foreach ($document['classes'] as $class) {
                if (($class['kind'] ?? null) === 'enum' && ($class['name'] ?? null) === 'Status') {
                    $status = $class;
                }
            }
            if ($status === null || ($status['enum_backing'] ?? null) !== 'string' || !in_array('Colorful', $status['interfaces'] ?? [], true)) {
                ir_fail('Enum IR is missing Status: string implements Colorful');
            }
            $open = false;
            foreach ($status['constants'] as $constant) {
                if (($constant['name'] ?? null) === 'Open' && in_array('enum_case', $constant['flags'] ?? [], true) && value_contains($constant['value'] ?? [], 'string', 'open')) {
                    $open = true;
                }
            }
            if (!$open) {
                ir_fail('Enum IR is missing case Open = open');
            }
            break;
        case 'PHP/8.2/Readonly.php':
            $point = null;
            foreach ($document['classes'] as $class) {
                if (($class['name'] ?? null) === 'Point') {
                    $point = $class;
                }
            }
            if ($point === null || !in_array('readonly', $point['flags'] ?? [], true)) {
                ir_fail('Readonly IR is missing the readonly class');
            }
            $promoted = false;
            foreach ($point['properties'] as $property) {
                if (($property['name'] ?? null) === 'x' && in_array('promoted', $property['flags'] ?? [], true) && in_array('readonly', $property['flags'] ?? [], true)) {
                    $promoted = true;
                }
            }
            if (!$promoted) {
                ir_fail('Readonly IR is missing promoted property $x');
            }
            break;
        case 'PHP/8.4/PropertyHooks.php':
            $get = $find('property-hook', '$value::get');
            $set = $find('property-hook', '$value::set');
            if (($get['property_hook']['hook'] ?? null) !== 'get' || ($set['property_hook']['hook'] ?? null) !== 'set') {
                ir_fail('Property hook IR is missing get and set');
            }
            $box = null;
            foreach ($document['classes'] as $class) {
                if (($class['name'] ?? null) === 'Box') {
                    $box = $class;
                }
            }
            if ($box === null || ($box['properties'][0]['hooks'] ?? null) !== ['get', 'set']) {
                ir_fail('Property hook IR is missing the hooks on Box::$value');
            }
            break;
    }
}

self_check();

if (!is_file($extension)) {
    fail("Rephper extension was not built: {$extension}");
}

$release = php_release($php);
$runnerRelease = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$toolRelease = preg_replace('/^(\d+\.\d+).*$/', '$1', $release);
if ($runnerRelease !== $toolRelease) {
    fail("Test runner PHP {$runnerRelease} does not match {$php} ({$toolRelease}).");
}

$supported = false;
foreach (SUPPORTED_VERSIONS as $version) {
    $parts = explode('.', $version);
    $upper = $parts[0] . '.' . ((int) $parts[1] + 1);
    if (version_compare($release, $version, '>=') && version_compare($release, $upper, '<')) {
        $supported = true;
        break;
    }
}
if (!$supported) {
    fail('PHP ' . $release . ' is not supported. Supported versions: ' . implode(', ', SUPPORTED_VERSIONS));
}

echo "Rephper tests\n";
echo "PHP: {$release}\n";
echo "Extension: {$extension}\n";

$load = run_process([
    $php,
    '-n',
    '-d',
    'zend_extension=' . $extension,
    '-r',
    'echo "ok\n";',
]);
if ($load['exit'] !== 0 || $load['stdout'] !== "ok\n" || $load['stderr'] !== '') {
    fwrite(STDERR, "Extension failed to load cleanly.\n");
    fwrite(STDERR, "exit: {$load['exit']}\n");
    fwrite(STDERR, "stdout:\n{$load['stdout']}\n");
    fwrite(STDERR, "stderr:\n{$load['stderr']}\n");
    $failures++;
}

$modules = run_process([
    $php,
    '-n',
    '-d',
    'zend_extension=' . $extension,
    '-m',
]);
if (!preg_match('/^Rephper$/m', $modules['stdout'])) {
    fwrite(STDERR, "php -m did not list the Rephper Zend extension.\n{$modules['stdout']}\n{$modules['stderr']}\n");
    $failures++;
}

$fixtures = discover_fixtures($root, $release);
if ($fixtures === []) {
    fail('No fixtures were discovered.');
}

$languageVersions = language_fixture_versions($root, $release);
echo 'Fixture sets: Common, ' . implode(', ', array_map(static fn (string $version): string => 'PHP/' . $version, $languageVersions)) . "\n";

$snippets = required_snippets();
foreach ($fixtures as $fixture) {
    $relative = substr($fixture, strlen($root . '/tests/Fixtures/'));
    $irFile = tempnam(sys_get_temp_dir(), 'rephper-ir-');
    if ($irFile === false) {
        fail('Unable to create a temporary IR file');
    }
    putenv('REPHPER_IR=' . $irFile);
    $result = run_process([
        $php,
        '-n',
        '-d',
        'display_errors=1',
        '-d',
        'zend_extension=' . $extension,
        '-l',
        $fixture,
    ]);

    $dump = normalize_dump($result['stderr'], $fixture, $root);
    $ok = $result['exit'] === 0
        && str_contains($result['stdout'], 'No syntax errors detected')
        && str_contains($dump, "Rephper: Compiled op array\n")
        && preg_match('/^[0-9]+ [A-Z][A-Z0-9_]* line=/m', $dump) === 1;

    if ($ok && isset($snippets[$relative])) {
        foreach ($snippets[$relative] as $snippet) {
            if (!str_contains($dump, $snippet)) {
                $ok = false;
                fwrite(STDERR, "{$relative}: dump is missing {$snippet}\n");
            }
        }
    }

    $expectedFile = expected_path($root, $release, $fixture);
    if ($updateExpected && $ok) {
        $directory = dirname($expectedFile);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            fail("Unable to create {$directory}");
        }
        file_put_contents($expectedFile, $dump);
    } elseif (!is_file($expectedFile)) {
        $ok = false;
        fwrite(STDERR, "{$relative}: expected output is missing at {$expectedFile}\n");
    } elseif (file_get_contents($expectedFile) !== $dump) {
        $ok = false;
        fwrite(STDERR, "{$relative}: opcode dump does not match {$expectedFile}\n");
        show_diff((string) file_get_contents($expectedFile), $dump);
    }

    $irRaw = is_file($irFile) ? (string) file_get_contents($irFile) : '';
    $irDocument = null;
    if ($irRaw === '') {
        $ok = false;
        fwrite(STDERR, "{$relative}: IR file was not written\n");
    } else {
        try {
            $decoded = json_decode($irRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $decoded = null;
            $ok = false;
            fwrite(STDERR, "{$relative}: IR is not valid JSON: {$exception->getMessage()}\n");
        }
        if (is_array($decoded)) {
            $irDocument = $decoded;
            try {
                assert_ir_document($decoded, $release);
                assert_fixture_ir($relative, $decoded);
            } catch (UnexpectedValueException $exception) {
                $ok = false;
                fwrite(STDERR, "{$relative}: {$exception->getMessage()}\n");
            }
        }
    }

    $irNormalized = $irDocument === null ? '' : normalize_ir($irRaw, $fixture, $root, $release);
    $irExpectedFile = ir_expected_path($root, $release, $fixture);
    if ($updateExpected && $ok) {
        $directory = dirname($irExpectedFile);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            fail("Unable to create {$directory}");
        }
        file_put_contents($irExpectedFile, $irNormalized);
    } elseif ($irDocument !== null && !is_file($irExpectedFile)) {
        $ok = false;
        fwrite(STDERR, "{$relative}: expected IR is missing at {$irExpectedFile}\n");
    } elseif ($irDocument !== null && file_get_contents($irExpectedFile) !== $irNormalized) {
        $ok = false;
        fwrite(STDERR, "{$relative}: IR does not match {$irExpectedFile}\n");
        show_diff((string) file_get_contents($irExpectedFile), $irNormalized);
    }

    if ($ok) {
        $again = run_process([
            $php,
            '-n',
            '-d',
            'display_errors=1',
            '-d',
            'zend_extension=' . $extension,
            '-l',
            $fixture,
        ]);
        $second = is_file($irFile) ? (string) file_get_contents($irFile) : '';
        if ($again['exit'] !== 0 || $second !== $irRaw) {
            $ok = false;
            fwrite(STDERR, "{$relative}: IR output is not deterministic\n");
        }
    }

    putenv('REPHPER_IR');
    @unlink($irFile);

    if (!$ok) {
        if ($result['exit'] !== 0 || !str_contains($result['stdout'], 'No syntax errors detected')) {
            fwrite(STDERR, "{$relative}: compilation failed (exit {$result['exit']})\n");
            fwrite(STDERR, "stdout:\n{$result['stdout']}\n");
            fwrite(STDERR, "stderr:\n{$dump}\n");
        }
        $failures++;
        echo "FAIL {$relative}\n";
        continue;
    }

    echo "ok   {$relative}\n";
}

$blockedDir = sys_get_temp_dir() . '/rephper-ir-dir-' . getmypid();
if (!mkdir($blockedDir, 0700, true) && !is_dir($blockedDir)) {
    fail("Unable to create {$blockedDir}");
}
putenv('REPHPER_IR=' . $blockedDir);
$blocked = run_process([
    $php,
    '-n',
    '-d',
    'zend_extension=' . $extension,
    '-l',
    $root . '/tests/Fixtures/Common/Arithmetic.php',
]);
putenv('REPHPER_IR');
$blockedOk = $blocked['exit'] === 0
    && is_dir($blockedDir)
    && str_contains($blocked['stderr'], 'IR path is not a regular file');
if (!$blockedOk) {
    fwrite(STDERR, "IR export replaced or ignored a non-file path (exit {$blocked['exit']}).\n{$blocked['stderr']}\n");
    $failures++;
    echo "FAIL IR path must be a regular file\n";
} else {
    echo "ok   IR export refuses a directory path\n";
}
@rmdir($blockedDir);

$probeDir = sys_get_temp_dir() . '/rephper-probe-' . getmypid();
if (!mkdir($probeDir, 0700, true) && !is_dir($probeDir)) {
    fail("Unable to create {$probeDir}");
}

try {
    file_put_contents($probeDir . '/other.php', "<?php\nfunction rephper_other(): int { return 2; }\n");
    file_put_contents(
        $probeDir . '/main.php',
        "<?php\nrequire __DIR__ . '/other.php';\nexit(7);\nfunction rephper_main(): int { return 1; }\n"
    );
    $probe = run_process([
        $php,
        '-n',
        '-d',
        'zend_extension=' . $extension,
        '-l',
        $probeDir . '/main.php',
    ]);
    $probeOk = $probe['exit'] === 0
        && str_contains($probe['stdout'], 'No syntax errors detected')
        && str_contains($probe['stderr'], 'Function: rephper_main')
        && str_contains($probe['stderr'], 'Kind: main')
        && str_contains($probe['stderr'], 'INCLUDE_OR_EVAL')
        && !str_contains($probe['stderr'], 'Function: rephper_other');
    if (!$probeOk) {
        fwrite(STDERR, "Compilation-only probe failed (exit {$probe['exit']}).\n");
        fwrite(STDERR, "stdout:\n{$probe['stdout']}\n");
        fwrite(STDERR, "stderr:\n{$probe['stderr']}\n");
        $failures++;
        echo "FAIL compilation-only probe\n";
    } else {
        echo "ok   compilation does not execute the file or compile required files\n";
    }
} finally {
    @unlink($probeDir . '/main.php');
    @unlink($probeDir . '/other.php');
    @rmdir($probeDir);
}

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}

echo count($fixtures) . " fixtures passed\n";
