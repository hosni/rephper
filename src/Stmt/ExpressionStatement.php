<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Expr\Expression;

final class ExpressionStatement extends Statement
{
    public function __construct(
        int $opline,
        int $line,
        public readonly Expression $expression,
    ) {
        parent::__construct($opline, $line);
    }
}
