<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Expr\Expression;

/**
 * A foreach loop over one iterable.
 *
 * The iterable is the expression already reconstructed for the reset operand.
 * The value target is the compiled variable written by the fetch. The key is
 * present only when that fetch's result is assigned to a compiled variable.
 * `byReference` is true when both the reset and the fetch are the RW forms, so
 * the value is not an ordinary assignment and stays a reference after the loop.
 * Body statements are the objects already reconstructed for the loop body.
 */
final class ForeachStatement extends Statement
{
    /**
     * @param list<Statement> $body
     */
    public function __construct(
        int $opline,
        int $line,
        public readonly Expression $iterable,
        public readonly ?CompiledVariableTarget $key,
        public readonly CompiledVariableTarget $value,
        public readonly bool $byReference,
        public readonly array $body,
        public readonly string $loopId,
    ) {
        parent::__construct($opline, $line);
    }
}
