<?php

declare(strict_types=1);

namespace Rephper\Application;

use Rephper\CodeGen\FunctionDeclaration;
use Rephper\CodeGen\FunctionRenderer;
use Rephper\CodeGen\NameQualifier;
use Rephper\CodeGen\PhpSourceGenerator;
use Rephper\CodeGen\SourceGenerationException;
use Rephper\Expr\Diagnostic;
use Rephper\Ir\Document;
use Rephper\Ir\NamespaceRegion;
use Rephper\Ir\OpArray;
use Rephper\Stmt\Statement;
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
 * A file with namespace regions keeps that region order. Each region's
 * functions come first, then its statements. One named region is an
 * unbracketed namespace. Several regions use bracketed blocks, including a
 * global region beside a named one. Import aliases are not in the IR.
 *
 * A class, interface, trait, enum, method, closure, property hook,
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

    private const NAMESPACE_NAME = '/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z/';

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
            $this->fail($class->kind . ' ' . $class->name . ' is not supported');
        }

        [$functions, $main] = $this->partition($document);
        if ($this->usesNamespaces($document)) {
            return $this->file($this->namespaceParts($document, $functions, $main));
        }

        return $this->file($this->globalParts($document, $functions, $main));
    }

    /**
     * @param list<OpArray> $functions
     * @return list<string>
     */
    private function globalParts(Document $document, array $functions, OpArray $main): array
    {
        $strict = $this->strictTypes($main, $functions);
        $chunks = [];
        foreach ($functions as $opArray) {
            $chunks[] = $this->renderFunction($document, $opArray, 0, null, null);
        }
        $body = $this->renderMain($document, $main, 0, null);
        if ($body !== '') {
            $chunks[] = $body;
        }
        if ($strict) {
            array_unshift($chunks, 'declare(strict_types=1);');
        }

        return $chunks;
    }

    /**
     * @param list<OpArray> $functions
     * @return list<string>
     */
    private function namespaceParts(Document $document, array $functions, OpArray $main): array
    {
        if ($document->namespaces === []) {
            $name = $this->recordedNamespace($document);
            $this->fail('namespace ' . $name . ' is not recorded as a namespace region');
        }
        foreach ($document->namespaces as $region) {
            if ($region->name !== null && preg_match(self::NAMESPACE_NAME, $region->name) !== 1) {
                $this->fail('namespace ' . $region->name . ' cannot be rendered');
            }
        }

        $grouped = $this->assignFunctions($document, $functions);
        $this->rejectDeclarations($main);
        $structured = $this->pipeline->structure($document, $main);
        $this->assertReconstructed($structured, 'the main script');
        $statements = $this->assignStatements($document->namespaces, $structured->statements);
        $strict = $this->strictTypes($main, $functions);
        $braced = count($document->namespaces) > 1;
        $parts = [];
        if ($strict) {
            $parts[] = 'declare(strict_types=1);';
        }
        foreach ($document->namespaces as $index => $region) {
            $names = $region->name === null ? null : new NameQualifier($region->name);
            $indent = $braced ? 1 : 0;
            $chunks = [];
            foreach ($grouped[$index] as $opArray) {
                $chunks[] = $this->renderFunction($document, $opArray, $indent, $this->declaredName($opArray, $region), $names);
            }
            $body = $this->renderStatements($statements[$index], $indent, $names);
            if ($body !== '') {
                $chunks[] = $body;
            }
            if (!$braced) {
                if ($region->name !== null) {
                    $parts[] = 'namespace ' . $region->name . ';';
                }
                foreach ($chunks as $chunk) {
                    $parts[] = $chunk;
                }
                continue;
            }
            $parts[] = $this->bracedRegion($region, implode("\n\n", $chunks));
        }

        return $parts;
    }

    /**
     * @return array{list<OpArray>, OpArray}
     */
    private function partition(Document $document): array
    {
        $functions = [];
        $main = null;
        $seen = [];
        foreach ($document->opArrays as $opArray) {
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

        return [$functions, $main];
    }

    private function usesNamespaces(Document $document): bool
    {
        if ($document->namespaces !== []) {
            return true;
        }
        foreach ($document->opArrays as $opArray) {
            if ($opArray->namespace !== null || (is_string($opArray->name) && str_contains($opArray->name, '\\'))) {
                return true;
            }
        }

        return false;
    }

    private function recordedNamespace(Document $document): string
    {
        foreach ($document->opArrays as $opArray) {
            if ($opArray->namespace !== null) {
                return $opArray->namespace;
            }
            if (is_string($opArray->name) && str_contains($opArray->name, '\\')) {
                $parts = explode('\\', $opArray->name);
                array_pop($parts);

                return implode('\\', $parts);
            }
        }

        return '(unknown)';
    }

    /**
     * @param list<OpArray> $functions
     * @return list<list<OpArray>>
     */
    private function assignFunctions(Document $document, array $functions): array
    {
        $expected = [];
        foreach ($document->namespaces as $index => $region) {
            foreach ($region->functions as $name) {
                $expected[] = ['index' => $index, 'name' => $name];
            }
        }
        $assigned = [];
        foreach ($document->namespaces as $index => $region) {
            $assigned[$index] = [];
        }
        $cursor = 0;
        foreach ($functions as $opArray) {
            $slot = $expected[$cursor] ?? null;
            if ($slot === null) {
                $this->fail('function ' . $opArray->name . ' is not listed in a namespace region');
            }
            $region = $document->namespaces[$slot['index']];
            $declared = $this->declaredName($opArray, $region);
            if ($declared !== $slot['name']) {
                $this->fail($this->regionLabel($region) . ' lists function ' . $slot['name'] . ', but the next function is ' . $opArray->name);
            }
            if ($opArray->namespace !== $region->name) {
                $recorded = $opArray->namespace === null ? 'the global namespace' : 'namespace ' . $opArray->namespace;
                $this->fail('function ' . $opArray->name . ' records ' . $recorded . ', which does not match ' . $this->regionLabel($region));
            }
            $assigned[$slot['index']][] = $opArray;
            $cursor++;
        }
        if (isset($expected[$cursor])) {
            $slot = $expected[$cursor];
            $region = $document->namespaces[$slot['index']];
            $this->fail($this->regionLabel($region) . ' lists function ' . $slot['name'] . ', which is not in the IR');
        }

        return $assigned;
    }

    /**
     * @param list<NamespaceRegion> $regions
     * @param list<Statement> $statements
     * @return list<list<Statement>>
     */
    private function assignStatements(array $regions, array $statements): array
    {
        $assigned = [];
        foreach ($regions as $index => $region) {
            $assigned[$index] = [];
        }
        $cursor = 0;
        foreach ($regions as $index => $region) {
            foreach ($region->statements as $lines) {
                $statement = $statements[$cursor] ?? null;
                if (!$statement instanceof Statement) {
                    $this->fail($this->regionLabel($region) . ' is missing a statement at line ' . $lines[0]);
                }
                if (!in_array($statement->line, $lines, true)) {
                    $this->fail($this->regionLabel($region) . ' cannot own the statement at line ' . $statement->line);
                }
                $assigned[$index][] = $statement;
                $cursor++;
            }
        }
        if (isset($statements[$cursor])) {
            $this->fail('the main script has a statement at line ' . $statements[$cursor]->line . ' that is not in a namespace region');
        }

        return $assigned;
    }

    private function declaredName(OpArray $opArray, NamespaceRegion $region): string
    {
        $name = $opArray->name;
        if (!is_string($name) || $name === '') {
            $this->fail('a function op array has no name');
        }
        if ($region->name === null) {
            if (str_contains($name, '\\')) {
                $this->fail('function ' . $name . ' is qualified, but ' . $this->regionLabel($region) . ' is global');
            }

            return $name;
        }
        $prefix = $region->name . '\\';
        if (!str_starts_with($name, $prefix)) {
            $this->fail('function ' . $name . ' is not in ' . $this->regionLabel($region));
        }
        $short = substr($name, strlen($prefix));
        if ($short === '' || str_contains($short, '\\')) {
            $this->fail('function ' . $name . ' is not a single name in ' . $this->regionLabel($region));
        }

        return $short;
    }

    private function regionLabel(NamespaceRegion $region): string
    {
        $name = $region->name === null ? 'the global namespace' : 'namespace ' . $region->name;

        return $name . ' at line ' . $region->lineStart;
    }

    private function renderFunction(
        Document $document,
        OpArray $opArray,
        int $indent,
        ?string $declaredName,
        ?NameQualifier $names,
    ): string {
        $this->rejectDeclarations($opArray);
        $structured = $this->pipeline->structure($document, $opArray);
        $this->assertReconstructed($structured, 'function ' . $opArray->name);

        return $this->functions->render(new FunctionDeclaration($opArray, $structured), $indent, $declaredName, $names);
    }

    private function renderMain(Document $document, OpArray $main, int $indent, ?NameQualifier $names): string
    {
        $this->rejectDeclarations($main);
        $structured = $this->pipeline->structure($document, $main);
        $this->assertReconstructed($structured, 'the main script');

        return $this->renderStatements($structured->statements, $indent, $names);
    }

    /**
     * @param list<Statement> $statements
     */
    private function renderStatements(array $statements, int $indent, ?NameQualifier $names): string
    {
        $source = $names === null ? $this->source : new PhpSourceGenerator($names);

        return $source->renderStatements($statements, $indent);
    }

    private function bracedRegion(NamespaceRegion $region, string $body): string
    {
        $header = 'namespace' . ($region->name === null ? '' : ' ' . $region->name) . ' {';
        if ($body === '') {
            return $header . "\n}";
        }

        return $header . "\n" . $body . "\n}";
    }

    /**
     * @param list<string> $parts
     */
    private function file(array $parts): string
    {
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
