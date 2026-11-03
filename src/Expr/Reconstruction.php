<?php

declare(strict_types=1);

namespace Rephper\Expr;

final class Reconstruction
{
    /**
     * @param list<BlockExpressions> $blocks
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(
        public readonly array $blocks,
        public readonly array $diagnostics,
    ) {
    }

    public function blockStartingAt(int $start): ?BlockExpressions
    {
        foreach ($this->blocks as $block) {
            if ($block->start === $start) {
                return $block;
            }
        }

        return null;
    }
}
