<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

use Rephper\Expr\Literal as ExprLiteral;
use Rephper\Ir\Literal as IrLiteral;

/**
 * Renders a literal or a constant-expression AST.
 *
 * Scalars and arrays go through ExpressionRenderer. A constant AST is rendered
 * from the exported node. An unknown node is an error, not a substitute value.
 */
final class LiteralRenderer
{
    private ?NameQualifier $names = null;

    private const IDENTIFIER = '/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/';

    private const QUALIFIED_NAME = '/\A\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z/';

    private const PRIMARY = 100;

    /**
     * @var array<string, array{0: string, 1: int, 2: string}>
     */
    private const OPERATORS = [
        'ADD' => ['+', 50, 'left'],
        'SUB' => ['-', 50, 'left'],
        'MUL' => ['*', 60, 'left'],
        'DIV' => ['/', 60, 'left'],
        'MOD' => ['%', 60, 'left'],
        'CONCAT' => ['.', 50, 'left'],
        'SL' => ['<<', 45, 'left'],
        'SR' => ['>>', 45, 'left'],
        'BW_OR' => ['|', 30, 'left'],
        'BW_AND' => ['&', 40, 'left'],
        'BW_XOR' => ['^', 35, 'left'],
        'POW' => ['**', 70, 'right'],
        'BOOL_XOR' => ['xor', 15, 'left'],
        'IS_IDENTICAL' => ['===', 25, 'none'],
        'IS_NOT_IDENTICAL' => ['!==', 25, 'none'],
        'IS_EQUAL' => ['==', 25, 'none'],
        'IS_NOT_EQUAL' => ['!=', 25, 'none'],
        'IS_SMALLER' => ['<', 28, 'none'],
        'IS_SMALLER_OR_EQUAL' => ['<=', 28, 'none'],
        'SPACESHIP' => ['<=>', 25, 'none'],
        'BOOL_NOT' => ['!', 80, 'right'],
        'BW_NOT' => ['~', 80, 'right'],
        'UNARY_PLUS' => ['+', 80, 'right'],
        'UNARY_MINUS' => ['-', 80, 'right'],
    ];

    public function renderLiteral(IrLiteral $literal, int $line, ?NameQualifier $names = null): string
    {
        $previous = $this->names;
        $this->names = $names;
        try {
            if ($literal->type === 'constant_ast') {
                if (!is_array($literal->ast)) {
                    throw SourceGenerationException::at('constant expression is missing', 0, $line);
                }

                return $this->node($literal->ast, $line)[0];
            }
            if ($literal->type === 'undef' || $literal->type === 'unsupported') {
                throw SourceGenerationException::at($literal->type . ' literals cannot be rendered', 0, $line);
            }

            return (new ExpressionRenderer())->render($this->expression($literal, $line));
        } finally {
            $this->names = $previous;
        }
    }

    /**
     * @param array<mixed> $data
     */
    public function renderStored(array $data, int $line, ?NameQualifier $names = null): string
    {
        return $this->renderLiteral($this->stored($data, $line), $line, $names);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function node(array $node, int $line): array
    {
        if (array_is_list($node)) {
            throw SourceGenerationException::at('constant expression is missing', 0, $line);
        }
        $kind = $node['kind'] ?? null;
        if (!is_string($kind)) {
            throw SourceGenerationException::at('constant expression is missing its kind', 0, $line);
        }

        return match ($kind) {
            'ZVAL' => $this->zval($node, $line),
            'CONSTANT' => $this->constant($node, $line),
            'BINARY_OP' => $this->binary($node, $line),
            'UNARY_OP' => $this->unary($node, $line, $node['attr_opcode'] ?? null),
            'UNARY_PLUS' => $this->unary($node, $line, 'UNARY_PLUS'),
            'UNARY_MINUS' => $this->unary($node, $line, 'UNARY_MINUS'),
            'ARRAY' => $this->arrayNode($node, $line),
            'CLASS_CONST' => $this->classConstant($node, $line),
            'AND' => $this->infix($node, $line, '&&', 20, 'left'),
            'OR' => $this->infix($node, $line, '||', 18, 'left'),
            'COALESCE' => $this->infix($node, $line, '??', 12, 'right'),
            'GREATER' => $this->infix($node, $line, '>', 28, 'none'),
            'GREATER_EQUAL' => $this->infix($node, $line, '>=', 28, 'none'),
            'CONDITIONAL' => $this->conditional($node, $line),
            'DIM' => $this->dim($node, $line),
            default => throw SourceGenerationException::at(
                'constant expression ' . $kind . ' cannot be rendered',
                0,
                $line,
            ),
        };
    }

    /**
     * @param array<mixed> $node
     * @return array{0: string, 1: int}
     */
    private function zval(array $node, int $line): array
    {
        $value = $node['value'] ?? null;
        if (!is_array($value)) {
            throw SourceGenerationException::at('constant value is missing', 0, $line);
        }

        return [$this->renderStored($value, $line), self::PRIMARY];
    }

    /**
     * @param array<mixed> $node
     * @return array{0: string, 1: int}
     */
    private function constant(array $node, int $line): array
    {
        $value = $node['value'] ?? null;
        if (!is_array($value) || ($value['type'] ?? null) !== 'string' || !is_string($value['value'] ?? null)) {
            throw SourceGenerationException::at('constant name is missing', 0, $line);
        }
        $text = $value['value'];
        $attr = $node['attr'] ?? 0;
        if (!is_int($attr)) {
            throw SourceGenerationException::at('constant name cannot be rendered', 0, $line);
        }
        if ($this->names !== null) {
            $qualified = $this->names->constant($text, $attr);
            if ($qualified === null) {
                throw SourceGenerationException::at(
                    'constant ' . $text . ' is not in namespace ' . $this->names->namespace,
                    0,
                    $line,
                );
            }
            $text = $qualified;
        }
        if (preg_match(self::QUALIFIED_NAME, $text) !== 1) {
            throw SourceGenerationException::at('constant name cannot be rendered', 0, $line);
        }

        return [$text, self::PRIMARY];
    }

    /**
     * @param array<mixed> $node
     * @return array{0: string, 1: int}
     */
    private function binary(array $node, int $line): array
    {
        $opcode = $node['attr_opcode'] ?? null;
        if (!is_string($opcode) || !isset(self::OPERATORS[$opcode])) {
            throw SourceGenerationException::at('binary constant expression cannot be rendered', 0, $line);
        }
        [$symbol, $precedence, $associativity] = self::OPERATORS[$opcode];

        return $this->infix($node, $line, $symbol, $precedence, $associativity);
    }

    /**
     * @param array<mixed> $node
     * @return array{0: string, 1: int}
     */
    private function unary(array $node, int $line, mixed $opcode): array
    {
        if (!is_string($opcode) || !isset(self::OPERATORS[$opcode])) {
            throw SourceGenerationException::at('unary constant expression cannot be rendered', 0, $line);
        }
        $children = $this->children($node, $line);
        if (count($children) !== 1 || !is_array($children[0])) {
            throw SourceGenerationException::at('unary constant expression cannot be rendered', 0, $line);
        }
        [$symbol, $precedence] = self::OPERATORS[$opcode];
        [$text, $child] = $this->node($children[0], $line);

        return [$symbol . $this->wrap($text, $child, $precedence, 'right', 'right'), $precedence];
    }

    /**
     * @param array<mixed> $node
     * @return array{0: string, 1: int}
     */
    private function infix(array $node, int $line, string $symbol, int $precedence, string $associativity): array
    {
        $children = $this->children($node, $line);
        if (count($children) !== 2 || !is_array($children[0]) || !is_array($children[1])) {
            throw SourceGenerationException::at('constant expression cannot be rendered', 0, $line);
        }
        [$left, $leftPrecedence] = $this->node($children[0], $line);
        [$right, $rightPrecedence] = $this->node($children[1], $line);
        $text = $this->wrap($left, $leftPrecedence, $precedence, $associativity, 'left')
            . ' ' . $symbol . ' '
            . $this->wrap($right, $rightPrecedence, $precedence, $associativity, 'right');

        return [$text, $precedence];
    }

    /**
     * @param array<mixed> $node
     * @return array{0: string, 1: int}
     */
    private function arrayNode(array $node, int $line): array
    {
        $pieces = [];
        foreach ($this->children($node, $line) as $child) {
            if (!is_array($child) || ($child['kind'] ?? null) !== 'ARRAY_ELEM') {
                throw SourceGenerationException::at('array element cannot be rendered', 0, $line);
            }
            $inner = $this->children($child, $line);
            if ($inner === [] || count($inner) > 2 || !is_array($inner[0])) {
                throw SourceGenerationException::at('array element cannot be rendered', 0, $line);
            }
            $value = $this->node($inner[0], $line)[0];
            if (count($inner) === 1 || $inner[1] === null) {
                $pieces[] = $value;
                continue;
            }
            if (!is_array($inner[1])) {
                throw SourceGenerationException::at('array key cannot be rendered', 0, $line);
            }
            $pieces[] = $this->node($inner[1], $line)[0] . ' => ' . $value;
        }

        return ['[' . implode(', ', $pieces) . ']', self::PRIMARY];
    }

    /**
     * @param array<mixed> $node
     * @return array{0: string, 1: int}
     */
    private function classConstant(array $node, int $line): array
    {
        $children = $this->children($node, $line);
        if (count($children) !== 2) {
            throw SourceGenerationException::at('class constant cannot be rendered', 0, $line);
        }

        $class = $this->nameValue($children[0], $line, true);
        if ($this->names !== null) {
            $class = $this->names->symbol($class);
        }

        return [$class . '::' . $this->nameValue($children[1], $line, false), self::PRIMARY];
    }

    /**
     * @param array<mixed> $node
     * @return array{0: string, 1: int}
     */
    private function conditional(array $node, int $line): array
    {
        $children = $this->children($node, $line);
        if (count($children) !== 3 || !is_array($children[0]) || !is_array($children[2])) {
            throw SourceGenerationException::at('conditional expression cannot be rendered', 0, $line);
        }
        [$condition, $conditionPrecedence] = $this->node($children[0], $line);
        [$otherwise, $otherwisePrecedence] = $this->node($children[2], $line);
        $condition = $this->wrap($condition, $conditionPrecedence, 10, 'left', 'left');
        $otherwise = $this->wrap($otherwise, $otherwisePrecedence, 10, 'right', 'right');
        if ($children[1] === null) {
            return [$condition . ' ?: ' . $otherwise, 10];
        }
        if (!is_array($children[1])) {
            throw SourceGenerationException::at('conditional expression cannot be rendered', 0, $line);
        }
        [$then, $thenPrecedence] = $this->node($children[1], $line);

        return [$condition . ' ? ' . $this->wrap($then, $thenPrecedence, 10, 'none', 'left') . ' : ' . $otherwise, 10];
    }

    /**
     * @param array<mixed> $node
     * @return array{0: string, 1: int}
     */
    private function dim(array $node, int $line): array
    {
        $children = $this->children($node, $line);
        if (count($children) !== 2 || !is_array($children[0]) || !is_array($children[1])) {
            throw SourceGenerationException::at('dimension cannot be rendered', 0, $line);
        }
        [$left, $precedence] = $this->node($children[0], $line);
        $right = $this->node($children[1], $line)[0];

        return [$this->wrap($left, $precedence, self::PRIMARY, 'left', 'left') . '[' . $right . ']', self::PRIMARY];
    }

    private function nameValue(mixed $node, int $line, bool $qualified): string
    {
        if (!is_array($node) || ($node['kind'] ?? null) !== 'ZVAL') {
            throw SourceGenerationException::at('constant name cannot be rendered', 0, $line);
        }
        $value = $node['value'] ?? null;
        if (!is_array($value) || ($value['type'] ?? null) !== 'string' || !is_string($value['value'] ?? null)) {
            throw SourceGenerationException::at('constant name cannot be rendered', 0, $line);
        }
        $pattern = $qualified ? self::QUALIFIED_NAME : self::IDENTIFIER;
        if (preg_match($pattern, $value['value']) !== 1) {
            throw SourceGenerationException::at('constant name cannot be rendered', 0, $line);
        }

        return $value['value'];
    }

    /**
     * @param array<mixed> $node
     * @return list<mixed>
     */
    private function children(array $node, int $line): array
    {
        $children = $node['children'] ?? null;
        if (!is_array($children) || !array_is_list($children)) {
            throw SourceGenerationException::at('constant expression is missing its children', 0, $line);
        }

        return $children;
    }

    private function wrap(string $text, int $child, int $parent, string $associativity, string $side): string
    {
        if ($child > $parent) {
            return $text;
        }
        if (
            $child < $parent
            || $associativity === 'none'
            || ($associativity === 'left' && $side === 'right')
            || ($associativity === 'right' && $side === 'left')
        ) {
            return '(' . $text . ')';
        }

        return $text;
    }

    private function expression(IrLiteral $literal, int $line): ExprLiteral
    {
        return new ExprLiteral(
            0,
            $line,
            $literal->type,
            $literal->value,
            $this->elements($literal, $line),
            $literal->binary,
        );
    }

    /**
     * @return list<array{key: ExprLiteral, value: ExprLiteral}>|null
     */
    private function elements(IrLiteral $literal, int $line): ?array
    {
        if ($literal->type !== 'array') {
            return null;
        }
        if ($literal->recursive) {
            throw SourceGenerationException::at('recursive array cannot be rendered', 0, $line);
        }
        $elements = [];
        foreach ($literal->elements as $element) {
            if (!isset($element['key'], $element['value']) || !$element['key'] instanceof IrLiteral || !$element['value'] instanceof IrLiteral) {
                throw SourceGenerationException::at('array element cannot be rendered', 0, $line);
            }
            $elements[] = [
                'key' => $this->expression($element['key'], $line),
                'value' => $this->expression($element['value'], $line),
            ];
        }

        return $elements;
    }

    /**
     * @param array<mixed> $data
     */
    private function stored(array $data, int $line): IrLiteral
    {
        $type = $data['type'] ?? null;
        if (!is_string($type)) {
            throw SourceGenerationException::at('literal type is missing', 0, $line);
        }
        if ($type === 'null' || $type === 'undef' || $type === 'unsupported') {
            if (array_key_exists('value', $data)) {
                throw SourceGenerationException::at($type . ' literal has a value', 0, $line);
            }

            return new IrLiteral($type, null, false, false, [], null, []);
        }
        if ($type === 'bool') {
            if (!is_bool($data['value'] ?? null)) {
                throw SourceGenerationException::at('bool literal is not a boolean', 0, $line);
            }

            return new IrLiteral($type, $data['value'], false, false, [], null, []);
        }
        if ($type === 'int') {
            $value = $data['value'] ?? null;
            if (!is_int($value) && !is_string($value)) {
                throw SourceGenerationException::at('integer literal cannot be rendered', 0, $line);
            }

            return new IrLiteral($type, $value, false, false, [], null, []);
        }
        if ($type === 'float') {
            $value = $data['value'] ?? null;
            if (!is_int($value) && !is_float($value) && !is_string($value)) {
                throw SourceGenerationException::at('float literal cannot be rendered', 0, $line);
            }

            return new IrLiteral($type, $value, false, false, [], null, []);
        }
        if ($type === 'string') {
            if (!is_string($data['value'] ?? null)) {
                throw SourceGenerationException::at('string literal is not a string', 0, $line);
            }
            $binary = $data['binary'] ?? false;
            if (!is_bool($binary)) {
                throw SourceGenerationException::at('string literal cannot be rendered', 0, $line);
            }

            return new IrLiteral($type, $data['value'], $binary, false, [], null, []);
        }
        if ($type === 'array') {
            $recursive = $data['recursive'] ?? false;
            if (!is_bool($recursive)) {
                throw SourceGenerationException::at('array literal cannot be rendered', 0, $line);
            }
            $elements = [];
            if (!$recursive) {
                $rows = $data['elements'] ?? null;
                if (!is_array($rows) || !array_is_list($rows)) {
                    throw SourceGenerationException::at('array elements are not available', 0, $line);
                }
                foreach ($rows as $row) {
                    if (!is_array($row) || !isset($row['key'], $row['value']) || !is_array($row['key']) || !is_array($row['value'])) {
                        throw SourceGenerationException::at('array element cannot be rendered', 0, $line);
                    }
                    $elements[] = [
                        'key' => $this->stored($row['key'], $line),
                        'value' => $this->stored($row['value'], $line),
                    ];
                }
            }

            return new IrLiteral($type, null, false, $recursive, $elements, null, []);
        }
        if ($type === 'constant_ast') {
            $ast = $data['ast'] ?? null;
            if (!is_array($ast)) {
                throw SourceGenerationException::at('constant expression is missing', 0, $line);
            }

            return new IrLiteral($type, null, false, false, [], $ast, []);
        }

        throw SourceGenerationException::at('literal type ' . $type . ' cannot be rendered', 0, $line);
    }
}
