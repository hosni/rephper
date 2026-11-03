<?php

declare(strict_types=1);

namespace Rephper\Expr;

final class VariableRead extends Expression
{
    public function __construct(
        int $opline,
        int $line,
        public readonly string $name,
        public readonly int $index,
    ) {
        parent::__construct($opline, $line);
    }
}
