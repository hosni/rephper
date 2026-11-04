<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Expr\Expression;

/**
 * A do-while loop.
 *
 * The body is ordered before the condition. The condition is the expression
 * on the latch branch. When the back edge has kind false, the condition is
 * that expression wrapped in !. The body statements are the existing
 * statement objects, not copies.
 */
final class DoWhileStatement extends Statement
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
