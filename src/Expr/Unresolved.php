<?php

declare(strict_types=1);

namespace Rephper\Expr;

/**
 * A temporary or compiler variable used where this block does not contain its definition.
 */
final class Unresolved extends Expression
{
    public function __construct(
        int $opline,
        int $line,
        public readonly string $kind,
        public readonly int $index,
        public readonly string $reason,
    ) {
        parent::__construct($opline, $line);
    }
}
