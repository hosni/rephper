<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

use Rephper\Expr\PropertyAssignment;
use Rephper\Expr\VariableRead;
use Rephper\Ir\Literal;
use Rephper\Ir\OpArray;
use Rephper\Stmt\ExpressionStatement;
use Rephper\Stmt\Statement;

/**
 * Renders one ordinary function from its op array and structured body.
 *
 * The signature is the IR metadata. The body is the existing statement
 * renderer at one indent. An incomplete signature throws, and no source for
 * that function is returned.
 */
final class FunctionRenderer
{
    private const IDENTIFIER = '/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/';

    private ?NameQualifier $names = null;

    private ?MethodStyle $method = null;

    public function __construct(
        private readonly PhpSourceGenerator $source = new PhpSourceGenerator(),
        private readonly TypeRenderer $types = new TypeRenderer(),
        private readonly LiteralRenderer $literals = new LiteralRenderer(),
        private readonly AttributeRenderer $attributes = new AttributeRenderer(),
    ) {
    }

    public function render(
        FunctionDeclaration $declaration,
        int $indent = 0,
        ?string $declaredName = null,
        ?NameQualifier $names = null,
        ?MethodStyle $method = null,
    ): string {
        $previousNames = $this->names;
        $previousMethod = $this->method;
        $this->names = $names;
        $this->method = $method;
        try {
            return $this->renderDeclaration($declaration, $indent, $declaredName);
        } finally {
            $this->names = $previousNames;
            $this->method = $previousMethod;
        }
    }

    private function renderDeclaration(FunctionDeclaration $declaration, int $indent, ?string $declaredName): string
    {
        $opArray = $declaration->opArray;
        $stored = is_string($opArray->name) ? $opArray->name : '';
        $name = $declaredName ?? $stored;
        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            $this->fail($stored === '' ? (string) $opArray->id : $stored, 'function name cannot be rendered');
        }
        $this->assertShape($opArray, $stored === '' ? $name : $stored);
        $this->assertAttributeTargets($opArray, $stored === '' ? $name : $stored);

        $label = $stored === '' ? $name : $stored;
        $pad = str_repeat('    ', $indent);
        $header = $this->attributeLines($opArray, $label, 0);
        $reference = in_array('return_reference', $opArray->flags, true) ? '&' : '';
        $signature = $this->prefix() . 'function ' . $reference . $name . '(' . $this->parameters($opArray, $label) . ')';
        $return = $this->types->render($opArray->returnType, 'the return type of ' . $this->subject($label), 0, $this->names);
        if (in_array('has_return_type', $opArray->flags, true)) {
            if ($return === null) {
                $this->fail($label, 'return type metadata is incomplete');
            }
            $signature .= ': ' . $return;
        } elseif ($return !== null) {
            $this->fail($label, 'return type metadata is incomplete');
        }

        $lines = [];
        foreach ($header as $attribute) {
            $lines[] = $pad . $attribute;
        }
        $statements = $declaration->body->statements;
        if ($this->method !== null && $this->method->promoted !== []) {
            if ($this->method->isAbstract) {
                $this->fail($label, 'abstract method has a promoted parameter');
            }
            $statements = $this->stripPromoted($statements, $label);
        }
        if ($this->method !== null && $this->method->isAbstract) {
            if ($statements !== []) {
                $this->fail($label, 'abstract method has a body');
            }
            $lines[] = $pad . $signature . ';';

            return implode("\n", $lines);
        }
        $lines[] = $pad . $signature;
        $source = $this->names === null ? $this->source : new PhpSourceGenerator($this->names);
        $body = $source->renderStatements($statements, $indent + 1);
        $text = implode("\n", $lines) . "\n" . $pad . "{\n";
        if ($body !== '') {
            $text .= $body . "\n";
        }
        $text .= $pad . '}';

        return $text;
    }

    private function prefix(): string
    {
        if ($this->method === null) {
            return '';
        }
        $parts = [];
        if ($this->method->isFinal) {
            $parts[] = 'final';
        }
        if ($this->method->printsAbstract) {
            $parts[] = 'abstract';
        }
        $parts[] = $this->method->visibility;
        if ($this->method->isStatic) {
            $parts[] = 'static';
        }

        return implode(' ', $parts) . ' ';
    }

    /**
     * @param list<Statement> $statements
     * @return list<Statement>
     */
    private function stripPromoted(array $statements, string $name): array
    {
        $method = $this->method;
        if ($method === null) {
            $this->fail($name, 'promoted parameter is not supported');
        }
        foreach ($method->promoted as $index => $parameter) {
            $statement = $statements[$index] ?? null;
            $value = $statement instanceof ExpressionStatement ? $statement->expression : null;
            if (!$value instanceof PropertyAssignment
                || $value->property !== $parameter
                || !$value->value instanceof VariableRead
                || $value->value->name !== $parameter
            ) {
                $this->fail($name, 'promoted parameter $' . $parameter . ' has no matching assignment');
            }
        }

        return array_slice($statements, count($method->promoted));
    }

    private function assertShape(OpArray $opArray, string $name): void
    {
        $variadic = [];
        foreach ($opArray->arguments as $index => $argument) {
            if (!is_array($argument) || array_is_list($argument)) {
                $this->fail($name, 'parameter ' . $index . ' is missing');
            }
            if (($argument['variadic'] ?? null) === true) {
                $variadic[] = $index;
            }
        }
        $flag = in_array('variadic', $opArray->flags, true);
        if ($flag !== ($variadic !== []) || count($variadic) > 1) {
            $this->fail($name, 'variadic metadata is incomplete');
        }
        if ($variadic !== [] && $variadic[0] !== count($opArray->arguments) - 1) {
            $this->fail($name, 'variadic parameter is not last');
        }
        if (count($opArray->arguments) - count($variadic) !== $opArray->numArgs) {
            $this->fail($name, 'parameter count does not match num_args');
        }
        if ($opArray->requiredNumArgs < 0 || $opArray->requiredNumArgs > $opArray->numArgs) {
            $this->fail($name, 'required parameter count is not valid');
        }
        $hasReturn = in_array('has_return_type', $opArray->flags, true);
        if ($hasReturn !== ($opArray->returnType !== null)) {
            $this->fail($name, 'return type metadata is incomplete');
        }
    }

    private function assertAttributeTargets(OpArray $opArray, string $name): void
    {
        foreach ($opArray->attributes as $attribute) {
            if (!is_array($attribute) || !array_key_exists('offset', $attribute) || !is_int($attribute['offset'])) {
                $this->fail($name, 'attribute target is not in the IR');
            }
            if ($attribute['offset'] < 0 || $attribute['offset'] > count($opArray->arguments)) {
                $this->fail($name, 'attribute target is not on this function');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function attributeLines(OpArray $opArray, string $name, int $offset): array
    {
        return $this->attributes->render(
            $opArray->attributes,
            $offset,
            $name,
            $this->method === null ? 'function' : 'method',
            $opArray->lineStart,
            $this->names,
        );
    }

    private function parameters(OpArray $opArray, string $name): string
    {
        $parameters = [];
        foreach ($opArray->arguments as $index => $argument) {
            if (!is_array($argument)) {
                $this->fail($name, 'parameter ' . $index . ' is missing');
            }
            $parameters[] = $this->parameter($opArray, $argument, $index, $name);
        }

        return implode(', ', $parameters);
    }

    /**
     * @param array<mixed> $argument
     */
    private function parameter(OpArray $opArray, array $argument, int $index, string $function): string
    {
        foreach (['name', 'type', 'send', 'variadic', 'promoted', 'default_literal'] as $key) {
            if (!array_key_exists($key, $argument)) {
                $this->fail($function, 'parameter metadata is incomplete');
            }
        }
        $name = $argument['name'];
        if (!is_string($name) || preg_match(self::IDENTIFIER, $name) !== 1) {
            $this->fail($function, 'parameter name cannot be rendered');
        }
        if ($argument['promoted'] !== true && $argument['promoted'] !== false) {
            $this->fail($function, 'promoted metadata is incomplete');
        }
        if ($argument['variadic'] !== true && $argument['variadic'] !== false) {
            $this->fail($function, 'variadic metadata is incomplete');
        }
        $promotion = null;
        if ($argument['promoted'] === true) {
            $promotion = $this->method?->promotions[$name] ?? null;
            if (!$promotion instanceof PromotedProperty) {
                $this->fail($function, 'promoted parameter $' . $name . ' is not supported');
            }
        }
        $send = $argument['send'];
        if ($send !== 'value' && $send !== 'reference') {
            $mode = is_string($send) ? $send : 'unknown';
            $this->fail($function, 'parameter $' . $name . ' uses send mode ' . $mode . ', which has no PHP spelling');
        }
        $variadic = $argument['variadic'] === true;
        $optional = $index >= $opArray->requiredNumArgs && !$variadic;
        $default = $argument['default_literal'];
        if ($default !== null && !is_int($default)) {
            $this->fail($function, 'default value of $' . $name . ' is not a literal index');
        }
        if ($variadic || $index < $opArray->requiredNumArgs) {
            if ($default !== null) {
                $this->fail($function, 'parameter $' . $name . ' has a default value it cannot use');
            }
        } elseif ($optional && $default === null) {
            $this->fail($function, 'parameter $' . $name . ' is missing its default value');
        }

        if ($promotion instanceof PromotedProperty && ($variadic || $send === 'reference')) {
            $this->fail($function, 'promoted parameter $' . $name . ' cannot be variadic or passed by reference');
        }
        $attributes = $this->attributeLines($opArray, $function, $index + 1);
        $type = $this->types->render($argument['type'], 'the type of parameter $' . $name . ' on ' . $this->subject($function), 0, $this->names);
        $sigil = ($send === 'reference' ? '&' : '') . ($variadic ? '...' : '');
        $head = $type === null ? '' : $type . ' ';
        if ($promotion instanceof PromotedProperty) {
            $head = $promotion->modifiers . ' ' . $head;
        }
        $text = ($attributes === [] ? '' : implode(' ', $attributes) . ' ')
            . $head
            . $sigil . '$' . $name;
        if ($default !== null) {
            $literal = $opArray->literal($default);
            if (!$literal instanceof Literal) {
                $this->fail($function, 'default value of $' . $name . ' is outside the literal table');
            }
            try {
                $value = $this->literals->renderLiteral($literal, $opArray->lineStart, $this->names);
            } catch (SourceGenerationException $exception) {
                $this->fail($function, 'default value of $' . $name . ' cannot be rendered: ' . $exception->getMessage());
            }
            $text .= ' = ' . $value;
        }

        return $text;
    }

    private function subject(string $name): string
    {
        return ($this->method === null ? 'function ' : 'method ') . $name;
    }

    private function fail(string $name, string $reason): never
    {
        throw new SourceGenerationException('Cannot generate PHP for ' . $this->subject($name) . ': ' . $reason . '.');
    }
}
