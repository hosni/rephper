<?php

declare(strict_types=1);

namespace Rephper\Expr;

final class ReturnValue extends Expression
{
    public function __construct(
        int $opline,
        int $line,
        public readonly ?Expression $value,
    ) {
        parent::__construct($opline, $line);
    }
}
