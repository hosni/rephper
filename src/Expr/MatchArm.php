<?php

declare(strict_types=1);

namespace Rephper\Expr;

/**
 * One arm of a match expression, in source order.
 *
 * Several conditions share this arm when they jump to the same result. The
 * result expression is not copied for each condition. `conditions` is empty
 * only for the default arm. Match conditions are identity comparisons; these
 * literals are the jump-table keys, not loose-equality tests.
 */
final class MatchArm
{
    /**
     * @param list<Literal> $conditions
     */
    public function __construct(
        public readonly int $opline,
        public readonly int $line,
        public readonly array $conditions,
        public readonly Expression $result,
        public readonly bool $isDefault,
    ) {
    }
}
