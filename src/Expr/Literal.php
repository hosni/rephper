<?php

declare(strict_types=1);

namespace Rephper\Expr;

/**
 * A PHP literal.
 *
 * `value` is the scalar stored on the IR literal. An array keeps that field
 * null and carries its elements here, in source order, so the key types are
 * not collapsed into one PHP array. `elements` is null for every other type,
 * and for an array whose elements were not available. `binary` is true when
 * the IR marked a string as non-UTF-8.
 *
 * @param list<array{key: Literal, value: Literal}>|null $elements
 */
final class Literal extends Expression
{
    public function __construct(
        int $opline,
        int $line,
        public readonly string $type,
        public readonly mixed $value,
        public readonly ?array $elements = null,
        public readonly bool $binary = false,
    ) {
        parent::__construct($opline, $line);
    }
}
