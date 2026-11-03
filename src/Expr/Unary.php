<?php

declare(strict_types=1);

namespace Rephper\Expr;

final class Unary extends Expression
{
    public function __construct(
        int $opline,
        int $line,
        public readonly string $operator,
        public readonly Expression $operand,
    ) {
        parent::__construct($opline, $line);
    }
}
