<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Expr\Expression;

final class ReturnStatement extends Statement
{
    public function __construct(
        int $opline,
        int $line,
        public readonly Expression $value,
    ) {
        parent::__construct($opline, $line);
    }
}
