<?php

declare(strict_types=1);

namespace Rephper\Ir;

final class BreakContinue
{
    public function __construct(
        public readonly int $start,
        public readonly int $continueTarget,
        public readonly int $breakTarget,
        public readonly int $parent,
        public readonly bool $switch,
    ) {
    }
}
