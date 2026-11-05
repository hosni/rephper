<?php

declare(strict_types=1);

namespace Rephper\Expr;

/**
 * A jump-table match expression.
 *
 * `subject` is the expression already stored on the MATCH operand, evaluated
 * once. `arms` are in source order. A default arm has `isDefault` set.
 * `unhandledOpline` is the MATCH_ERROR instruction when the match has no
 * default; that path throws UnhandledMatchError and is not a default arm.
 * `fallbackOpline` is the MATCH extended_value, the default arm or that error.
 */
final class MatchExpression extends Expression
{
    /**
     * @param list<MatchArm> $arms
     */
    public function __construct(
        int $opline,
        int $line,
        public readonly Expression $subject,
        public readonly array $arms,
        public readonly ?int $unhandledOpline,
        public readonly int $fallbackOpline,
    ) {
        parent::__construct($opline, $line);
    }
}
