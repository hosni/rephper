<?php

declare(strict_types=1);

namespace Rephper\Expr;

/**
 * A write to a property of `$this`.
 *
 * The object operand is the `this` kind. The property name is a string
 * literal. The value is the following `OP_DATA` operand.
 */
final class PropertyAssignment extends Expression
{
    public function __construct(
        int $opline,
        int $line,
        public readonly string $property,
        public readonly Expression $value,
    ) {
        parent::__construct($opline, $line);
    }
}
