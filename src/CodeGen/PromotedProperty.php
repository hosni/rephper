<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

/**
 * The modifiers of one constructor-promoted property.
 *
 * The string is the visibility, set visibility, and readonly keywords that
 * the property declaration would have used. The parameter type stays on the
 * op-array argument. `hooks` is the braced hook block, when this parameter's
 * property declares hooks in this class. It is appended after the default.
 */
final class PromotedProperty
{
    public function __construct(
        public readonly string $modifiers,
        public readonly ?string $hooks = null,
    ) {
    }
}
