<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use RuntimeException;

/**
 * A region is not an unambiguous forward conditional.
 *
 * The structurer catches this and returns the original statements.
 */
final class UnstructuredFlow extends RuntimeException
{
    public function __construct(
        public readonly string $blockId,
        public readonly int $opline,
        public readonly int $sourceLine,
        public readonly string $opcode,
        string $reason,
    ) {
        parent::__construct($reason);
    }
}
