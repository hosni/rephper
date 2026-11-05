<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Expr\Diagnostic;

/**
 * Statements for one op array after a structuring pass.
 *
 * ConditionalStructurer and LoopStructurer both return this value.
 * LoopStructurer rebuilds while, do-while, for, foreach, and jump-table switch. The control-flow
 * graph and the per-block statement reconstruction are left unchanged. This
 * value is a separate view of the same statement objects.
 */
final class StructuredStatements
{
    /**
     * @param list<Statement> $statements
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(
        public readonly array $statements,
        public readonly array $diagnostics,
    ) {
    }
}
