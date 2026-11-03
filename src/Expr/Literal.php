<?php

declare(strict_types=1);

namespace Rephper\Expr;

final class Literal extends Expression
{
    public function __construct(
        int $opline,
        int $line,
        public readonly string $type,
        public readonly mixed $value,
    ) {
        parent::__construct($opline, $line);
    }
}
