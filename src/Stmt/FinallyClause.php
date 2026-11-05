<?php

declare(strict_types=1);

namespace Rephper\Stmt;

/**
 * The finally handler of one try statement.
 *
 * This is not a statement and it is not repeated for each FAST_CALL. `opline`
 * is the first instruction of the finally body. `line` is the finally keyword,
 * taken from the normal FAST_CALL. `endOpline` is the FAST_RET. `callOpline`
 * is that normal FAST_CALL. An empty body has `opline === endOpline`.
 */
final class FinallyClause
{
    /**
     * @param list<Statement> $body
     */
    public function __construct(
        public readonly int $opline,
        public readonly int $line,
        public readonly int $endOpline,
        public readonly int $callOpline,
        public readonly array $body,
    ) {
    }
}
