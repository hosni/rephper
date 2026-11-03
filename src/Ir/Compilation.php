<?php

declare(strict_types=1);

namespace Rephper\Ir;

final class Compilation
{
    public function __construct(
        public readonly string $stage,
        public readonly string $jumps,
    ) {
    }
}
