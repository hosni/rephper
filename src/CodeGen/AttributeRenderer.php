<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

/**
 * Renders attributes stored on a function, method, parameter, or class.
 *
 * `offset` 0 is the function, method, or class. A parameter offset is 1-based.
 * An attribute without an offset is not given a target.
 */
final class AttributeRenderer
{
    private const IDENTIFIER = '/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/';

    private const QUALIFIED_NAME = '/\A\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z/';

    public function __construct(
        private readonly LiteralRenderer $literals = new LiteralRenderer(),
    ) {
    }

    /**
     * @param list<mixed> $attributes
     * @return list<string>
     */
    public function render(
        array $attributes,
        int $offset,
        string $owner,
        string $kind,
        int $line,
        ?NameQualifier $names,
    ): array {
        $rendered = [];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute) || (array_is_list($attribute) && $attribute !== [])) {
                $this->fail($kind, $owner, 'attribute metadata is incomplete');
            }
            if (!array_key_exists('offset', $attribute) || !is_int($attribute['offset'])) {
                $this->fail($kind, $owner, 'attribute target is not in the IR');
            }
            if ($attribute['offset'] !== $offset) {
                continue;
            }
            $rendered[] = $this->attribute($attribute, $owner, $kind, $line, $names);
        }

        return $rendered;
    }

    /**
     * @param array<mixed> $attribute
     */
    private function attribute(array $attribute, string $owner, string $kind, int $line, ?NameQualifier $names): string
    {
        $name = $attribute['name'] ?? null;
        if (!is_string($name) || preg_match(self::QUALIFIED_NAME, $name) !== 1) {
            $this->fail($kind, $owner, 'attribute name cannot be rendered');
        }
        if ($names !== null) {
            $name = $names->symbol($name);
        }
        $arguments = $attribute['arguments'] ?? null;
        if (!is_array($arguments) || !array_is_list($arguments)) {
            $this->fail($kind, $owner, 'attribute arguments are missing');
        }
        $rendered = [];
        foreach ($arguments as $argument) {
            if (!is_array($argument)) {
                $this->fail($kind, $owner, 'attribute argument cannot be rendered');
            }
            $argumentName = $argument['name'] ?? null;
            if ($argumentName !== null && (!is_string($argumentName) || preg_match(self::IDENTIFIER, $argumentName) !== 1)) {
                $this->fail($kind, $owner, 'attribute argument name cannot be rendered');
            }
            $value = $argument['value'] ?? null;
            if (!is_array($value)) {
                $this->fail($kind, $owner, 'attribute argument cannot be rendered');
            }
            try {
                $text = $this->literals->renderStored($value, is_int($attribute['line'] ?? null) ? $attribute['line'] : $line, $names);
            } catch (SourceGenerationException $exception) {
                $this->fail($kind, $owner, 'attribute argument cannot be rendered: ' . $exception->getMessage());
            }
            $rendered[] = $argumentName === null ? $text : $argumentName . ': ' . $text;
        }
        $inside = $rendered === [] ? '' : '(' . implode(', ', $rendered) . ')';

        return '#[' . $name . $inside . ']';
    }

    private function fail(string $kind, string $owner, string $reason): never
    {
        throw new SourceGenerationException('Cannot generate PHP for ' . $kind . ' ' . $owner . ': ' . $reason . '.');
    }
}
