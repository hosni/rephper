<?php

declare(strict_types=1);

namespace Rephper\Cfg;

/**
 * One try/catch row located in an op array.
 *
 * `try` is the protected region's first opline. `spanEnd` is the first
 * opline after the whole try/catch statement, where normal execution
 * continues. `clauses` is null when the row is not reconstructed. `reason`
 * says why. A row that contains a finally handler is not reconstructed.
 * The value does not own CFG blocks and does not follow exception edges.
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
        public readonly ?int $catch,
        public readonly ?array $clauses,
        public readonly ?string $reason,
    ) {
    }

    public function isStructured(): bool
    {
        return $this->reason === null && $this->clauses !== null && !$this->hasFinally;
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
            $this->catch,
            null,
            $reason,
        );
    }
}
