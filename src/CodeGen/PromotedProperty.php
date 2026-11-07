<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

/**
 * The modifiers of one constructor-promoted property.
 *
 * The string is the visibility, set visibility, and readonly keywords that
 * the property declaration would have used. The parameter type stays on the
 * op-array argument.
 */
final class PromotedProperty
{
    public function __construct(
        public readonly string $modifiers,
    ) {
    }
}
