<?php

declare(strict_types=1);

namespace Rephper\Expr;

final class Diagnostic
{
    public function __construct(
        public readonly string $kind,
        public readonly string $blockId,
        public readonly int $opline,
        public readonly int $line,
        public readonly string $opcode,
        public readonly string $reason,
    ) {
    }
}
