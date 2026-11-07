<?php

declare(strict_types=1);

namespace Rephper\Ir;

/**
 * Reads IR JSON produced by the Rephper Zend extension.
 *
 * The reader does not build a control-flow graph and does not load the extension.
 * Unknown object keys are kept. Missing required keys and wrong types throw.
 */
final class Reader
{
    private const KNOWN_LITERAL_TYPES = [
        'null',
        'bool',
        'int',
        'float',
        'string',
        'array',
        'constant_ast',
        'undef',
        'unsupported',
    ];

    private const OPERAND_KINDS = [
        'unused',
        'this',
        'const',
        'tmp',
        'var',
        'cv',
        'jump',
        'try_catch',
        'level',
        'label',
        'unknown',
    ];

    public function readFile(string $path): Document
    {
        if (!is_file($path)) {
            throw new IrException('IR file does not exist: ' . $path);
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new IrException('IR file could not be read: ' . $path);
        }

        return $this->readString($json);
    }

    public function readString(string $json): Document
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new IrException('IR is not valid JSON: ' . $exception->getMessage(), 0, $exception);
        }

        if (!$this->isObject($decoded)) {
            throw new IrException('IR document must be a JSON object');
        }

        return $this->document($decoded);
    }

    /**
     * @param array<mixed> $data
     */
    private function document(array $data): Document
    {
        $version = $this->int($data, 'ir_version', 'ir_version');
        if ($version !== 1) {
            throw new UnsupportedIrVersionException('Unsupported IR version ' . $version . '. This reader accepts version 1.');
        }

        $php = $this->object($data, 'php', 'php');
        $compilation = $this->object($data, 'compilation', 'compilation');
        $opArrays = $this->list($data, 'op_arrays', 'op_arrays');
        $classes = $this->list($data, 'classes', 'classes');

        $parsed = [];
        foreach ($opArrays as $index => $opArray) {
            if (!$this->isObject($opArray)) {
                throw new IrException('op_arrays[' . $index . '] must be an object');
            }
            $parsed[] = $this->opArray($opArray, 'op_arrays[' . $index . ']');
        }

        $classRows = [];
        foreach ($classes as $index => $class) {
            if (!$this->isObject($class)) {
                throw new IrException('classes[' . $index . '] must be an object');
            }
            $classRows[] = $class;
        }

        return new Document(
            $version,
            new PhpBuild(
                $this->string($php, 'version', 'php.version'),
                $this->int($php, 'version_id', 'php.version_id'),
                $this->int($php, 'api', 'php.api'),
                $this->int($php, 'zend_module_api', 'php.zend_module_api'),
                $this->int($php, 'zend_extension_api', 'php.zend_extension_api'),
                $this->bool($php, 'zts', 'php.zts'),
                $this->bool($php, 'debug', 'php.debug'),
            ),
            new Compilation(
                $this->string($compilation, 'stage', 'compilation.stage'),
                $this->string($compilation, 'jumps', 'compilation.jumps'),
            ),
            $parsed,
            $classRows,
            $this->extra($data, ['ir_version', 'php', 'compilation', 'op_arrays', 'classes']),
        );
    }

    /**
     * @param array<mixed> $data
     */
    private function opArray(array $data, string $path): OpArray
    {
        $instructions = $this->list($data, 'instructions', $path . '.instructions');
        $parsedInstructions = [];
        foreach ($instructions as $index => $instruction) {
            if (!$this->isObject($instruction)) {
                throw new IrException($path . '.instructions[' . $index . '] must be an object');
            }
            $parsed = $this->instruction($instruction, $path . '.instructions[' . $index . ']');
            if ($parsed->index !== $index) {
                throw new IrException($path . '.instructions[' . $index . '].index must be ' . $index);
            }
            $parsedInstructions[] = $parsed;
        }

        $literals = [];
        foreach ($this->list($data, 'literals', $path . '.literals') as $index => $literal) {
            if (!$this->isObject($literal)) {
                throw new IrException($path . '.literals[' . $index . '] must be an object');
            }
            $literals[] = $this->literal($literal, $path . '.literals[' . $index . ']');
        }

        $tryCatch = [];
        foreach ($this->list($data, 'try_catch', $path . '.try_catch') as $index => $row) {
            if (!$this->isObject($row)) {
                throw new IrException($path . '.try_catch[' . $index . '] must be an object');
            }
            $tryCatch[] = new TryCatch(
                $this->int($row, 'try', $path . '.try_catch[' . $index . '].try'),
                $this->nullableInt($row, 'catch', $path . '.try_catch[' . $index . '].catch'),
                $this->nullableInt($row, 'finally', $path . '.try_catch[' . $index . '].finally'),
                $this->nullableInt($row, 'finally_end', $path . '.try_catch[' . $index . '].finally_end'),
            );
        }

        $breaks = $this->nullableList($data, 'breaks', $path . '.breaks');
        $parsedBreaks = null;
        if ($breaks !== null) {
            $parsedBreaks = [];
            foreach ($breaks as $index => $row) {
                if (!$this->isObject($row)) {
                    throw new IrException($path . '.breaks[' . $index . '] must be an object');
                }
                $breakPath = $path . '.breaks[' . $index . ']';
                $parsedBreaks[] = new BreakContinue(
                    $this->int($row, 'start', $breakPath . '.start'),
                    $this->int($row, 'continue', $breakPath . '.continue'),
                    $this->int($row, 'break', $breakPath . '.break'),
                    $this->int($row, 'parent', $breakPath . '.parent'),
                    $this->bool($row, 'switch', $breakPath . '.switch'),
                );
            }
        }

        $labels = $this->nullableList($data, 'labels', $path . '.labels');
        $parsedLabels = null;
        if ($labels !== null) {
            $parsedLabels = [];
            foreach ($labels as $index => $row) {
                if (!$this->isObject($row)) {
                    throw new IrException($path . '.labels[' . $index . '] must be an object');
                }
                $labelPath = $path . '.labels[' . $index . ']';
                $parsedLabels[] = new Label(
                    $this->nullableString($row, 'name', $labelPath . '.name'),
                    $this->int($row, 'opline', $labelPath . '.opline'),
                    $this->int($row, 'break_continue', $labelPath . '.break_continue'),
                );
            }
        }

        $variables = [];
        foreach ($this->list($data, 'variables', $path . '.variables') as $index => $name) {
            if (!is_string($name)) {
                throw new IrException($path . '.variables[' . $index . '] must be a string');
            }
            $variables[] = $name;
        }

        $flags = [];
        foreach ($this->list($data, 'flags', $path . '.flags') as $index => $flag) {
            if (!is_string($flag)) {
                throw new IrException($path . '.flags[' . $index . '] must be a string');
            }
            $flags[] = $flag;
        }

        $arguments = [];
        foreach ($this->list($data, 'arguments', $path . '.arguments') as $index => $argument) {
            if (!$this->isObject($argument)) {
                throw new IrException($path . '.arguments[' . $index . '] must be an object');
            }
            if (!isset($argument['name']) || !is_string($argument['name'])) {
                throw new IrException($path . '.arguments[' . $index . '].name must be a string');
            }
            $arguments[] = $argument;
        }

        $dynamic = [];
        foreach ($this->list($data, 'dynamic_functions', $path . '.dynamic_functions') as $index => $id) {
            if ($id !== null && !is_int($id)) {
                throw new IrException($path . '.dynamic_functions[' . $index . '] must be an integer or null');
            }
            $dynamic[] = $id;
        }

        $attributes = $this->objectList($data, 'attributes', $path . '.attributes');
        $liveRanges = $this->objectList($data, 'live_ranges', $path . '.live_ranges');

        $static = $this->nullableList($data, 'static_variables', $path . '.static_variables');
        if ($static !== null) {
            foreach ($static as $index => $row) {
                if (!$this->isObject($row)) {
                    throw new IrException($path . '.static_variables[' . $index . '] must be an object');
                }
            }
        }

        $returnType = $data['return_type'] ?? null;
        if (!array_key_exists('return_type', $data)) {
            throw new IrException($path . '.return_type is missing');
        }
        if ($returnType !== null) {
            if (!$this->isObject($returnType)) {
                throw new IrException($path . '.return_type must be an object or null');
            }
            $this->nullableString($returnType, 'display', $path . '.return_type.display');
            $this->bool($returnType, 'nullable', $path . '.return_type.nullable');
            $this->string($returnType, 'kind', $path . '.return_type.kind');
        }

        $namespace = null;
        if (array_key_exists('namespace', $data)) {
            $namespace = $this->nullableString($data, 'namespace', $path . '.namespace');
            if ($namespace === '') {
                throw new IrException($path . '.namespace must not be empty');
            }
        }

        return new OpArray(
            $this->int($data, 'id', $path . '.id'),
            $this->string($data, 'kind', $path . '.kind'),
            $this->nullableString($data, 'name', $path . '.name'),
            $namespace,
            $this->string($data, 'filename', $path . '.filename'),
            $this->int($data, 'line_start', $path . '.line_start'),
            $this->int($data, 'line_end', $path . '.line_end'),
            $this->scope($data, $path),
            $this->propertyHook($data, $path),
            $flags,
            $this->int($data, 'num_args', $path . '.num_args'),
            $this->int($data, 'required_num_args', $path . '.required_num_args'),
            $returnType,
            $arguments,
            $variables,
            $this->int($data, 'temporary_variables', $path . '.temporary_variables'),
            $literals,
            $static,
            $tryCatch,
            $liveRanges,
            $parsedBreaks,
            $parsedLabels,
            $dynamic,
            $attributes,
            $this->nullableString($data, 'doc_comment', $path . '.doc_comment'),
            $parsedInstructions,
            $this->extra($data, [
                'id', 'kind', 'name', 'namespace', 'filename', 'line_start', 'line_end', 'scope', 'property_hook',
                'flags', 'num_args', 'required_num_args', 'return_type', 'arguments', 'variables',
                'temporary_variables', 'literals', 'static_variables', 'try_catch', 'live_ranges',
                'breaks', 'labels', 'dynamic_functions', 'attributes', 'doc_comment', 'instructions',
            ]),
        );
    }

    /**
     * @param array<mixed> $data
     */
    private function scope(array $data, string $path): ?Scope
    {
        if (!array_key_exists('scope', $data)) {
            throw new IrException($path . '.scope is missing');
        }
        $scope = $data['scope'];
        if ($scope === null) {
            return null;
        }
        if (!$this->isObject($scope)) {
            throw new IrException($path . '.scope must be an object or null');
        }

        return new Scope(
            $this->string($scope, 'name', $path . '.scope.name'),
            $this->string($scope, 'kind', $path . '.scope.kind'),
            $this->extra($scope, ['name', 'kind']),
        );
    }

    /**
     * @param array<mixed> $data
     */
    private function propertyHook(array $data, string $path): ?PropertyHook
    {
        if (!array_key_exists('property_hook', $data)) {
            throw new IrException($path . '.property_hook is missing');
        }
        $hook = $data['property_hook'];
        if ($hook === null) {
            return null;
        }
        if (!$this->isObject($hook)) {
            throw new IrException($path . '.property_hook must be an object or null');
        }

        return new PropertyHook(
            $this->string($hook, 'property', $path . '.property_hook.property'),
            $this->string($hook, 'hook', $path . '.property_hook.hook'),
            $this->extra($hook, ['property', 'hook']),
        );
    }

    /**
     * @param array<mixed> $data
     */
    private function instruction(array $data, string $path): Instruction
    {
        $op1 = $this->object($data, 'op1', $path . '.op1');
        $op2 = $this->object($data, 'op2', $path . '.op2');
        $result = $this->object($data, 'result', $path . '.result');

        return new Instruction(
            $this->int($data, 'index', $path . '.index'),
            $this->string($data, 'opcode', $path . '.opcode'),
            $this->int($data, 'opcode_number', $path . '.opcode_number'),
            $this->int($data, 'line', $path . '.line'),
            $this->operand($op1, $path . '.op1'),
            $this->operand($op2, $path . '.op2'),
            $this->operand($result, $path . '.result'),
            $this->int($data, 'extended_value', $path . '.extended_value'),
            array_key_exists('jump_target', $data)
                ? $this->int($data, 'jump_target', $path . '.jump_target')
                : null,
            $this->extra($data, [
                'index', 'opcode', 'opcode_number', 'line', 'op1', 'op2', 'result',
                'extended_value', 'jump_target',
            ]),
        );
    }

    /**
     * @param array<mixed> $data
     */
    private function operand(array $data, string $path): Operand
    {
        $kind = $this->string($data, 'kind', $path . '.kind');
        if (!in_array($kind, self::OPERAND_KINDS, true)) {
            throw new IrException($path . '.kind is not a known operand kind: ' . $kind);
        }

        $smart = [];
        if (array_key_exists('smart_branch', $data)) {
            if (!is_array($data['smart_branch']) || !array_is_list($data['smart_branch'])) {
                throw new IrException($path . '.smart_branch must be an array');
            }
            foreach ($data['smart_branch'] as $index => $branch) {
                if (!is_string($branch)) {
                    throw new IrException($path . '.smart_branch[' . $index . '] must be a string');
                }
                $smart[] = $branch;
            }
        }

        $known = ['kind', 'smart_branch'];
        $index = null;
        $name = null;
        $target = null;
        $value = null;
        $id = null;
        $jumpTable = false;
        $encoded = null;
        $typeCode = null;

        if ($kind === 'jump') {
            $target = $this->int($data, 'target', $path . '.target');
            $known[] = 'target';
        } elseif ($kind === 'const' || $kind === 'tmp' || $kind === 'var' || $kind === 'try_catch') {
            $index = $this->int($data, 'index', $path . '.index');
            $known[] = 'index';
            if ($kind === 'const' && array_key_exists('jump_table', $data)) {
                $jumpTable = $this->bool($data, 'jump_table', $path . '.jump_table');
                $known[] = 'jump_table';
            }
        } elseif ($kind === 'cv') {
            $index = $this->nullableInt($data, 'index', $path . '.index');
            $known[] = 'index';
            if (array_key_exists('name', $data)) {
                $name = $this->nullableString($data, 'name', $path . '.name');
                $known[] = 'name';
            }
            if (array_key_exists('encoded', $data)) {
                $encoded = $this->int($data, 'encoded', $path . '.encoded');
                $known[] = 'encoded';
            }
        } elseif ($kind === 'level') {
            $value = $this->int($data, 'value', $path . '.value');
            $known[] = 'value';
        } elseif ($kind === 'label') {
            $id = $this->int($data, 'id', $path . '.id');
            $known[] = 'id';
        } elseif ($kind === 'unused') {
            if (array_key_exists('value', $data)) {
                $value = $this->int($data, 'value', $path . '.value');
                $known[] = 'value';
            }
        } elseif ($kind === 'unknown') {
            if (array_key_exists('type', $data)) {
                $typeCode = $this->int($data, 'type', $path . '.type');
                $known[] = 'type';
            }
            if (array_key_exists('value', $data)) {
                $value = $this->int($data, 'value', $path . '.value');
                $known[] = 'value';
            }
        }

        return new Operand($kind, $index, $name, $target, $value, $id, $jumpTable, $smart, $encoded, $typeCode, $this->extra($data, $known));
    }

    /**
     * @param array<mixed> $data
     */
    private function literal(array $data, string $path): Literal
    {
        $type = $this->string($data, 'type', $path . '.type');
        if (!in_array($type, self::KNOWN_LITERAL_TYPES, true)) {
            return new Literal($type, null, false, false, [], null, $data);
        }

        $known = ['type'];
        $value = null;
        $binary = false;
        $recursive = false;
        $elements = [];
        $ast = null;

        if ($type === 'null' || $type === 'undef' || $type === 'unsupported') {
            if (array_key_exists('value', $data)) {
                throw new IrException($path . ' of type ' . $type . ' must not have a value');
            }
        } elseif ($type === 'bool') {
            $value = $this->bool($data, 'value', $path . '.value');
            $known[] = 'value';
        } elseif ($type === 'int') {
            $raw = $this->requireKey($data, 'value', $path . '.value');
            if (!is_int($raw) && !is_string($raw)) {
                throw new IrException($path . '.value must be an integer or a decimal string');
            }
            $value = $raw;
            $known[] = 'value';
        } elseif ($type === 'float') {
            $raw = $this->requireKey($data, 'value', $path . '.value');
            if (!is_int($raw) && !is_float($raw) && !is_string($raw)) {
                throw new IrException($path . '.value must be a number or a string');
            }
            $value = $raw;
            $known[] = 'value';
        } elseif ($type === 'string') {
            $value = $this->string($data, 'value', $path . '.value');
            $known[] = 'value';
            if (array_key_exists('binary', $data)) {
                $binary = $this->bool($data, 'binary', $path . '.binary');
                $known[] = 'binary';
            }
        } elseif ($type === 'array') {
            if (array_key_exists('recursive', $data)) {
                $recursive = $this->bool($data, 'recursive', $path . '.recursive');
                $known[] = 'recursive';
            }
            if (!$recursive) {
                $rows = $this->list($data, 'elements', $path . '.elements');
                $known[] = 'elements';
                foreach ($rows as $index => $row) {
                    if (!$this->isObject($row)) {
                        throw new IrException($path . '.elements[' . $index . '] must be an object');
                    }
                    $key = $this->object($row, 'key', $path . '.elements[' . $index . '].key');
                    $item = $this->object($row, 'value', $path . '.elements[' . $index . '].value');
                    $elements[] = [
                        'key' => $this->literal($key, $path . '.elements[' . $index . '].key'),
                        'value' => $this->literal($item, $path . '.elements[' . $index . '].value'),
                    ];
                }
            }
        } elseif ($type === 'constant_ast') {
            if (!array_key_exists('ast', $data)) {
                throw new IrException($path . '.ast is missing');
            }
            $ast = $data['ast'];
            if ($ast !== null && !$this->isObject($ast)) {
                throw new IrException($path . '.ast must be an object or null');
            }
            $known[] = 'ast';
        }

        return new Literal($type, $value, $binary, $recursive, $elements, $ast, $this->extra($data, $known));
    }

    private function isObject(mixed $value): bool
    {
        // json_decode turns both {} and [] into an empty array. An empty array
        // is accepted as an object; missing keys are then reported individually.
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $known
     * @return array<string, mixed>
     */
    private function extra(array $data, array $known): array
    {
        $extra = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || in_array($key, $known, true)) {
                continue;
            }
            $extra[$key] = $value;
        }

        return $extra;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function object(array $data, string $key, string $path): array
    {
        $value = $this->requireKey($data, $key, $path);
        if (!$this->isObject($value)) {
            throw new IrException($path . ' must be an object');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     * @return list<mixed>
     */
    private function list(array $data, string $key, string $path): array
    {
        $value = $this->requireKey($data, $key, $path);
        if (!is_array($value) || !array_is_list($value)) {
            throw new IrException($path . ' must be an array');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     * @return list<mixed>|null
     */
    private function nullableList(array $data, string $key, string $path): ?array
    {
        $value = $this->requireKey($data, $key, $path);
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new IrException($path . ' must be an array or null');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     * @return list<array<mixed>>
     */
    private function objectList(array $data, string $key, string $path): array
    {
        $rows = [];
        foreach ($this->list($data, $key, $path) as $index => $row) {
            if (!$this->isObject($row)) {
                throw new IrException($path . '[' . $index . '] must be an object');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<mixed> $data
     */
    private function requireKey(array $data, string $key, string $path): mixed
    {
        if (!array_key_exists($key, $data)) {
            throw new IrException($path . ' is missing');
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private function string(array $data, string $key, string $path): string
    {
        $value = $this->requireKey($data, $key, $path);
        if (!is_string($value)) {
            throw new IrException($path . ' must be a string');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function nullableString(array $data, string $key, string $path): ?string
    {
        $value = $this->requireKey($data, $key, $path);
        if ($value !== null && !is_string($value)) {
            throw new IrException($path . ' must be a string or null');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function int(array $data, string $key, string $path): int
    {
        $value = $this->requireKey($data, $key, $path);
        if (!is_int($value)) {
            throw new IrException($path . ' must be an integer');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function nullableInt(array $data, string $key, string $path): ?int
    {
        $value = $this->requireKey($data, $key, $path);
        if ($value !== null && !is_int($value)) {
            throw new IrException($path . ' must be an integer or null');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function bool(array $data, string $key, string $path): bool
    {
        $value = $this->requireKey($data, $key, $path);
        if (!is_bool($value)) {
            throw new IrException($path . ' must be a boolean');
        }

        return $value;
    }
}
