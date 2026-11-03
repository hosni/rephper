<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Expr\Diagnostic;

final class Reconstruction
{
    /**
     * @param list<BlockStatements> $blocks
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(
        public readonly array $blocks,
        public readonly array $diagnostics,
    ) {
    }

    public function blockStartingAt(int $start): ?BlockStatements
    {
        foreach ($this->blocks as $block) {
            if ($block->start === $start) {
                return $block;
            }
        }

        return null;
    }
}
