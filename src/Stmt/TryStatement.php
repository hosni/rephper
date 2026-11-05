<?php

declare(strict_types=1);

namespace Rephper\Stmt;

/**
 * A try statement and its catch clauses, without a finally handler.
 *
 * `body` is the protected statements in execution order. `catches` is in
 * source order. `opline` and `line` are the first instruction of the
 * protected region. `catchOpline` is the first CATCH of the dispatch chain.
 * `continuationOpline` is the first opline after the try/catch, where normal
 * execution continues. Finally is not represented.
 */
final class TryStatement extends Statement
{
    /**
     * @param list<Statement> $body
     * @param list<CatchClause> $catches
     */
    public function __construct(
        int $opline,
        int $line,
        public readonly array $body,
        public readonly array $catches,
        public readonly int $catchOpline,
        public readonly int $continuationOpline,
    ) {
        parent::__construct($opline, $line);
    }
}
