<?php

declare(strict_types=1);

namespace Rephper\Expr;

/**
 * A read of a property of `$this`.
 *
 * The object operand is the `this` kind. The property name is a string
 * literal. A by-reference fetch uses the same spelling; the `&` belongs to
 * the hook or the function. Other object fetches stay unresolved.
 */
final class PropertyFetch extends Expression
{
    public function __construct(
        int $opline,
        int $line,
        public readonly string $property,
    ) {
        parent::__construct($opline, $line);
    }
}
