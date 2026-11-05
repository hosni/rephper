<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

use RuntimeException;

/**
 * Source generation stopped because a node has no reliable PHP form.
 *
 * The message names the opcode, opline, and source line when the node has them.
 * Generation does not return the PHP that was rendered before the failure.
 */
final class SourceGenerationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $opline = null,
        public readonly ?int $sourceLine = null,
        public readonly ?string $opcode = null,
    ) {
        parent::__construct($message);
    }

    public static function at(string $reason, int $opline, int $sourceLine, ?string $opcode = null): self
    {
        $where = 'opline ' . $opline . ', line ' . $sourceLine;
        $subject = $opcode === null ? $where : $opcode . ' at ' . $where;

        return new self(
            'Cannot generate PHP for ' . $subject . ': ' . $reason . '.',
            $opline,
            $sourceLine,
            $opcode,
        );
    }
}
