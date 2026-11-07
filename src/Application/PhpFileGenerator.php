<?php

declare(strict_types=1);

namespace Rephper\Application;

use Rephper\CodeGen\FunctionDeclaration;
use Rephper\CodeGen\FunctionRenderer;
use Rephper\CodeGen\PhpSourceGenerator;
use Rephper\CodeGen\SourceGenerationException;
use Rephper\Expr\Diagnostic;
use Rephper\Ir\Document;
use Rephper\Ir\OpArray;
use Rephper\Stmt\StructuredStatements;

/**
 * Generates one PHP file from an IR document.
 *
 * Ordinary top-level functions are emitted in document order, which is the
 * order the compiler produced them. The main script's statements follow
 * those functions. The IR does not record one sequence that interleaves the
 * two, so the original mix of statements and function declarations is not
 * reconstructed. Functions are hoisted by PHP, and the statements keep their
 * order inside the main op array.
 *
 * A class, interface, trait, enum, method, closure, property hook, namespace,
 * conditional or nested function, const, or other unsupported declaration
 * fails the file. Nothing from that document is returned as a partial file.
 */
final class PhpFileGenerator
{
    /** @var array<string, string> */
    private const DECLARATIONS = [
        'DECLARE_FUNCTION' => 'conditional function declaration',
        'DECLARE_CLASS' => 'class declaration',
        'DECLARE_CLASS_DELAYED' => 'class declaration',
        'DECLARE_ANON_CLASS' => 'anonymous class',
        'DECLARE_LAMBDA_FUNCTION' => 'closure',
        'DECLARE_CONST' => 'const declaration',
        'ADD_INTERFACE' => 'interface declaration',
        'ADD_TRAIT' => 'trait use',
        'BIND_TRAITS' => 'trait use',
    ];

    /** @var array<string, string> */
    private const REJECTED_FLAGS = [
        'public' => 'method',
        'protected' => 'method',
        'private' => 'method',
        'static' => 'static function',
        'abstract' => 'abstract function',
        'closure' => 'closure',
        'fake_closure' => 'closure',
        'constructor' => 'constructor',
        'override' => 'method',
        'generator' => 'generator function',
        'uses_this' => 'function that uses $this',
    ];

    public function __construct(
        private readonly OpArrayPipeline $pipeline = new OpArrayPipeline(),
        private readonly PhpSourceGenerator $source = new PhpSourceGenerator(),
        private readonly FunctionRenderer $functions = new FunctionRenderer(),
    ) {
    }

    public function generate(Document $document): string
    {
        if ($document->classes !== []) {
            $class = $document->classes[0];
            $kind = is_array($class) && is_string($class['kind'] ?? null) ? $class['kind'] : 'class';
            $name = is_array($class) && is_string($class['name'] ?? null) ? $class['name'] : '(unknown)';
            $this->fail($kind . ' ' . $name . ' is not supported');
        }

        $functions = [];
        $main = null;
        $seen = [];
        foreach ($document->opArrays as $opArray) {
            if ($opArray->namespace !== null) {
                $this->fail('namespace ' . $opArray->namespace . ' is not supported');
            }
            if ($opArray->kind === 'main') {
                if ($main !== null) {
                    $this->fail('the document has more than one main op array');
                }
                $main = $opArray;
                continue;
            }
            if ($opArray->kind !== 'function') {
                $this->rejectKind($opArray);
            }
            $functions[] = $this->acceptFunction($opArray, $seen);
        }
        if (!$main instanceof OpArray) {
            $this->fail('the document has no main op array');
        }

        $strict = $this->strictTypes($main, $functions);
        $chunks = [];
        foreach ($functions as $opArray) {
            $this->rejectDeclarations($opArray);
            $structured = $this->pipeline->structure($document, $opArray);
            $this->assertReconstructed($structured, 'function ' . $opArray->name);
            $chunks[] = $this->functions->render(new FunctionDeclaration($opArray, $structured));
        }
        $this->rejectDeclarations($main);
        $structured = $this->pipeline->structure($document, $main);
        $this->assertReconstructed($structured, 'the main script');
        $body = $this->source->renderStatements($structured->statements, 0);
        if ($body !== '') {
            $chunks[] = $body;
        }

        $parts = [];
        if ($strict) {
            array_unshift($parts, 'declare(strict_types=1);');
        }
        foreach ($chunks as $chunk) {
            $parts[] = $chunk;
        }
        $source = "<?php\n";
        if ($parts !== []) {
            $source .= "\n" . implode("\n\n", $parts) . "\n";
        }

        return $source;
    }

    /**
     * @param array<string, true> $seen
     */
    private function acceptFunction(OpArray $opArray, array &$seen): OpArray
    {
        if ($opArray->scope !== null || $opArray->propertyHook !== null) {
            $this->rejectKind($opArray);
        }
        $name = $opArray->name;
        if (!is_string($name) || $name === '') {
            $this->fail('a function op array has no name');
        }
        if (str_contains($name, '\\')) {
            $this->fail('function ' . $name . ' is in a namespace, and namespace declarations are not supported');
        }
        if (!in_array('top_level', $opArray->flags, true)) {
            $this->fail('function ' . $name . ' is nested or conditional and is not supported');
        }
        foreach (self::REJECTED_FLAGS as $flag => $kind) {
            if (!in_array($flag, $opArray->flags, true)) {
                continue;
            }
            if ($flag === 'uses_this') {
                $this->fail('function ' . $name . ' uses $this and is not an ordinary function');
            }
            $this->fail($kind . ' ' . $name . ' is not supported');
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            $this->fail('function ' . $name . ' is declared more than once');
        }
        $seen[$key] = true;

        return $opArray;
    }

    private function rejectKind(OpArray $opArray): never
    {
        $name = is_string($opArray->name) ? $opArray->name : '(unknown)';
        if ($opArray->kind === 'method' || $opArray->scope !== null) {
            $scope = $opArray->scope === null ? '(unknown)' : $opArray->scope->name;
            $this->fail('method ' . $scope . '::' . $name . ' is not supported');
        }
        if ($opArray->kind === 'property-hook' || $opArray->propertyHook !== null) {
            $hook = $opArray->propertyHook;
            $label = $hook === null ? $name : $hook->property . '::' . $hook->hook;
            $this->fail('property hook ' . $label . ' is not supported');
        }
        if ($opArray->kind === 'closure') {
            $this->fail('closure ' . $name . ' is not supported');
        }
        if ($opArray->kind === 'eval') {
            $this->fail('eval code is not supported');
        }

        $this->fail('op array ' . $opArray->id . ' of kind ' . $opArray->kind . ' is not supported');
    }

    private function rejectDeclarations(OpArray $opArray): void
    {
        foreach ($opArray->instructions as $instruction) {
            $reason = self::DECLARATIONS[$instruction->opcode] ?? null;
            if ($reason === null) {
                continue;
            }
            $where = $opArray->kind === 'main' ? 'the main script' : 'function ' . $opArray->name;
            $this->fail($reason . ' in ' . $where . ' is not supported');
        }
    }

    /**
     * @param list<OpArray> $functions
     */
    private function strictTypes(OpArray $main, array $functions): bool
    {
        $flags = [in_array('strict_types', $main->flags, true)];
        foreach ($functions as $opArray) {
            $flags[] = in_array('strict_types', $opArray->flags, true);
        }
        if (in_array(true, $flags, true) && in_array(false, $flags, true)) {
            $this->fail('strict_types is not the same on every op array');
        }

        return $flags[0];
    }

    private function assertReconstructed(StructuredStatements $structured, string $where): void
    {
        $diagnostic = $structured->diagnostics[0] ?? null;
        if (!$diagnostic instanceof Diagnostic) {
            return;
        }

        throw new SourceGenerationException(
            'Cannot generate PHP for ' . $where . ': ' . $diagnostic->opcode
            . ' at opline ' . $diagnostic->opline . ', line ' . $diagnostic->line
            . ': ' . $diagnostic->reason . '.'
        );
    }

    private function fail(string $reason): never
    {
        throw new SourceGenerationException('Cannot generate a PHP file: ' . $reason . '.');
    }
}
