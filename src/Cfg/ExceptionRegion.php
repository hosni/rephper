<?php

declare(strict_types=1);

namespace Rephper\Cfg;

/**
 * One try/catch row located in an op array.
 *
 * `try` is the protected region's first opline. `spanEnd` is the first
 * opline after the whole statement, where normal execution continues.
 * `finally` is the finally body, `finallyEnd` is its FAST_RET, and
 * `finallyCall` is the normal FAST_CALL that enters that handler. Any of
 * those is null when the row has no finally. `clauses` is null when the row
 * is not reconstructed. `reason` says why. The value does not own CFG blocks
 * and does not follow exception edges.
 */
final class ExceptionRegion
{
    /**
     * @param list<CatchDispatch>|null $clauses
     */
    public function __construct(
        public readonly int $index,
        public readonly int $try,
        public readonly int $spanEnd,
        public readonly bool $hasFinally,
        public readonly ?int $finally,
        public readonly ?int $finallyEnd,
        public readonly ?int $finallyCall,
        public readonly ?int $catch,
        public readonly ?array $clauses,
        public readonly ?string $reason,
    ) {
    }

    public function isStructured(): bool
    {
        if ($this->reason !== null || $this->clauses === null) {
            return false;
        }
        if ($this->hasFinally) {
            return $this->finally !== null && $this->finallyEnd !== null && $this->finallyCall !== null;
        }

        return $this->clauses !== [];
    }

    public function unstructured(string $reason): self
    {
        return new self(
            $this->index,
            $this->try,
            $this->spanEnd,
            $this->hasFinally,
            $this->finally,
            $this->finallyEnd,
            $this->finallyCall,
            $this->catch,
            null,
            $reason,
        );
    }
}
