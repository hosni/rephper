<?php

declare(strict_types=1);

namespace Rephper\Ir;

/**
 * One namespace declaration and the declarations that belong to it.
 *
 * `name` is null for the global namespace. `functions` are unqualified
 * top-level function names in source order. `statements` is one line set per
 * top-level executable statement, also in source order. A statement belongs
 * to the region when the structured statement's line is in that set.
 * Import aliases are not stored.
 */
final class NamespaceRegion
{
    /**
     * @param list<string> $functions
     * @param list<list<int>> $statements
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly ?string $name,
        public readonly int $lineStart,
        public readonly int $lineEnd,
        public readonly array $functions,
        public readonly array $statements,
        public readonly array $extra,
    ) {
    }
}
