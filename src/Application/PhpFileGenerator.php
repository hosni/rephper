<?php

declare(strict_types=1);

namespace Rephper\Application;

use Rephper\CodeGen\ClassRenderer;
use Rephper\CodeGen\FunctionDeclaration;
use Rephper\CodeGen\FunctionRenderer;
use Rephper\CodeGen\NameQualifier;
use Rephper\CodeGen\PhpSourceGenerator;
use Rephper\CodeGen\PropertyHookDeclaration;
use Rephper\CodeGen\SourceGenerationException;
use Rephper\Expr\Diagnostic;
use Rephper\Ir\ClassDeclaration;
use Rephper\Ir\Document;
use Rephper\Ir\Instruction;
use Rephper\Ir\NamespaceRegion;
use Rephper\Ir\OpArray;
use Rephper\Stmt\OpaqueStatement;
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
 * Ordinary classes, interfaces, traits, and enums are emitted from the class
 * rows. Their methods reuse function rendering. A property hook is attached to
 * the property it names and rendered with that property, in op-array order.
 * Anonymous classes, closures, conditional classes, and other unsupported
 * declarations fail the file. Nothing from that document is returned as a
 * partial file.
 *
 * PHP 8.4 does not emit ADD_TRAIT or BIND_TRAITS. Those opcodes were removed
 * when trait binding moved into DECLARE_CLASS. Trait names and adaptations
 * live on the class row. If an IR still contains either opcode, it is compiler
 * registration only when the main script has no branch and the opcode line
 * sits inside one class or trait that lists traits. The instruction is then
 * dropped. A branch, a nested op array, or a line that is not in such a
 * declaration fails the file.
 */
final class PhpFileGenerator
{
    /** @var array<string, string> */
    private const DECLARATIONS = [
        'DECLARE_FUNCTION' => 'conditional function declaration',
        'DECLARE_ANON_CLASS' => 'anonymous class',
        'DECLARE_LAMBDA_FUNCTION' => 'closure',
        'DECLARE_CONST' => 'const declaration',
        'ADD_INTERFACE' => 'interface declaration',
    ];

    /** @var array<string, string> */
    private const CLASS_REGISTRATION = [
        'DECLARE_CLASS' => 'class declaration',
        'DECLARE_CLASS_DELAYED' => 'class declaration',
    ];

    /**
     * Removed from the PHP 8.4 VM. Kept so an IR that still names them is not
     * treated as an executable statement.
     *
     * @var array<string, string>
     */
    private const TRAIT_REGISTRATION = [
        'ADD_TRAIT' => 'trait use',
        'BIND_TRAITS' => 'trait use',
    ];

    /** @var list<string> */
    private const BRANCHES = [
        'JMP', 'JMPZ', 'JMPNZ', 'JMPZ_EX', 'JMPNZ_EX', 'JMP_NULL', 'JMP_SET',
        'COALESCE', 'FE_RESET_R', 'FE_RESET_RW', 'FE_FETCH_R', 'FE_FETCH_RW',
        'SWITCH_LONG', 'SWITCH_STRING', 'MATCH', 'MATCH_ERROR',
        'BRK', 'CONT', 'GOTO', 'FAST_CALL', 'FAST_RET', 'CATCH',
        'ASSERT_CHECK', 'BIND_INIT_STATIC_OR_JMP', 'JMP_FRAMELESS',
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

    private const IDENTIFIER = '/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/';

    /**
     * Oplines rewritten from trait registration to NOP, keyed by op array id.
     *
     * @var array<int, array<int, true>>
     */
    private array $ignoredRegistrations = [];

    public function __construct(
        private readonly OpArrayPipeline $pipeline = new OpArrayPipeline(),
        private readonly PhpSourceGenerator $source = new PhpSourceGenerator(),
        private readonly FunctionRenderer $functions = new FunctionRenderer(),
        private readonly ClassRenderer $classes = new ClassRenderer(),
    ) {
    }

    public function generate(Document $document): string
    {
        $this->ignoredRegistrations = [];
        $document = $this->acceptTraitRegistration($document);
        [$functions, $main, $methods, $hooks] = $this->partition($document);
        $classes = $this->classGroups($document, $methods, $hooks);
        (new HookComposition())->assertValid($classes);
        if ($this->usesNamespaces($document)) {
            return $this->file($this->namespaceParts($document, $functions, $main, $classes));
        }

        return $this->file($this->globalParts($document, $functions, $main, $classes));
    }

    /**
     * @param list<OpArray> $functions
     * @param list<array{ClassDeclaration, list<OpArray>, list<OpArray>}> $classes
     * @return list<string>
     */
    private function globalParts(Document $document, array $functions, OpArray $main, array $classes): array
    {
        $strict = $this->strictTypes($main, $functions, $this->flatMethods($classes), $this->flatHooks($classes));
        $chunks = [];
        foreach ($functions as $opArray) {
            $chunks[] = $this->renderFunction($document, $opArray, 0, null, null);
        }
        foreach ($this->merge($document, $this->ordered($classes), $this->mainStatements($document, $main, $classes), 0, null) as $chunk) {
            $chunks[] = $chunk;
        }
        if ($strict) {
            array_unshift($chunks, 'declare(strict_types=1);');
        }

        return $chunks;
    }

    /**
     * @param list<OpArray> $functions
     * @param list<array{ClassDeclaration, list<OpArray>, list<OpArray>}> $classes
     * @return list<string>
     */
    private function namespaceParts(Document $document, array $functions, OpArray $main, array $classes): array
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
        $groupedClasses = $this->assignClasses($document, $classes);
        $statements = $this->assignStatements($document->namespaces, $this->mainStatements($document, $main, $classes));
        $strict = $this->strictTypes($main, $functions, $this->flatMethods($classes), $this->flatHooks($classes));
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
            foreach ($this->merge($document, $this->ordered($groupedClasses[$index]), $statements[$index], $indent, $region) as $chunk) {
                $chunks[] = $chunk;
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
     * @return array{list<OpArray>, OpArray, list<OpArray>, list<OpArray>}
     */
    private function partition(Document $document): array
    {
        $functions = [];
        $methods = [];
        $hooks = [];
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
            if ($opArray->kind === 'property-hook' || $opArray->propertyHook !== null) {
                $hooks[] = $this->acceptHook($opArray);
                continue;
            }
            if ($opArray->kind === 'method') {
                $methods[] = $opArray;
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

        return [$functions, $main, $methods, $hooks];
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
        foreach ($document->classes as $class) {
            if (str_contains($class->name, '\\')) {
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

    /**
     * @param list<OpArray> $methods
     * @param list<OpArray> $hooks
     * @return list<array{ClassDeclaration, list<OpArray>, list<OpArray>}>
     */
    private function classGroups(Document $document, array $methods, array $hooks): array
    {
        $groups = [];
        $used = [];
        $seen = [];
        foreach ($document->classes as $class) {
            $this->rejectUnsupportedClass($class);
            $key = strtolower($class->name);
            if (isset($seen[$key])) {
                $this->fail($class->kind . ' ' . $class->name . ' is declared more than once');
            }
            $seen[$key] = true;
            $mine = [];
            foreach ($methods as $index => $method) {
                if ($method->scope === null) {
                    $name = is_string($method->name) ? $method->name : '(unknown)';
                    $this->fail('method ' . $name . ' has no class scope');
                }
                if ($method->scope->name === $class->name && $method->scope->kind === $class->kind) {
                    $mine[] = $method;
                    $used[$index] = true;
                }
            }
            $groups[] = [$class, $mine, []];
        }
        foreach ($methods as $index => $method) {
            if (isset($used[$index])) {
                continue;
            }
            $scope = $method->scope === null ? '(unknown)' : $method->scope->name;
            $name = is_string($method->name) ? $method->name : '(unknown)';
            $this->fail('method ' . $scope . '::' . $name . ' does not belong to a class in this file');
        }

        return $this->attachHooks($groups, $hooks);
    }

    /**
     * Anonymous classes have already been rejected. A hook whose scope is not
     * one of the remaining classes cannot be placed, so the file fails.
     *
     * @param list<array{ClassDeclaration, list<OpArray>, list<OpArray>}> $groups
     * @param list<OpArray> $hooks
     * @return list<array{ClassDeclaration, list<OpArray>, list<OpArray>}>
     */
    private function attachHooks(array $groups, array $hooks): array
    {
        $used = [];
        foreach ($groups as $index => $group) {
            $class = $group[0];
            $mine = [];
            foreach ($hooks as $hookIndex => $hook) {
                $scope = $hook->scope;
                if ($scope !== null && $scope->name === $class->name && $scope->kind === $class->kind) {
                    $mine[] = $hook;
                    $used[$hookIndex] = true;
                }
            }
            $groups[$index][2] = $mine;
        }
        foreach ($hooks as $hookIndex => $hook) {
            if (isset($used[$hookIndex])) {
                continue;
            }
            $this->fail('property hook ' . $this->hookLabel($hook) . ' does not belong to a class in this file');
        }

        return $groups;
    }

    private function rejectUnsupportedClass(ClassDeclaration $class): void
    {
        if (in_array('anonymous', $class->flags, true) || str_contains($class->name, "\0")) {
            $this->fail('anonymous class at line ' . $class->lineStart . ' is not supported');
        }
        if (!in_array($class->kind, ['class', 'interface', 'trait', 'enum'], true)) {
            $this->fail($class->kind . ' ' . $class->name . ' is not supported');
        }
    }

    /**
     * @param list<array{ClassDeclaration, list<OpArray>, list<OpArray>}> $classes
     * @return list<OpArray>
     */
    private function flatMethods(array $classes): array
    {
        $methods = [];
        foreach ($classes as $group) {
            foreach ($group[1] as $method) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * @param list<array{ClassDeclaration, list<OpArray>, list<OpArray>}> $classes
     * @return list<OpArray>
     */
    private function flatHooks(array $classes): array
    {
        $hooks = [];
        foreach ($classes as $group) {
            foreach ($group[2] as $hook) {
                $hooks[] = $hook;
            }
        }

        return $hooks;
    }

    /**
     * @param list<array{ClassDeclaration, list<OpArray>, list<OpArray>}> $classes
     * @return list<array{ClassDeclaration, list<OpArray>, list<OpArray>}>
     */
    private function ordered(array $classes): array
    {
        $decorated = [];
        foreach ($classes as $index => $group) {
            $decorated[] = [$group[0]->lineStart, $index, $group];
        }
        usort($decorated, static fn (array $left, array $right): int => $left[0] <=> $right[0] ?: $left[1] <=> $right[1]);
        $ordered = [];
        foreach ($decorated as $row) {
            $ordered[] = $row[2];
        }

        return $ordered;
    }

    /**
     * @param list<array{ClassDeclaration, list<OpArray>, list<OpArray>}> $classes
     * @return list<array{ClassDeclaration, list<OpArray>, list<OpArray>}>
     */
    private function assignClasses(Document $document, array $classes): array
    {
        $assigned = [];
        foreach ($document->namespaces as $index => $region) {
            $assigned[$index] = [];
        }
        foreach ($classes as $group) {
            $class = $group[0];
            $matches = [];
            foreach ($document->namespaces as $index => $region) {
                if ($class->lineStart >= $region->lineStart && $class->lineEnd <= $region->lineEnd) {
                    $matches[] = $index;
                }
            }
            if (count($matches) !== 1) {
                $this->fail($class->kind . ' ' . $class->name . ' is not contained by one namespace region');
            }
            $assigned[$matches[0]][] = $group;
        }

        return $assigned;
    }

    /**
     * @param list<array{ClassDeclaration, list<OpArray>, list<OpArray>}> $classes
     * @return list<Statement>
     */
    private function mainStatements(Document $document, OpArray $main, array $classes): array
    {
        $this->rejectDeclarations($main);
        $this->assertClassRegistration($main, $classes);
        $structured = $this->pipeline->structure($document, $main);
        $this->assertReconstructed($structured, 'the main script', true, $main->id);

        return $this->withoutRegistration($structured->statements, $main->id);
    }

    /**
     * PHP 8.4 binds traits inside DECLARE_CLASS and does not emit these opcodes.
     * A document that still contains them keeps them only as registration for a
     * recorded trait use. Anything else fails before a statement is rendered.
     */
    private function acceptTraitRegistration(Document $document): Document
    {
        $main = null;
        $registrations = [];
        $branches = false;
        foreach ($document->opArrays as $opArray) {
            $mine = [];
            $branched = false;
            foreach ($opArray->instructions as $instruction) {
                if (isset(self::TRAIT_REGISTRATION[$instruction->opcode])) {
                    $mine[] = $instruction;
                    continue;
                }
                if (in_array($instruction->opcode, self::BRANCHES, true)) {
                    $branched = true;
                }
            }
            if ($mine === []) {
                continue;
            }
            if ($opArray->kind !== 'main') {
                $this->fail('trait use in ' . $this->location($opArray) . ' is not supported');
            }
            if ($main instanceof OpArray) {
                $this->fail('the document has more than one main op array');
            }
            $main = $opArray;
            $registrations = $mine;
            $branches = $branched;
        }
        if (!$main instanceof OpArray) {
            return $document;
        }
        if ($branches) {
            $this->fail('conditional trait use is not supported');
        }
        $indexes = [];
        foreach ($registrations as $instruction) {
            if (!$this->traitHost($document->classes, $instruction->line) instanceof ClassDeclaration) {
                $this->fail('trait use at line ' . $instruction->line . ' cannot be associated with a class declaration');
            }
            $indexes[$instruction->index] = true;
        }
        $this->ignoredRegistrations[$main->id] = $indexes;
        $instructions = [];
        foreach ($main->instructions as $instruction) {
            if (!isset($indexes[$instruction->index])) {
                $instructions[] = $instruction;
                continue;
            }
            $instructions[] = new Instruction(
                $instruction->index,
                'NOP',
                0,
                $instruction->line,
                $instruction->op1,
                $instruction->op2,
                $instruction->result,
                $instruction->extendedValue,
                $instruction->jumpTarget,
                $instruction->extra,
            );
        }
        $opArrays = [];
        foreach ($document->opArrays as $opArray) {
            $opArrays[] = $opArray === $main ? $main->withInstructions($instructions) : $opArray;
        }

        return $document->withOpArrays($opArrays);
    }

    /**
     * @param list<ClassDeclaration> $classes
     */
    private function traitHost(array $classes, int $line): ?ClassDeclaration
    {
        $matches = [];
        foreach ($classes as $class) {
            if ($line >= $class->lineStart && $line <= $class->lineEnd) {
                $matches[] = $class;
            }
        }
        if (count($matches) !== 1) {
            return null;
        }
        $class = $matches[0];
        if (($class->kind !== 'class' && $class->kind !== 'trait') || $class->traits === []) {
            return null;
        }

        return $class;
    }

    private function location(OpArray $opArray): string
    {
        $name = is_string($opArray->name) && $opArray->name !== '' ? $opArray->name : '(unknown)';
        if ($opArray->kind === 'property-hook' || $opArray->propertyHook !== null) {
            return 'property hook ' . $this->hookLabel($opArray);
        }
        if ($opArray->kind === 'method') {
            $scope = $opArray->scope === null ? '(unknown)' : $opArray->scope->name;

            return 'method ' . $scope . '::' . $name;
        }
        if ($opArray->kind === 'function') {
            return 'function ' . $name;
        }
        if ($opArray->kind === 'main') {
            return 'the main script';
        }

        return $opArray->kind . ' ' . $name;
    }

    /**
     * @param list<array{ClassDeclaration, list<OpArray>, list<OpArray>}> $classes
     */
    private function assertClassRegistration(OpArray $main, array $classes): void
    {
        $declarations = [];
        $branches = false;
        foreach ($main->instructions as $instruction) {
            if (isset(self::CLASS_REGISTRATION[$instruction->opcode])) {
                $declarations[] = $instruction;
                continue;
            }
            if (in_array($instruction->opcode, self::BRANCHES, true)) {
                $branches = true;
            }
        }
        if ($declarations === []) {
            return;
        }
        if ($branches) {
            $this->fail('conditional class declaration is not supported');
        }
        $lines = [];
        foreach ($classes as $group) {
            $lines[$group[0]->lineStart] = true;
        }
        foreach ($declarations as $instruction) {
            if (!isset($lines[$instruction->line])) {
                $this->fail('class declaration at line ' . $instruction->line . ' is not a class in the IR');
            }
        }
    }

    /**
     * @param list<Statement> $statements
     * @return list<Statement>
     */
    private function withoutRegistration(array $statements, int $opArrayId): array
    {
        $ignored = $this->ignoredRegistrations[$opArrayId] ?? [];
        $kept = [];
        foreach ($statements as $statement) {
            if ($statement instanceof OpaqueStatement && isset(self::CLASS_REGISTRATION[$statement->instruction->opcode])) {
                continue;
            }
            if (isset($ignored[$statement->opline])) {
                continue;
            }
            $kept[] = $statement;
        }

        return $kept;
    }

    /**
     * @param list<array{ClassDeclaration, list<OpArray>, list<OpArray>}> $classes
     * @param list<Statement> $statements
     * @return list<string>
     */
    private function merge(Document $document, array $classes, array $statements, int $indent, ?NamespaceRegion $region): array
    {
        foreach ($classes as $group) {
            $class = $group[0];
            foreach ($statements as $statement) {
                if ($statement->line >= $class->lineStart && $statement->line <= $class->lineEnd) {
                    $this->fail($class->kind . ' ' . $class->name . ' overlaps the statement at line ' . $statement->line);
                }
            }
        }

        $names = $region !== null && $region->name !== null ? new NameQualifier($region->name) : null;
        $chunks = [];
        $classIndex = 0;
        $statementIndex = 0;
        while ($classIndex < count($classes) || $statementIndex < count($statements)) {
            $classLine = isset($classes[$classIndex]) ? $classes[$classIndex][0]->lineStart : PHP_INT_MAX;
            $statementLine = isset($statements[$statementIndex]) ? $statements[$statementIndex]->line : PHP_INT_MAX;
            if ($classLine === $statementLine) {
                $this->fail($classes[$classIndex][0]->kind . ' ' . $classes[$classIndex][0]->name . ' and a statement share line ' . $classLine);
            }
            if ($classLine < $statementLine) {
                [$class, $methods, $hooks] = $classes[$classIndex];
                $chunks[] = $this->renderClass(
                    $document,
                    $class,
                    $methods,
                    $hooks,
                    $indent,
                    $this->declaredClassName($class, $region),
                    $names,
                    $region,
                );
                $classIndex++;
                continue;
            }
            $run = [];
            while ($statementIndex < count($statements) && $statements[$statementIndex]->line < $classLine) {
                $run[] = $statements[$statementIndex];
                $statementIndex++;
            }
            $body = $this->renderStatements($run, $indent, $names);
            if ($body !== '') {
                $chunks[] = $body;
            }
        }

        return $chunks;
    }

    /**
     * @param list<OpArray> $methods
     * @param list<OpArray> $hooks
     */
    private function renderClass(
        Document $document,
        ClassDeclaration $class,
        array $methods,
        array $hooks,
        int $indent,
        string $declaredName,
        ?NameQualifier $names,
        ?NamespaceRegion $region,
    ): string {
        $expected = $region?->name;
        $rendered = [];
        foreach ($methods as $opArray) {
            $this->rejectDeclarations($opArray);
            if ($opArray->namespace !== $expected) {
                $recorded = $opArray->namespace === null ? 'the global namespace' : 'namespace ' . $opArray->namespace;
                $where = $region === null ? 'the file has no namespace region' : $this->regionLabel($region);
                $this->fail('method ' . $class->name . '::' . $opArray->name . ' records ' . $recorded . ', which does not match ' . $where);
            }
            $structured = $this->pipeline->structure($document, $opArray);
            $this->assertReconstructed($structured, 'method ' . $class->name . '::' . $opArray->name);
            $rendered[] = new FunctionDeclaration($opArray, $structured);
        }
        $renderedHooks = [];
        foreach ($hooks as $opArray) {
            $label = $this->hookLabel($opArray);
            $this->rejectHookBody($opArray, $label);
            $this->rejectDeclarations($opArray);
            if ($opArray->namespace !== $expected) {
                $recorded = $opArray->namespace === null ? 'the global namespace' : 'namespace ' . $opArray->namespace;
                $where = $region === null ? 'the file has no namespace region' : $this->regionLabel($region);
                $this->fail('property hook ' . $label . ' records ' . $recorded . ', which does not match ' . $where);
            }
            $structured = $this->pipeline->structure($document, $opArray);
            $this->assertReconstructed($structured, 'property hook ' . $class->name . '::$' . $label);
            $renderedHooks[] = new PropertyHookDeclaration($opArray, $structured);
        }

        return $this->classes->render($class, $rendered, $indent, $declaredName, $names, $renderedHooks);
    }

    private function declaredClassName(ClassDeclaration $class, ?NamespaceRegion $region): string
    {
        if ($region === null || $region->name === null) {
            if (str_contains($class->name, '\\')) {
                $where = $region === null ? 'the file has no namespace region' : $this->regionLabel($region);
                $this->fail($class->kind . ' ' . $class->name . ' is qualified, but ' . $where);
            }
            if (preg_match(self::IDENTIFIER, $class->name) !== 1) {
                $this->fail($class->kind . ' ' . $class->name . ' cannot be rendered');
            }

            return $class->name;
        }
        $prefix = $region->name . '\\';
        if (!str_starts_with($class->name, $prefix)) {
            $this->fail($class->kind . ' ' . $class->name . ' is not in ' . $this->regionLabel($region));
        }
        $short = substr($class->name, strlen($prefix));
        if (preg_match(self::IDENTIFIER, $short) !== 1) {
            $this->fail($class->kind . ' ' . $class->name . ' is not a single name in ' . $this->regionLabel($region));
        }

        return $short;
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

    private function acceptHook(OpArray $opArray): OpArray
    {
        $hook = $opArray->propertyHook;
        if ($opArray->kind !== 'property-hook' || $hook === null) {
            if ($hook === null) {
                $name = is_string($opArray->name) && $opArray->name !== '' ? $opArray->name : '(unknown)';
                $this->fail('property hook ' . $name . ' has no hook kind');
            }
            $this->fail('property hook ' . $this->hookLabel($opArray) . ' metadata is inconsistent');
        }
        if ($opArray->scope === null) {
            $this->fail('property hook ' . $this->hookLabel($opArray) . ' has no class scope');
        }
        if ($hook->hook !== 'get' && $hook->hook !== 'set') {
            $name = $hook->property === '' ? '(unknown)' : $hook->property;
            $detail = $hook->hook === '' ? 'has no hook kind' : 'has hook kind ' . $hook->hook . ', which is not supported';
            $this->fail('property hook ' . $name . ' ' . $detail);
        }
        if ($hook->property === '' || preg_match(self::IDENTIFIER, $hook->property) !== 1) {
            $this->fail('property hook ' . $this->hookLabel($opArray) . ' metadata is inconsistent');
        }
        if (!in_array($opArray->scope->kind, ['class', 'interface', 'trait', 'enum'], true)) {
            $this->fail('property hook ' . $this->hookLabel($opArray) . ' metadata is inconsistent');
        }
        if ($opArray->name !== '$' . $hook->property . '::' . $hook->hook) {
            $this->fail('property hook ' . $this->hookLabel($opArray) . ' metadata is inconsistent');
        }

        return $opArray;
    }

    private function hookLabel(OpArray $opArray): string
    {
        $hook = $opArray->propertyHook;
        if ($hook !== null && $hook->property !== '' && $hook->hook !== '') {
            return $hook->property . '::' . $hook->hook;
        }
        if (is_string($opArray->name) && $opArray->name !== '') {
            return $opArray->name;
        }

        return '(unknown)';
    }

    private function rejectHookBody(OpArray $opArray, string $label): void
    {
        if (in_array('generator', $opArray->flags, true)) {
            $this->fail('property hook ' . $label . ' contains a generator, which is not supported');
        }
        if (in_array('closure', $opArray->flags, true) || in_array('fake_closure', $opArray->flags, true)) {
            $this->fail('property hook ' . $label . ' contains a closure, which is not supported');
        }
    }

    private function rejectKind(OpArray $opArray): never
    {
        $name = is_string($opArray->name) ? $opArray->name : '(unknown)';
        if ($opArray->kind === 'property-hook' || $opArray->propertyHook !== null) {
            $hook = $opArray->propertyHook;
            $label = $hook === null ? $name : $hook->property . '::' . $hook->hook;
            $this->fail('property hook ' . $label . ' is not supported');
        }
        if ($opArray->kind === 'method' || $opArray->scope !== null) {
            $scope = $opArray->scope === null ? '(unknown)' : $opArray->scope->name;
            $this->fail('method ' . $scope . '::' . $name . ' is not supported');
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
            if (isset(self::CLASS_REGISTRATION[$instruction->opcode])) {
                if ($opArray->kind !== 'main') {
                    $this->fail(self::CLASS_REGISTRATION[$instruction->opcode] . ' in ' . $this->location($opArray) . ' is not supported');
                }
                continue;
            }
            if (isset(self::TRAIT_REGISTRATION[$instruction->opcode])) {
                $this->fail(self::TRAIT_REGISTRATION[$instruction->opcode] . ' in ' . $this->location($opArray) . ' is not supported');
            }
            $reason = self::DECLARATIONS[$instruction->opcode] ?? null;
            if ($reason === null) {
                continue;
            }
            $where = $opArray->kind === 'main' ? 'the main script' : $this->location($opArray);
            $this->fail($reason . ' in ' . $where . ' is not supported');
        }
    }

    /**
     * @param list<OpArray> $functions
     * @param list<OpArray> $methods
     * @param list<OpArray> $hooks
     */
    private function strictTypes(OpArray $main, array $functions, array $methods, array $hooks): bool
    {
        $flags = [in_array('strict_types', $main->flags, true)];
        foreach ([...$functions, ...$methods, ...$hooks] as $opArray) {
            $flags[] = in_array('strict_types', $opArray->flags, true);
        }
        if (in_array(true, $flags, true) && in_array(false, $flags, true)) {
            $this->fail('strict_types is not the same on every op array');
        }

        return $flags[0];
    }

    private function assertReconstructed(
        StructuredStatements $structured,
        string $where,
        bool $classRegistration = false,
        ?int $opArrayId = null,
    ): void {
        $ignored = $opArrayId === null ? [] : ($this->ignoredRegistrations[$opArrayId] ?? []);
        foreach ($structured->diagnostics as $diagnostic) {
            if ($classRegistration && isset(self::CLASS_REGISTRATION[$diagnostic->opcode])) {
                continue;
            }
            if (isset($ignored[$diagnostic->opline])) {
                continue;
            }
            if (!$diagnostic instanceof Diagnostic) {
                continue;
            }

            throw new SourceGenerationException(
                'Cannot generate PHP for ' . $where . ': ' . $diagnostic->opcode
                . ' at opline ' . $diagnostic->opline . ', line ' . $diagnostic->line
                . ': ' . $diagnostic->reason . '.'
            );
        }
    }

    private function fail(string $reason): never
    {
        throw new SourceGenerationException('Cannot generate a PHP file: ' . $reason . '.');
    }
}
