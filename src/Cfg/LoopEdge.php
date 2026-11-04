<?php

declare(strict_types=1);

namespace Rephper\Cfg;

/**
 * One ordinary CFG edge recorded for a loop.
 *
 * `opline` and `line` belong to the last instruction of the source block,
 * which is the instruction that takes the edge.
 */
final class LoopEdge
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $kind,
        public readonly int $opline,
        public readonly int $line,
    ) {
    }
}
