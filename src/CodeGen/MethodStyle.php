<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

/**
 * Modifiers for one method.
 *
 * `isAbstract` is a declaration with no body. `printsAbstract` is the
 * `abstract` keyword. Interface methods are abstract and do not print it.
 * `promoted` is the promoted parameter names in signature order. `promotions`
 * maps each of those names to the property modifiers that precede its type.
 */
final class MethodStyle
{
    /**
     * @param list<string> $promoted
     * @param array<string, PromotedProperty> $promotions
     */
    public function __construct(
        public readonly string $visibility,
        public readonly bool $isStatic,
        public readonly bool $isFinal,
        public readonly bool $isAbstract,
        public readonly bool $printsAbstract,
        public readonly array $promoted,
        public readonly array $promotions,
    ) {
    }
}
