<?php

declare(strict_types=1);

namespace Rephper\Ir;

final class Label
{
    public function __construct(
        public readonly ?string $name,
        public readonly int $opline,
        public readonly int $breakContinue,
    ) {
    }
}
