<?php

declare(strict_types=1);

namespace Rephper\Stmt;

abstract class Statement
{
    public function __construct(
        public readonly int $opline,
        public readonly int $line,
    ) {
    }
}
