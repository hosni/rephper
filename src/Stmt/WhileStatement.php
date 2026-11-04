<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Expr\Expression;

/**
 * A while loop.
 *
 * The condition is the expression already stored on the header branch. When
 * the edge that enters the body has kind false, the condition is that
 * expression wrapped in !. The body statements are the statements of the
 * natural loop, in execution order, and do not include the condition branch.
 */
final class WhileStatement extends Statement
{
    /**
     * @param list<Statement> $body
     */
    public function __construct(
        int $opline,
        int $line,
        public readonly Expression $condition,
        public readonly array $body,
        public readonly string $loopId,
    ) {
        parent::__construct($opline, $line);
    }
}
