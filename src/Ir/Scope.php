<?php

declare(strict_types=1);

namespace Rephper\Ir;

final class Scope
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly array $extra,
    ) {
    }
}
