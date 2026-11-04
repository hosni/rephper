<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Expr\Expression;

/**
 * One tested arm of a conditional, in source order.
 *
 * `elseif` is another branch of the same statement. The condition is the
 * expression the branch instruction already used.
 */
final class ConditionalBranch
{
    /**
     * @param list<Statement> $statements
     */
    public function __construct(
        public readonly int $opline,
        public readonly int $line,
        public readonly Expression $condition,
        public readonly array $statements,
    ) {
    }
}
