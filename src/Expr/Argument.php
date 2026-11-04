<?php

declare(strict_types=1);

namespace Rephper\Expr;

/**
 * One argument of a reconstructed function call, in send order.
 *
 * The value stays an expression. A named argument keeps the name the compiler
 * stored. An unpacked argument is not turned into a list of elements.
 */
final class Argument
{
    public const VALUE = 'value';

    public const REFERENCE = 'reference';

    public function __construct(
        public readonly int $opline,
        public readonly int $line,
        public readonly string $opcode,
        public readonly Expression $value,
        public readonly ?string $name,
        public readonly string $mode,
        public readonly bool $unpack,
        public readonly ?int $position,
    ) {
    }
}
