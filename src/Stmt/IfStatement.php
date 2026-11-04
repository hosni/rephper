<?php

declare(strict_types=1);

namespace Rephper\Stmt;

/**
 * A conditional with one or more tested branches and an optional else.
 *
 * `elseStatements === null` means the compiler did not emit an else region.
 * An empty array is an else region that contains no statements. The statement
 * opline and line are the first tested branch. The else opline and line are
 * the first instruction of the else region.
 */
final class IfStatement extends Statement
{
    /**
     * @param list<ConditionalBranch> $branches
     * @param list<Statement>|null $elseStatements
     */
    public function __construct(
        int $opline,
        int $line,
        public readonly array $branches,
        public readonly ?array $elseStatements,
        public readonly ?int $elseOpline,
        public readonly ?int $elseLine,
    ) {
        parent::__construct($opline, $line);
    }
}
