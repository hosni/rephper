<?php

declare(strict_types=1);

namespace Rephper\Cfg;

use Rephper\Ir\Instruction;

final class Block
{
    /**
     * @param list<Instruction> $instructions
     * @param list<Edge> $successors
     * @param list<string> $predecessors
     * @param list<Edge> $exceptions
     * @param list<string> $exceptionPredecessors
     */
    public function __construct(
        public readonly string $id,
        public readonly int $start,
        public readonly int $end,
        public readonly array $instructions,
        public array $successors = [],
        public array $predecessors = [],
        public array $exceptions = [],
        public array $exceptionPredecessors = [],
    ) {
    }
}
