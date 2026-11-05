<?php

declare(strict_types=1);

namespace Rephper\Stmt;

/**
 * One catch clause of a try statement.
 *
 * `types` are the exception class names in source order. Names that belong
 * to one union catch share this clause. `variable` is the caught variable's
 * compiled name, without `$`, or null when the clause catches without a
 * variable. `body` is the clause's statements in execution order. `opline`
 * and `line` are the first CATCH instruction of the clause.
 */
final class CatchClause
{
    /**
     * @param list<string> $types
     * @param list<int> $catchOplines
     * @param list<Statement> $body
     */
    public function __construct(
        public readonly int $opline,
        public readonly int $line,
        public readonly array $types,
        public readonly array $catchOplines,
        public readonly ?string $variable,
        public readonly array $body,
    ) {
    }
}
