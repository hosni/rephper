<?php

declare(strict_types=1);

namespace Rephper\Expr;

final class Binary extends Expression
{
    public function __construct(
        int $opline,
        int $line,
        public readonly string $operator,
        public readonly Expression $left,
        public readonly Expression $right,
    ) {
        parent::__construct($opline, $line);
    }
}
