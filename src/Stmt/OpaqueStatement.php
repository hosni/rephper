<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Expr\Opaque;

final class OpaqueStatement extends Statement
{
    public function __construct(
        int $opline,
        int $line,
        public readonly Opaque $instruction,
    ) {
        parent::__construct($opline, $line);
    }
}
