<?php

declare(strict_types=1);

namespace Rephper\Stmt;

/**
 * A try statement, its catch clauses, and its optional finally handler.
 *
 * `body` is the protected statements in execution order. `catches` is in
 * source order and is empty when the statement has no catch. `opline` and
 * `line` are the first instruction of the protected region. `catchOpline` is
 * the first CATCH of the dispatch chain, or null when there is no catch.
 * `continuationOpline` is the first opline after the whole statement, where
 * normal execution continues. `finally` is the one finally body, or null when
 * the statement has no finally. FAST_CALL and FAST_RET are not statements.
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
        public readonly ?int $catchOpline,
        public readonly int $continuationOpline,
        public readonly ?FinallyClause $finally = null,
    ) {
        parent::__construct($opline, $line);
    }
}
