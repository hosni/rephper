<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Expr\Expression;

/**
 * A for loop.
 *
 * Initializers run once, in order, before the condition. Updates run in order
 * after the body and after a continue that targets this loop. The condition
 * is null when the compiler synthesized a true test for an omitted condition.
 * A written true stays a literal. Expressions and body statements are the
 * objects already reconstructed for those instructions.
 */
final class ForStatement extends Statement
{
    /**
     * @param list<Expression> $initializers
     * @param list<Expression> $updates
     * @param list<Statement> $body
     */
    public function __construct(
        int $opline,
        int $line,
        public readonly array $initializers,
        public readonly ?Expression $condition,
        public readonly array $updates,
        public readonly array $body,
        public readonly string $loopId,
    ) {
        parent::__construct($opline, $line);
    }
}
