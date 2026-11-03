<?php

declare(strict_types=1);

namespace Rephper\Cfg;

/**
 * One successor of an instruction, already resolved to an absolute opline.
 */
final class Successor
{
    public function __construct(
        public readonly string $kind,
        public readonly int $target,
        public readonly mixed $case = null,
    ) {
    }
}
