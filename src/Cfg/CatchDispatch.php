<?php

declare(strict_types=1);

namespace Rephper\Cfg;

/**
 * One catch clause recovered from a CATCH dispatch chain.
 *
 * `types` is the clause's exception class names in source order. A union
 * catch is several names on one clause. `catchOplines` are the CATCH
 * instructions that share this clause, in the same order. `variable` is the
 * compiled variable those instructions assign, or null when the clause has
 * no variable. `bodyStart` is inclusive and `bodyEnd` is exclusive. An empty
 * body has `bodyStart === bodyEnd`.
 */
final class CatchDispatch
{
    /**
     * @param list<string> $types
     * @param list<int> $catchOplines
     */
    public function __construct(
        public readonly int $opline,
        public readonly int $line,
        public readonly array $types,
        public readonly array $catchOplines,
        public readonly ?string $variable,
        public readonly int $bodyStart,
        public readonly int $bodyEnd,
    ) {
    }
}
