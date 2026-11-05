<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Expr\Literal;

/**
 * One switch arm, in source order.
 *
 * Several labels share this arm when they jump to the same body. The body is
 * not copied for each label. `labels` is empty only for a default arm that has
 * no case label. `fallsThrough` is true when a path from this body reaches the
 * next arm. An explicit break does not.
 */
final class SwitchCase extends Statement
{
    /**
     * @param list<Literal> $labels
     * @param list<Statement> $body
     */
    public function __construct(
        int $opline,
        int $line,
        public readonly array $labels,
        public readonly array $body,
        public readonly bool $isDefault,
        public readonly bool $fallsThrough,
    ) {
        parent::__construct($opline, $line);
    }
}
