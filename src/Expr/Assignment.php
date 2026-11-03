<?php

declare(strict_types=1);

namespace Rephper\Expr;

final class Assignment extends Expression
{
    public function __construct(
        int $opline,
        int $line,
        public readonly string $variable,
        public readonly Expression $value,
        public readonly ?string $operator,
    ) {
        parent::__construct($opline, $line);
    }
}
