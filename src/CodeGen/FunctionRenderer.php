<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

use Rephper\Ir\Literal;
use Rephper\Ir\OpArray;

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

    private const QUALIFIED_NAME = '/\A\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z/';

    public function __construct(
        private readonly PhpSourceGenerator $source = new PhpSourceGenerator(),
        private readonly TypeRenderer $types = new TypeRenderer(),
        private readonly LiteralRenderer $literals = new LiteralRenderer(),
    ) {
    }

    public function render(FunctionDeclaration $declaration): string
    {
        $opArray = $declaration->opArray;
        $name = is_string($opArray->name) ? $opArray->name : '';
        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            $this->fail($name === '' ? (string) $opArray->id : $name, 'function name cannot be rendered');
        }
        $this->assertShape($opArray, $name);
        $this->assertAttributeTargets($opArray, $name);

        $header = $this->attributes($opArray, $name, 0);
        $reference = in_array('return_reference', $opArray->flags, true) ? '&' : '';
        $signature = 'function ' . $reference . $name . '(' . $this->parameters($opArray, $name) . ')';
        $return = $this->types->render($opArray->returnType, 'the return type of function ' . $name);
        if (in_array('has_return_type', $opArray->flags, true)) {
            if ($return === null) {
                $this->fail($name, 'return type metadata is incomplete');
            }
            $signature .= ': ' . $return;
        } elseif ($return !== null) {
            $this->fail($name, 'return type metadata is incomplete');
        }

        $lines = $header;
        $lines[] = $signature;
        $body = $this->source->renderStatements($declaration->body->statements, 1);
        $text = implode("\n", $lines) . "\n{\n";
        if ($body !== '') {
            $text .= $body . "\n";
        }
        $text .= '}';

        return $text;
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
    private function attributes(OpArray $opArray, string $name, int $offset): array
    {
        $rendered = [];
        foreach ($opArray->attributes as $attribute) {
            if (!is_array($attribute) || array_is_list($attribute) && $attribute !== []) {
                $this->fail($name, 'attribute metadata is incomplete');
            }
            if (!array_key_exists('offset', $attribute) || !is_int($attribute['offset'])) {
                $this->fail($name, 'attribute target is not in the IR');
            }
            if ($attribute['offset'] !== $offset) {
                continue;
            }
            $rendered[] = $this->attribute($attribute, $name, $opArray->lineStart);
        }

        return $rendered;
    }

    /**
     * @param array<mixed> $attribute
     */
    private function attribute(array $attribute, string $function, int $line): string
    {
        $name = $attribute['name'] ?? null;
        if (!is_string($name) || preg_match(self::QUALIFIED_NAME, $name) !== 1) {
            $this->fail($function, 'attribute name cannot be rendered');
        }
        $arguments = $attribute['arguments'] ?? null;
        if (!is_array($arguments) || !array_is_list($arguments)) {
            $this->fail($function, 'attribute arguments are missing');
        }
        $rendered = [];
        foreach ($arguments as $argument) {
            if (!is_array($argument)) {
                $this->fail($function, 'attribute argument cannot be rendered');
            }
            $argumentName = $argument['name'] ?? null;
            if ($argumentName !== null && (!is_string($argumentName) || preg_match(self::IDENTIFIER, $argumentName) !== 1)) {
                $this->fail($function, 'attribute argument name cannot be rendered');
            }
            $value = $argument['value'] ?? null;
            if (!is_array($value)) {
                $this->fail($function, 'attribute argument cannot be rendered');
            }
            try {
                $text = $this->literals->renderStored($value, is_int($attribute['line'] ?? null) ? $attribute['line'] : $line);
            } catch (SourceGenerationException $exception) {
                $this->fail($function, 'attribute argument cannot be rendered: ' . $exception->getMessage());
            }
            $rendered[] = $argumentName === null ? $text : $argumentName . ': ' . $text;
        }
        $inside = $rendered === [] ? '' : '(' . implode(', ', $rendered) . ')';

        return '#[' . $name . $inside . ']';
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
        if ($argument['promoted'] !== false) {
            $this->fail($function, 'promoted parameter $' . $name . ' is not supported');
        }
        if ($argument['variadic'] !== true && $argument['variadic'] !== false) {
            $this->fail($function, 'variadic metadata is incomplete');
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

        $attributes = $this->attributes($opArray, $function, $index + 1);
        $type = $this->types->render($argument['type'], 'the type of parameter $' . $name . ' on function ' . $function);
        $sigil = ($send === 'reference' ? '&' : '') . ($variadic ? '...' : '');
        $text = ($attributes === [] ? '' : implode(' ', $attributes) . ' ')
            . ($type === null ? '' : $type . ' ')
            . $sigil . '$' . $name;
        if ($default !== null) {
            $literal = $opArray->literal($default);
            if (!$literal instanceof Literal) {
                $this->fail($function, 'default value of $' . $name . ' is outside the literal table');
            }
            try {
                $value = $this->literals->renderLiteral($literal, $opArray->lineStart);
            } catch (SourceGenerationException $exception) {
                $this->fail($function, 'default value of $' . $name . ' cannot be rendered: ' . $exception->getMessage());
            }
            $text .= ' = ' . $value;
        }

        return $text;
    }

    private function fail(string $name, string $reason): never
    {
        throw new SourceGenerationException('Cannot generate PHP for function ' . $name . ': ' . $reason . '.');
    }
}
