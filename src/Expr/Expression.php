<?php

declare(strict_types=1);

namespace Rephper\Expr;

abstract class Expression
{
    public function __construct(
        public readonly int $opline,
        public readonly int $line,
    ) {
    }
}
