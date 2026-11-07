<?php

declare(strict_types=1);

namespace Rephper\Ir;

/**
 * One `insteadof` adaptation from `zend_class_entry.trait_precedences`.
 *
 * `trait` is the resolved class of the selected method. `insteadOf` keeps
 * `exclude_class_names` in source order.
 */
final class TraitPrecedence
{
    /**
     * @param list<string> $insteadOf
     */
    public function __construct(
        public readonly string $trait,
        public readonly string $method,
        public readonly array $insteadOf,
    ) {
    }
}
