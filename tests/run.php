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

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
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
 * @return list<string>
 */
function discover_fixtures(string $root, string $release): array
{
    $fixtures = glob($root . '/tests/Fixtures/Common/*.php') ?: [];
    foreach (selectable_versions($release, SUPPORTED_VERSIONS) as $version) {
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
    $relative = substr($fixture, strlen($root . '/tests/Fixtures/'));
    $relative = preg_replace('/\.php$/', '.txt', $relative);
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
    ];
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

$selected = selectable_versions($release, SUPPORTED_VERSIONS);
echo 'Fixture sets: Common, ' . implode(', ', array_map(static fn (string $version): string => 'PHP/' . $version, $selected)) . "\n";

$snippets = required_snippets();
foreach ($fixtures as $fixture) {
    $relative = substr($fixture, strlen($root . '/tests/Fixtures/'));
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
