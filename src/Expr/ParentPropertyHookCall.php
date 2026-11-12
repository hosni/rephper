<?php

declare(strict_types=1);

namespace Rephper\Expr;

/**
 * A call to the parent implementation of one property hook.
 *
 * The source spelling is `parent::$property::get()` or `parent::$property::set(...)`.
 * The parent class name is not in the opcode. `opline` is the `DO_FCALL` that
 * produces the result. This is not a method call or a static call.
 */
final class ParentPropertyHookCall extends Expression
{
    /**
     * @param list<Argument> $arguments
     */
    public function __construct(
        int $opline,
        int $line,
        public readonly string $property,
        public readonly string $hook,
        public readonly array $arguments,
        public readonly int $initOpline,
        public readonly int $initExtendedValue,
        public readonly int $executeExtendedValue,
        public readonly bool $checksUndefinedArguments,
    ) {
        parent::__construct($opline, $line);
    }
}
