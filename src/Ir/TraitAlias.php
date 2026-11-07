<?php

declare(strict_types=1);

namespace Rephper\Ir;

/**
 * One `as` adaptation from `zend_class_entry.trait_aliases`.
 *
 * `trait` is null when the source named the method without a class. `alias`
 * is null when the adaptation only changes a modifier. `modifiers` is empty
 * or one of `public`, `protected`, `private`, and `final`.
 */
final class TraitAlias
{
    /**
     * @param list<string> $modifiers
     */
    public function __construct(
        public readonly ?string $trait,
        public readonly string $method,
        public readonly ?string $alias,
        public readonly array $modifiers,
    ) {
    }
}
