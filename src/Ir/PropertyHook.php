<?php

declare(strict_types=1);

namespace Rephper\Ir;

final class PropertyHook
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $property,
        public readonly string $hook,
        public readonly array $extra,
    ) {
    }
}
