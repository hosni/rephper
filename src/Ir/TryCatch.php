<?php

declare(strict_types=1);

namespace Rephper\Ir;

/**
 * A row from op_array.try_catch_array.
 *
 * catch, finally, and finallyEnd are null when the exporter saw (uint32_t)-1.
 * The compiler leaves catch and finally at 0 when that handler was not emitted.
 * 0 is a sentinel, not opline 0: the VM tests op_num < catch_op, which is never
 * true for 0.
 */
final class TryCatch
{
    public function __construct(
        public readonly int $try,
        public readonly ?int $catch,
        public readonly ?int $finally,
        public readonly ?int $finallyEnd,
    ) {
    }

    public function hasCatch(): bool
    {
        return $this->catch !== null && $this->catch !== 0;
    }

    public function hasFinally(): bool
    {
        return $this->finally !== null && $this->finally !== 0;
    }
}
