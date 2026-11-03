<?php

declare(strict_types=1);

namespace Rephper\Stmt;

final class BlockStatements
{
    /**
     * @param list<Statement> $statements
     */
    public function __construct(
        public readonly string $blockId,
        public readonly int $start,
        public readonly int $end,
        public readonly array $statements,
    ) {
    }
}
