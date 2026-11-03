<?php

declare(strict_types=1);

namespace Rephper\Cfg;

final class Edge
{
    public function __construct(
        public readonly string $kind,
        public readonly string $to,
        public readonly mixed $case = null,
    ) {
    }
}
