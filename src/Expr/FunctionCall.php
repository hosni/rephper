<?php

declare(strict_types=1);

namespace Rephper\Expr;

/**
 * A direct named function call.
 *
 * `opline` is the instruction that executes the call and produces its result.
 * The init instruction is kept beside it so the original sequence remains visible.
 */
final class FunctionCall extends Expression
{
    /**
     * @param list<Argument> $arguments
     */
    public function __construct(
        int $opline,
        int $line,
        public readonly string $name,
        public readonly array $arguments,
        public readonly string $initOpcode,
        public readonly int $initOpline,
        public readonly int $initExtendedValue,
        public readonly string $executeOpcode,
        public readonly int $executeExtendedValue,
        public readonly bool $checksUndefinedArguments,
    ) {
        parent::__construct($opline, $line);
    }
}
