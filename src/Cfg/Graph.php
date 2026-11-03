<?php

declare(strict_types=1);

namespace Rephper\Cfg;

final class Graph
{
    /**
     * @param list<Block> $blocks
     */
    public function __construct(
        public readonly int $opArrayId,
        public readonly ?string $name,
        public readonly string $kind,
        public readonly array $blocks,
    ) {
    }

    public function blockStartingAt(int $opline): ?Block
    {
        foreach ($this->blocks as $block) {
            if ($block->start === $opline) {
                return $block;
            }
        }

        return null;
    }

    public function blockContaining(int $opline): ?Block
    {
        foreach ($this->blocks as $block) {
            if ($opline >= $block->start && $opline < $block->end) {
                return $block;
            }
        }

        return null;
    }
}
