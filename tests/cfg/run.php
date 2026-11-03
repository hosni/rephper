<?php

declare(strict_types=1);

/**
 * Read real Rephper IR files and check the control-flow graphs.
 * This runner does not load the Zend extension.
 */

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php. Run composer install from the repository root.\n");
    exit(1);
}

require $autoload;

use Rephper\Cfg\Builder;
use Rephper\Cfg\CfgException;
use Rephper\Cfg\Graph;
use Rephper\Cfg\IncompleteMetadataException;
use Rephper\Cfg\UnsupportedSemanticsException;
use Rephper\Cli\GraphPrinter;
use Rephper\Ir\Document;
use Rephper\Ir\IrException;
use Rephper\Ir\Reader;
use Rephper\Ir\UnsupportedIrVersionException;

$failures = 0;

function fail(string $message): void
{
    global $failures;
    fwrite(STDERR, $message . "\n");
    $failures++;
}

function load_document(string $path): Document
{
    $json = file_get_contents($path);
    if ($json === false) {
        throw new IrException('Cannot read ' . $path);
    }
    $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
    $json = str_replace('"version_id": 0', '"version_id": 80427', $json);

    return (new Reader())->readString($json);
}

/**
 * @return array<string, Graph>
 */
function graphs(string $path): array
{
    $byName = [];
    foreach ((new Builder())->buildDocument(load_document($path)) as $graph) {
        $byName[$graph->name ?? 'main'] = $graph;
    }

    return $byName;
}

function graph(string $path, string $name): Graph
{
    $found = graphs($path);
    if (!isset($found[$name])) {
        throw new RuntimeException($path . ' has no op array ' . $name);
    }

    return $found[$name];
}

function edge_to(Graph $graph, int $start, string $kind, int $targetStart, mixed $case = null): bool
{
    $block = $graph->blockStartingAt($start);
    $target = $graph->blockStartingAt($targetStart);
    if ($block === null || $target === null) {
        return false;
    }
    foreach ($block->successors as $edge) {
        if ($edge->kind === $kind && $edge->to === $target->id && $edge->case === $case) {
            return true;
        }
    }

    return false;
}

function exception_to(Graph $graph, int $start, string $kind, int $targetStart): bool
{
    $block = $graph->blockStartingAt($start);
    $target = $graph->blockStartingAt($targetStart);
    if ($block === null || $target === null) {
        return false;
    }
    foreach ($block->exceptions as $edge) {
        if ($edge->kind === $kind && $edge->to === $target->id) {
            return true;
        }
    }

    return false;
}

function assert_edge(Graph $graph, int $start, string $kind, int $targetStart, mixed $case = null): void
{
    if (!edge_to($graph, $start, $kind, $targetStart, $case)) {
        fail($graph->name . ' block at ' . $start . ' has no ' . $kind . ' edge to ' . $targetStart);
    }
}

function assert_exception(Graph $graph, int $start, string $kind, int $targetStart): void
{
    if (!exception_to($graph, $start, $kind, $targetStart)) {
        fail($graph->name . ' block at ' . $start . ' has no ' . $kind . ' exception edge to ' . $targetStart);
    }
}

function assert_covered(Graph $graph, int $count): void
{
    $seen = [];
    foreach ($graph->blocks as $block) {
        if ($block->end <= $block->start) {
            fail($graph->name . ' block ' . $block->id . ' is not a half-open range');
        }
        foreach ($block->instructions as $instruction) {
            if ($instruction->index < $block->start || $instruction->index >= $block->end) {
                fail($graph->name . ' instruction ' . $instruction->index . ' is outside ' . $block->id);
            }
            $seen[$instruction->index] = true;
        }
        foreach ($block->successors as $edge) {
            $target = null;
            foreach ($graph->blocks as $candidate) {
                if ($candidate->id === $edge->to) {
                    $target = $candidate;
                    break;
                }
            }
            if ($target === null || !in_array($block->id, $target->predecessors, true)) {
                fail($graph->name . ' successor ' . $edge->to . ' does not list predecessor ' . $block->id);
            }
        }
    }
    if (count($seen) !== $count) {
        fail($graph->name . ' blocks cover ' . count($seen) . ' instructions, op array has ' . $count);
    }
}

$root = dirname(__DIR__, 2);
$expected = $root . '/tests/Expected/PHP/8.4';

$reader = new Reader();
try {
    $raw = $reader->readFile($expected . '/Common/Arithmetic.json');
    if ($raw->irVersion !== 1 || $raw->php->version !== 'PHP_VERSION' || $raw->php->api !== 20240924) {
        fail('Arithmetic IR metadata was not preserved');
    }
    if ($raw->compilation->stage !== 'pre_pass_two' || $raw->compilation->jumps !== 'absolute_opline') {
        fail('Arithmetic compilation metadata was not preserved');
    }
    $calculate = $raw->opArrayNamed('calculate');
    if ($calculate === null || $calculate->instructions[2]->opcode !== 'ADD' || $calculate->instructions[2]->op1->kind !== 'cv') {
        fail('Arithmetic ADD operand was not preserved');
    }
} catch (IrException $exception) {
    fail('Reader rejected Arithmetic IR: ' . $exception->getMessage());
}

foreach (['{', '', 'not json'] as $bad) {
    try {
        $reader->readString($bad);
        fail('Invalid JSON was accepted');
    } catch (IrException) {
    }
}

try {
    $reader->readString('{}');
    fail('Empty document was accepted');
} catch (IrException $exception) {
    if (!str_contains($exception->getMessage(), 'ir_version')) {
        fail('Empty document error did not mention ir_version');
    }
}

try {
    $reader->readString('{"ir_version":"1"}');
    fail('String IR version was accepted');
} catch (IrException) {
}

try {
    $reader->readString('{"ir_version":2}');
    fail('IR version 2 was accepted');
} catch (UnsupportedIrVersionException) {
} catch (IrException $exception) {
    fail('IR version 2 raised ' . $exception::class);
}

$arithmetic = graph($expected . '/Common/Arithmetic.json', 'calculate');
assert_covered($arithmetic, 7);
if (count($arithmetic->blocks) !== 2 || $arithmetic->blocks[0]->start !== 0 || $arithmetic->blocks[0]->end !== 5) {
    fail('calculate is not one straight-line block plus the unreachable return');
}
if ($arithmetic->blocks[0]->successors !== [] || $arithmetic->blocks[1]->predecessors !== []) {
    fail('calculate return should end the reachable graph');
}

$classify = graph($expected . '/Common/Conditions.json', 'classify');
assert_covered($classify, 7);
assert_edge($classify, 0, 'true', 3);
assert_edge($classify, 0, 'false', 4);
if ($classify->blockStartingAt(5)?->predecessors !== []) {
    fail('implicit return after classify should be unreachable');
}

$walk = graph($expected . '/Common/ControlFlow.json', 'walk');
assert_covered($walk, 40);
assert_edge($walk, 0, 'true', 5);
assert_edge($walk, 0, 'false', 7);
assert_edge($walk, 5, 'jump', 8);
assert_edge($walk, 18, 'continue', 23);
assert_edge($walk, 21, 'break', 26);
assert_edge($walk, 24, 'true', 16);
assert_edge($walk, 24, 'false', 26);
assert_edge($walk, 29, 'true', 28);
assert_edge($walk, 29, 'false', 31);
assert_edge($walk, 31, 'foreach_exit', 35);
assert_edge($walk, 32, 'fallthrough', 33);
assert_edge($walk, 32, 'foreach_exit', 35);
assert_edge($walk, 33, 'jump', 32);

$loop = graph($expected . '/Common/Loops.json', 'calculateTotal');
assert_edge($loop, 0, 'fallthrough', 3);
assert_edge($loop, 0, 'foreach_exit', 6);
assert_edge($loop, 4, 'jump', 3);

$nested = graph($expected . '/Common/StructuredFlow.json', 'nested');
assert_edge($nested, 0, 'true', 3);
assert_edge($nested, 0, 'false', 8);
assert_edge($nested, 3, 'true', 5);
assert_edge($nested, 3, 'false', 7);

$dowhile = graph($expected . '/Common/StructuredFlow.json', 'dowhile');
assert_edge($dowhile, 2, 'true', 2);
assert_edge($dowhile, 2, 'false', 5);

$nestedLoops = graph($expected . '/Common/StructuredFlow.json', 'nestedLoops');
assert_edge($nestedLoops, 8, 'break', 16);
assert_edge($nestedLoops, 11, 'true', 6);
assert_edge($nestedLoops, 14, 'true', 4);

$choose = graph($expected . '/Common/StructuredFlow.json', 'choose');
assert_edge($choose, 0, 'switch', 9, 'a');
assert_edge($choose, 0, 'switch', 10, 'b');
assert_edge($choose, 0, 'switch', 10, 'c');
assert_edge($choose, 0, 'default', 11);
assert_edge($choose, 0, 'fallthrough', 2);
$switchBlock = $choose->blockStartingAt(0);
$switchKinds = $switchBlock === null ? [] : array_map(static fn ($edge) => $edge->kind, $switchBlock->successors);
if (count(array_filter($switchKinds, static fn (string $kind): bool => $kind === 'fallthrough')) !== 1) {
    fail('SWITCH_STRING should keep one type-mismatch fallthrough');
}

$jump = graph($expected . '/Common/StructuredFlow.json', 'jump');
assert_edge($jump, 3, 'goto', 5);
assert_edge($jump, 0, 'false', 4);
assert_edge($jump, 4, 'fallthrough', 5);

$early = graph($expected . '/Common/StructuredFlow.json', 'early');
assert_edge($early, 0, 'true', 3);
assert_edge($early, 0, 'false', 4);
assert_edge($early, 4, 'true', 6);
assert_edge($early, 4, 'false', 7);

$failGraph = graph($expected . '/Common/StructuredFlow.json', 'fail');
$throw = $failGraph->blockStartingAt(3);
if ($throw === null || $throw->instructions[array_key_last($throw->instructions)]->opcode !== 'THROW' || $throw->successors !== [] || $throw->exceptions !== []) {
    fail('throw outside try should have no successor and no invented handler');
}

$fallback = graph($expected . '/Common/StructuredFlow.json', 'fallback');
assert_edge($fallback, 0, 'not_null', 3);
assert_edge($fallback, 0, 'null', 2);
assert_edge($fallback, 3, 'true', 6);
assert_edge($fallback, 3, 'false', 5);
assert_edge($fallback, 6, 'assert_skip', 15);
assert_edge($fallback, 6, 'fallthrough', 10);

$match = graph($expected . '/PHP/8.0/MatchExpression.json', 'classify');
assert_edge($match, 0, 'switch', 2, 0);
assert_edge($match, 0, 'switch', 4, 1);
assert_edge($match, 0, 'switch', 4, 2);
assert_edge($match, 0, 'default', 6);
$matchBlock = $match->blockStartingAt(0);
foreach ($matchBlock?->successors ?? [] as $edge) {
    if ($edge->kind === 'fallthrough') {
        fail('MATCH must not fall through');
    }
}

$guard = graph($expected . '/Common/Exceptions.json', 'guard');
assert_exception($guard, 1, 'catch', 8);
assert_exception($guard, 3, 'catch', 8);
assert_exception($guard, 8, 'finally', 13);
assert_edge($guard, 8, 'finally_call', 13);
assert_edge($guard, 11, 'finally_call', 13);
assert_edge($guard, 13, 'finally_return', 10);
assert_edge($guard, 13, 'finally_return', 12);
$finally = $guard->blockStartingAt(13);
if ($finally === null || $finally->exceptions !== []) {
    fail('finally body should not dispatch to itself');
}

$caught = graph($expected . '/Common/TryForms.json', 'caught');
assert_exception($caught, 1, 'catch', 8);
assert_edge($caught, 8, 'fallthrough', 9);
assert_edge($caught, 8, 'catch_next', 11);

$finalized = graph($expected . '/Common/TryForms.json', 'finalized');
assert_exception($finalized, 1, 'finally', 13);
assert_edge($finalized, 7, 'finally_call', 13);
if ($finalized->blockStartingAt(1)?->exceptions[0]->kind !== 'finally') {
    fail('try/finally should not invent a catch edge');
}

$wrapped = graph($expected . '/Common/TryForms.json', 'wrapped');
assert_exception($wrapped, 1, 'catch', 8);
assert_exception($wrapped, 8, 'catch', 14);

$minimal = graphs($expected . '/Common/Minimal.json');
if (!isset($minimal['main']) || $minimal['main']->kind !== 'main' || $minimal['main']->blocks === []) {
    fail('minimal script did not produce a main graph');
}

$closureDocument = load_document($expected . '/Common/Closures.json');
$closureGraphs = [];
foreach ((new Builder())->buildDocument($closureDocument) as $candidate) {
    $closureGraphs[$candidate->opArrayId] = $candidate;
    assert_covered($candidate, $closureDocument->opArrays[$candidate->opArrayId]->instructionCount());
}
$sawClosure = false;
foreach ($closureGraphs as $candidate) {
    if ($candidate->kind === 'closure') {
        $sawClosure = true;
    }
}
if (!$sawClosure || !isset(graphs($expected . '/Common/Closures.json')['main'])) {
    fail('closure fixture should contain a closure graph and the main script');
}

$printed = (new GraphPrinter())->print($classify);
$again = (new GraphPrinter())->print(graph($expected . '/Common/Conditions.json', 'classify'));
if ($printed !== $again || !str_contains($printed, 'Block B0 [0, 3)') || !str_contains($printed, 'B1 (true)') || !str_contains($printed, 'B2 (false)')) {
    fail('CFG printer output is not stable or does not show the classify branches');
}

$mutated = json_decode((string) file_get_contents($expected . '/Common/Conditions.json'), true);
$mutated['php']['version'] = '8.4.27';
$mutated['php']['version_id'] = 80427;
$mutated['op_arrays'][0]['instructions'][2]['op2']['target'] = 99;
try {
    (new Builder())->buildDocument($reader->readString((string) json_encode($mutated)));
    fail('Jump target 99 was accepted');
} catch (CfgException $exception) {
    if ($exception instanceof UnsupportedSemanticsException || $exception instanceof IncompleteMetadataException) {
        fail('Out-of-range jump was classified as ' . $exception::class);
    }
}

$mutated['op_arrays'][0]['instructions'][2]['op2']['target'] = 4;
$mutated['op_arrays'][0]['instructions'][0]['opcode'] = 'NOT_A_REAL_OPCODE';
$mutated['op_arrays'][0]['instructions'][0]['opcode_number'] = 250;
try {
    (new Builder())->buildDocument($reader->readString((string) json_encode($mutated)));
    fail('Unknown opcode was accepted');
} catch (UnsupportedSemanticsException) {
}

$mutated['op_arrays'][0]['instructions'][0]['opcode'] = 'USER_OPCODE';
$mutated['op_arrays'][0]['instructions'][0]['opcode_number'] = 150;
try {
    (new Builder())->buildDocument($reader->readString((string) json_encode($mutated)));
    fail('USER_OPCODE was treated as ordinary fallthrough');
} catch (UnsupportedSemanticsException) {
}

$control = json_decode((string) file_get_contents($expected . '/Common/ControlFlow.json'), true);
$control['php']['version'] = '8.4.27';
$control['php']['version_id'] = 80427;
$control['op_arrays'][0]['breaks'] = null;
try {
    (new Builder())->buildDocument($reader->readString((string) json_encode($control)));
    fail('BRK without a breaks table was accepted');
} catch (IncompleteMetadataException) {
}

$guardJson = json_decode((string) file_get_contents($expected . '/Common/Exceptions.json'), true);
$guardJson['php']['version'] = '8.4.27';
$guardJson['php']['version_id'] = 80427;
$guardJson['op_arrays'][0]['try_catch'][0]['catch'] = 1000;
try {
    (new Builder())->buildDocument($reader->readString((string) json_encode($guardJson)));
    fail('Catch opline 1000 was accepted');
} catch (CfgException) {
}

$empty = json_decode((string) file_get_contents($expected . '/Common/Minimal.json'), true);
$empty['php']['version'] = '8.4.27';
$empty['php']['version_id'] = 80427;
foreach ($empty['op_arrays'] as &$opArray) {
    $opArray['instructions'] = [];
}
unset($opArray);
$emptyGraphs = (new Builder())->buildDocument($reader->readString((string) json_encode($empty)));
if ($emptyGraphs === [] || $emptyGraphs[0]->blocks !== []) {
    fail('An op array with no instructions should produce an empty graph');
}

$static = json_decode((string) file_get_contents($expected . '/Common/StructuredFlow.json'), true);
$static['php']['version'] = '8.4.27';
$static['php']['version_id'] = 80427;
$bind = &$static['op_arrays'][7]['instructions'][7];
$bind['opcode'] = 'BIND_INIT_STATIC_OR_JMP';
$bind['opcode_number'] = 203;
$bind['op2'] = ['kind' => 'jump', 'target' => 18];
$staticGraph = null;
foreach ((new Builder())->buildDocument($reader->readString((string) json_encode($static))) as $candidate) {
    if ($candidate->name === 'fallback') {
        $staticGraph = $candidate;
    }
}
if ($staticGraph === null || !edge_to($staticGraph, 6, 'initialized', 18) || !edge_to($staticGraph, 6, 'fallthrough', 8)) {
    fail('BIND_INIT_STATIC_OR_JMP did not jump when initialized and fall through otherwise');
}
unset($bind);

$frameless = json_decode((string) file_get_contents($expected . '/Common/StructuredFlow.json'), true);
$frameless['php']['version'] = '8.4.27';
$frameless['php']['version_id'] = 80427;
$call = &$frameless['op_arrays'][7]['instructions'][10];
$call['opcode'] = 'JMP_FRAMELESS';
$call['opcode_number'] = 208;
$call['op2'] = ['kind' => 'jump', 'target' => 18];
$framelessGraph = null;
foreach ((new Builder())->buildDocument($reader->readString((string) json_encode($frameless))) as $candidate) {
    if ($candidate->name === 'fallback') {
        $framelessGraph = $candidate;
    }
}
if ($framelessGraph === null || !edge_to($framelessGraph, 10, 'frameless', 18) || !edge_to($framelessGraph, 10, 'fallthrough', 11)) {
    fail('JMP_FRAMELESS did not model a hit as a jump and a miss as fallthrough');
}

$cliFile = tempnam(sys_get_temp_dir(), 'rephper-cfg-');
if ($cliFile === false) {
    fail('Unable to create a CLI fixture');
} else {
    $cliJson = (string) file_get_contents($expected . '/Common/Conditions.json');
    $cliJson = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $cliJson);
    $cliJson = str_replace('"version_id": 0', '"version_id": 80427', $cliJson);
    file_put_contents($cliFile, $cliJson);
    $command = [PHP_BINARY, '-n', $root . '/bin/rephper', 'graph', $cliFile, '--op-array=classify'];
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || !is_string($stdout) || !str_contains($stdout, 'OpArray #0 classify (function)') || !str_contains($stdout, '2: JMPZ')) {
        fail("CLI graph failed.\nexit={$exit}\nstdout={$stdout}\nstderr={$stderr}");
    }
    unlink($cliFile);
}

if ($failures > 0) {
    fwrite(STDERR, $failures . " CFG check(s) failed\n");
    exit(1);
}

fwrite(STDOUT, "CFG checks passed\n");
