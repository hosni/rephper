<?php

declare(strict_types=1);

namespace Rephper\Stmt;

/**
 * A foreach key or value that the compiler writes through one compiled variable.
 *
 * This is a write target, not a value expression. `index` is the compiled-variable
 * number. `opline` is the instruction that writes it: the fetch for a value, the
 * following assignment for a key. Destructuring and other writable forms are not
 * represented here.
 */
final class CompiledVariableTarget
{
    public function __construct(
        public readonly string $name,
        public readonly int $index,
        public readonly int $opline,
        public readonly int $line,
    ) {
    }
}
