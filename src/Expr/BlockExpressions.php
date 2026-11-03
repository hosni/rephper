<?php

declare(strict_types=1);

namespace Rephper\Expr;

final class BlockExpressions
{
    /**
     * @param list<Expression> $expressions
     */
    public function __construct(
        public readonly string $blockId,
        public readonly int $start,
        public readonly int $end,
        public readonly array $expressions,
    ) {
    }
}
