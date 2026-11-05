<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

use Rephper\Expr\Argument;
use Rephper\Expr\Assignment;
use Rephper\Expr\Binary;
use Rephper\Expr\Expression;
use Rephper\Expr\FunctionCall;
use Rephper\Expr\Literal;
use Rephper\Expr\MatchArm;
use Rephper\Expr\MatchExpression;
use Rephper\Expr\Opaque;
use Rephper\Expr\ReturnValue;
use Rephper\Expr\Unary;
use Rephper\Expr\Unresolved;
use Rephper\Expr\VariableRead;

/**
 * Renders one expression using PHP 8.4 precedence and associativity.
 *
 * The first line has no indent. Continuation lines are indented from the
 * statement that contains the expression. Parentheses are added only when
 * PHP would group the text differently from the tree. A prefix operator is
 * not wrapped around another prefix operator: `~!$a` is `~(!$a)` and
 * `!~$a` is `!(~$a)`.
 */
final class ExpressionRenderer
{
    private const PREC_ASSIGN = 10;

    private const PREC_PRIMARY = 100;

    private const IDENTIFIER = '/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/';

    private const QUALIFIED_NAME = '/\A\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z/';

    private const FLOAT_LITERAL = '/\A-?(?:\d+(?:\.\d+)?)(?:[eE][+-]?\d+)?\z/';

    /**
     * Higher numbers bind more tightly. `+`, `-`, and `.` share a level.
     * Equality and relational operators do not associate.
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const BINARY = [
        '*' => [60, 'left'],
        '/' => [60, 'left'],
        '%' => [60, 'left'],
        '+' => [50, 'left'],
        '-' => [50, 'left'],
        '.' => [50, 'left'],
        '<' => [40, 'none'],
        '<=' => [40, 'none'],
        '==' => [30, 'none'],
        '!=' => [30, 'none'],
        '===' => [30, 'none'],
        '!==' => [30, 'none'],
    ];

    /** @var array<string, int> */
    private const UNARY = [
        '!' => 70,
        '~' => 80,
        'bool' => 80,
    ];

    /** @var list<string> */
    private const COMPOUND = ['+', '-', '*', '/', '%', '.'];

    public function __construct(
        private readonly int $indent = 0,
    ) {
    }

    public function render(
        Expression $expression,
        int $parentPrecedence = 0,
        string $associativity = 'left',
        string $side = 'left',
        bool $parentIsPrefix = false,
    ): string {
        $text = $this->produce($expression);
        if ($parentIsPrefix && $expression instanceof Unary) {
            return $text;
        }

        return $this->parenthesize(
            $text,
            $this->precedence($expression),
            $parentPrecedence,
            $associativity,
            $side,
        );
    }

    private function produce(Expression $expression): string
    {
        if ($expression instanceof Literal) {
            return $this->literal($expression);
        }
        if ($expression instanceof VariableRead) {
            return $this->variable($expression->name, $expression);
        }
        if ($expression instanceof Assignment) {
            return $this->assignment($expression);
        }
        if ($expression instanceof Binary) {
            return $this->binary($expression);
        }
        if ($expression instanceof Unary) {
            return $this->unary($expression);
        }
        if ($expression instanceof FunctionCall) {
            return $this->call($expression);
        }
        if ($expression instanceof MatchExpression) {
            return $this->match($expression);
        }
        if ($expression instanceof Opaque) {
            throw SourceGenerationException::at(
                $expression->reason,
                $expression->opline,
                $expression->line,
                $expression->opcode,
            );
        }
        if ($expression instanceof Unresolved) {
            throw new SourceGenerationException(
                'Cannot generate PHP for unresolved ' . $expression->kind . ' ' . $expression->index
                . ' at opline ' . $expression->opline . ', line ' . $expression->line
                . ': ' . $expression->reason . '.',
                $expression->opline,
                $expression->line,
            );
        }
        if ($expression instanceof ReturnValue) {
            throw SourceGenerationException::at(
                'return is a statement',
                $expression->opline,
                $expression->line,
            );
        }

        throw SourceGenerationException::at(
            'expression has no PHP representation',
            $expression->opline,
            $expression->line,
        );
    }

    private function precedence(Expression $expression): int
    {
        if ($expression instanceof Binary) {
            return self::BINARY[$expression->operator][0] ?? self::PREC_ASSIGN;
        }
        if ($expression instanceof Unary) {
            return self::UNARY[$expression->operator] ?? self::PREC_ASSIGN;
        }
        if ($expression instanceof Assignment) {
            return self::PREC_ASSIGN;
        }

        return self::PREC_PRIMARY;
    }

    private function parenthesize(
        string $text,
        int $child,
        int $parent,
        string $associativity,
        string $side,
    ): string {
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

    private function literal(Literal $literal): string
    {
        return match ($literal->type) {
            'null' => $this->nullLiteral($literal),
            'bool' => $this->boolLiteral($literal),
            'int' => $this->intLiteral($literal),
            'float' => $this->floatLiteral($literal),
            'string' => $this->stringLiteral($literal),
            'array' => $this->arrayLiteral($literal),
            'constant_ast' => throw SourceGenerationException::at(
                'constant AST literals cannot be rendered',
                $literal->opline,
                $literal->line,
            ),
            'undef' => throw SourceGenerationException::at(
                'undefined literals cannot be rendered',
                $literal->opline,
                $literal->line,
            ),
            'unsupported' => throw SourceGenerationException::at(
                'unsupported literals cannot be rendered',
                $literal->opline,
                $literal->line,
            ),
            default => throw SourceGenerationException::at(
                'literal type ' . $literal->type . ' cannot be rendered',
                $literal->opline,
                $literal->line,
            ),
        };
    }

    private function nullLiteral(Literal $literal): string
    {
        if ($literal->value !== null) {
            throw SourceGenerationException::at('null literal has a value', $literal->opline, $literal->line);
        }

        return 'null';
    }

    private function boolLiteral(Literal $literal): string
    {
        if (!is_bool($literal->value)) {
            throw SourceGenerationException::at('bool literal is not a boolean', $literal->opline, $literal->line);
        }

        return $literal->value ? 'true' : 'false';
    }

    private function intLiteral(Literal $literal): string
    {
        $value = $literal->value;
        if (is_int($value)) {
            return $value === PHP_INT_MIN ? 'PHP_INT_MIN' : (string) $value;
        }
        if (is_string($value) && preg_match('/\A-?(?:0|[1-9]\d*)\z/', $value) === 1) {
            if ($value === (string) PHP_INT_MIN) {
                return 'PHP_INT_MIN';
            }
            if (!$this->fitsPhpInt($value)) {
                throw SourceGenerationException::at(
                    'integer literal cannot be rendered',
                    $literal->opline,
                    $literal->line,
                );
            }

            return $value;
        }

        throw SourceGenerationException::at('integer literal cannot be rendered', $literal->opline, $literal->line);
    }

    private function fitsPhpInt(string $value): bool
    {
        $negative = str_starts_with($value, '-');
        $digits = ltrim($negative ? substr($value, 1) : $value, '0');
        if ($digits === '') {
            return true;
        }
        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (strlen($digits) !== strlen($limit)) {
            return strlen($digits) < strlen($limit);
        }

        return strcmp($digits, $limit) <= 0;
    }

    private function floatLiteral(Literal $literal): string
    {
        $value = $literal->value;
        if (is_string($value)) {
            return match ($value) {
                '-0', '-0.0' => '-0.0',
                'NaN' => 'NAN',
                'Infinity' => 'INF',
                '-Infinity' => '-INF',
                default => throw SourceGenerationException::at(
                    'float literal cannot be rendered',
                    $literal->opline,
                    $literal->line,
                ),
            };
        }
        if (is_int($value)) {
            return $value . '.0';
        }
        if (!is_float($value)) {
            throw SourceGenerationException::at('float literal cannot be rendered', $literal->opline, $literal->line);
        }
        if (is_nan($value)) {
            return 'NAN';
        }
        if ($value === INF) {
            return 'INF';
        }
        if ($value === -INF) {
            return '-INF';
        }
        if ($value === 0.0) {
            return (string) $value === '-0' ? '-0.0' : '0.0';
        }

        try {
            $text = json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw SourceGenerationException::at('float literal cannot be rendered', $literal->opline, $literal->line);
        }
        if (preg_match('/\A-?\d+\z/', $text) === 1) {
            $text .= '.0';
        }
        if (preg_match(self::FLOAT_LITERAL, $text) !== 1) {
            throw SourceGenerationException::at('float literal cannot be rendered', $literal->opline, $literal->line);
        }

        return $text;
    }

    private function stringLiteral(Literal $literal): string
    {
        if (!is_string($literal->value)) {
            throw SourceGenerationException::at('string literal is not a string', $literal->opline, $literal->line);
        }

        $value = $literal->value;
        if ($literal->binary) {
            $value = $this->binaryBytes($value, $literal);
        }

        return $this->string($value, $literal->binary);
    }

    private function binaryBytes(string $value, Literal $literal): string
    {
        $bytes = '';
        $length = strlen($value);
        $index = 0;
        while ($index < $length) {
            $size = $this->utf8Sequence($value, $index);
            if ($size === 0) {
                throw SourceGenerationException::at(
                    'binary string cannot be rendered',
                    $literal->opline,
                    $literal->line,
                );
            }
            $code = $this->codePoint($value, $index, $size);
            if ($code > 0xFF) {
                throw SourceGenerationException::at(
                    'binary string cannot be rendered',
                    $literal->opline,
                    $literal->line,
                );
            }
            $bytes .= chr($code);
            $index += $size;
        }

        return $bytes;
    }

    private function codePoint(string $value, int $index, int $size): int
    {
        $lead = ord($value[$index]);
        if ($size === 1) {
            return $lead;
        }
        $code = match ($size) {
            2 => $lead & 0x1F,
            3 => $lead & 0x0F,
            default => $lead & 0x07,
        };
        for ($offset = 1; $offset < $size; $offset++) {
            $code = ($code << 6) | (ord($value[$index + $offset]) & 0x3F);
        }

        return $code;
    }

    private function string(string $value, bool $binary): string
    {
        if ($value === '') {
            return "''";
        }
        if ($binary || !$this->isUtf8($value)) {
            return $this->hexadecimal($value);
        }
        if ($this->needsDoubleQuotes($value)) {
            return $this->doubleQuoted($value);
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    private function needsDoubleQuotes(string $value): bool
    {
        $length = strlen($value);
        for ($index = 0; $index < $length; $index++) {
            $ordinal = ord($value[$index]);
            if ($ordinal < 0x20 || $ordinal === 0x7F) {
                return true;
            }
        }

        return false;
    }

    private function doubleQuoted(string $value): string
    {
        $encoded = '"';
        $length = strlen($value);
        for ($index = 0; $index < $length; $index++) {
            $byte = $value[$index];
            $encoded .= match ($byte) {
                '\\' => '\\\\',
                '"' => '\\"',
                '$' => '\\$',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                default => $this->doubleQuotedByte($byte),
            };
        }

        return $encoded . '"';
    }

    private function doubleQuotedByte(string $byte): string
    {
        $ordinal = ord($byte);
        if ($ordinal < 0x20 || $ordinal === 0x7F) {
            return sprintf('\\x%02x', $ordinal);
        }

        return $byte;
    }

    private function hexadecimal(string $value): string
    {
        $encoded = '"';
        $length = strlen($value);
        for ($index = 0; $index < $length; $index++) {
            $encoded .= sprintf('\\x%02x', ord($value[$index]));
        }

        return $encoded . '"';
    }

    private function isUtf8(string $value): bool
    {
        $length = strlen($value);
        $index = 0;
        while ($index < $length) {
            $size = $this->utf8Sequence($value, $index);
            if ($size === 0) {
                return false;
            }
            $index += $size;
        }

        return true;
    }

    private function utf8Sequence(string $value, int $index): int
    {
        $available = strlen($value) - $index;
        if ($available <= 0) {
            return 0;
        }
        $lead = ord($value[$index]);
        if ($lead <= 0x7F) {
            return 1;
        }
        if (($lead & 0xE0) === 0xC0) {
            $need = 2;
            $code = $lead & 0x1F;
        } elseif (($lead & 0xF0) === 0xE0) {
            $need = 3;
            $code = $lead & 0x0F;
        } elseif (($lead & 0xF8) === 0xF0) {
            $need = 4;
            $code = $lead & 0x07;
        } else {
            return 0;
        }
        if ($available < $need) {
            return 0;
        }
        for ($offset = 1; $offset < $need; $offset++) {
            $byte = ord($value[$index + $offset]);
            if (($byte & 0xC0) !== 0x80) {
                return 0;
            }
            $code = ($code << 6) | ($byte & 0x3F);
        }
        if ($need === 2 && $code < 0x80) {
            return 0;
        }
        if ($need === 3 && $code < 0x800) {
            return 0;
        }
        if ($need === 4 && $code < 0x10000) {
            return 0;
        }
        if ($code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
            return 0;
        }

        return $need;
    }

    private function arrayLiteral(Literal $literal): string
    {
        if ($literal->elements === null) {
            throw SourceGenerationException::at(
                'array elements are not available',
                $literal->opline,
                $literal->line,
            );
        }

        $pieces = [];
        $keys = [];
        foreach ($literal->elements as $element) {
            if (
                !is_array($element)
                || !isset($element['key'], $element['value'])
                || !$element['key'] instanceof Literal
                || !$element['value'] instanceof Expression
            ) {
                throw SourceGenerationException::at(
                    'array element is not a key and a value',
                    $literal->opline,
                    $literal->line,
                );
            }
            $value = (new self($this->indent + 1))->render($element['value']);
            $implicit = $this->nextImplicitIndex($keys);
            $key = $element['key'];
            if ($key->type === 'int' && is_int($key->value) && $key->value === $implicit) {
                $pieces[] = $value;
            } else {
                $pieces[] = $this->arrayKey($key) . ' => ' . $value;
            }
            $keys[] = $key;
        }
        if (!$this->spansLines($pieces)) {
            return '[' . implode(', ', $pieces) . ']';
        }

        $lines = ['['];
        $pad = $this->pad($this->indent + 1);
        foreach ($pieces as $piece) {
            $pieceLines = explode("\n", $piece);
            $pieceLines[0] = $pad . $pieceLines[0];
            $pieceLines[count($pieceLines) - 1] .= ',';
            $lines[] = implode("\n", $pieceLines);
        }
        $lines[] = $this->pad($this->indent) . ']';

        return implode("\n", $lines);
    }

    /**
     * @param list<Literal> $keys
     */
    private function nextImplicitIndex(array $keys): int
    {
        $max = null;
        foreach ($keys as $key) {
            if ($key->type !== 'int' || !is_int($key->value)) {
                continue;
            }
            if ($max === null || $key->value > $max) {
                $max = $key->value;
            }
        }
        if ($max === null || $max < 0) {
            return 0;
        }

        return $max + 1;
    }

    private function arrayKey(Literal $key): string
    {
        if ($key->type !== 'int' && $key->type !== 'string') {
            throw SourceGenerationException::at(
                'array key type ' . $key->type . ' cannot be rendered',
                $key->opline,
                $key->line,
            );
        }
        $text = $this->render($key);
        if (str_contains($text, "\n")) {
            throw SourceGenerationException::at('array key cannot be rendered', $key->opline, $key->line);
        }

        return $text;
    }

    /**
     * @param list<string> $pieces
     */
    private function spansLines(array $pieces): bool
    {
        foreach ($pieces as $piece) {
            if (str_contains($piece, "\n")) {
                return true;
            }
        }

        return false;
    }

    private function variable(string $name, Expression $expression): string
    {
        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            throw SourceGenerationException::at(
                'variable name cannot be rendered',
                $expression->opline,
                $expression->line,
            );
        }

        return '$' . $name;
    }

    private function assignment(Assignment $expression): string
    {
        $operator = $expression->operator === null ? '=' : $this->compound($expression);
        $value = $this->render($expression->value, self::PREC_ASSIGN, 'right', 'right');

        return $this->variable($expression->variable, $expression) . ' ' . $operator . ' ' . $value;
    }

    private function compound(Assignment $expression): string
    {
        $operator = $expression->operator;
        if (!is_string($operator) || !in_array($operator, self::COMPOUND, true)) {
            throw SourceGenerationException::at(
                'assignment operator cannot be rendered',
                $expression->opline,
                $expression->line,
            );
        }

        return $operator . '=';
    }

    private function binary(Binary $expression): string
    {
        $operator = self::BINARY[$expression->operator] ?? null;
        if ($operator === null) {
            throw SourceGenerationException::at(
                'binary operator ' . $expression->operator . ' cannot be rendered',
                $expression->opline,
                $expression->line,
            );
        }
        $left = $this->render($expression->left, $operator[0], $operator[1], 'left');
        $right = $this->render($expression->right, $operator[0], $operator[1], 'right');

        return $left . ' ' . $expression->operator . ' ' . $right;
    }

    private function unary(Unary $expression): string
    {
        $precedence = self::UNARY[$expression->operator] ?? null;
        if ($precedence === null) {
            throw SourceGenerationException::at(
                'unary operator ' . $expression->operator . ' cannot be rendered',
                $expression->opline,
                $expression->line,
            );
        }
        $operand = $this->render($expression->operand, $precedence, 'right', 'right', true);
        if ($expression->operator === 'bool') {
            return '(bool)' . $operand;
        }

        return $expression->operator . $operand;
    }

    private function call(FunctionCall $expression): string
    {
        if (preg_match(self::QUALIFIED_NAME, $expression->name) !== 1) {
            throw SourceGenerationException::at(
                'function name cannot be rendered',
                $expression->opline,
                $expression->line,
            );
        }
        $arguments = [];
        foreach ($expression->arguments as $argument) {
            if (!$argument instanceof Argument) {
                throw SourceGenerationException::at(
                    'function argument cannot be rendered',
                    $expression->opline,
                    $expression->line,
                );
            }
            $arguments[] = $this->argument($argument);
        }

        return $expression->name . '(' . implode(', ', $arguments) . ')';
    }

    private function argument(Argument $argument): string
    {
        if ($argument->mode !== Argument::VALUE && $argument->mode !== Argument::REFERENCE) {
            throw SourceGenerationException::at(
                'argument mode cannot be rendered',
                $argument->opline,
                $argument->line,
            );
        }
        if ($argument->name !== null && $argument->unpack) {
            throw SourceGenerationException::at(
                'argument is both named and unpacked',
                $argument->opline,
                $argument->line,
            );
        }
        if ($argument->name !== null && preg_match(self::IDENTIFIER, $argument->name) !== 1) {
            throw SourceGenerationException::at(
                'argument name cannot be rendered',
                $argument->opline,
                $argument->line,
            );
        }

        // PHP 8 has no call-time pass-by-reference. SEND_REF records the
        // callee parameter, which this body does not declare, so the argument
        // is the value expression without a leading "&".
        $value = $this->render($argument->value);
        if ($argument->unpack) {
            return '...' . $value;
        }
        if ($argument->name !== null) {
            return $argument->name . ': ' . $value;
        }

        return $value;
    }

    private function match(MatchExpression $expression): string
    {
        if ($expression->arms === []) {
            throw SourceGenerationException::at(
                'match expression has no arms',
                $expression->opline,
                $expression->line,
            );
        }
        $subject = $this->render($expression->subject);
        $chunks = [];
        foreach ($expression->arms as $arm) {
            if (!$arm instanceof MatchArm) {
                throw SourceGenerationException::at(
                    'match arm is missing',
                    $expression->opline,
                    $expression->line,
                );
            }
            $result = (new self($this->indent + 1))->render($arm->result);
            $chunks[] = $this->arm($arm, $result);
        }

        return 'match (' . $subject . ") {\n" . implode("\n", $chunks) . "\n" . $this->pad($this->indent) . '}';
    }

    private function arm(MatchArm $arm, string $result): string
    {
        $prefixes = [];
        if ($arm->conditions !== []) {
            $conditions = [];
            foreach ($arm->conditions as $condition) {
                if (!$condition instanceof Literal) {
                    throw SourceGenerationException::at(
                        'match condition is not a literal',
                        $arm->opline,
                        $arm->line,
                    );
                }
                $text = $this->render($condition);
                if (str_contains($text, "\n")) {
                    throw SourceGenerationException::at(
                        'match condition cannot be rendered',
                        $condition->opline,
                        $condition->line,
                    );
                }
                $conditions[] = $text;
            }
            $prefixes[] = implode(', ', $conditions) . ' => ';
        }
        if ($arm->isDefault) {
            $prefixes[] = 'default => ';
        }
        if ($prefixes === []) {
            throw SourceGenerationException::at(
                'match arm has no condition',
                $arm->opline,
                $arm->line,
            );
        }

        $lines = [];
        foreach ($prefixes as $prefix) {
            $lines[] = $this->prefixed($prefix, $result, true);
        }

        return implode("\n", $lines);
    }

    private function prefixed(string $prefix, string $rendered, bool $comma): string
    {
        $parts = explode("\n", $prefix . $rendered);
        $parts[0] = $this->pad($this->indent + 1) . $parts[0];
        if ($comma) {
            $parts[count($parts) - 1] .= ',';
        }

        return implode("\n", $parts);
    }

    private function pad(int $indent): string
    {
        return str_repeat('    ', $indent);
    }
}
