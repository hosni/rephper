<?php

declare(strict_types=1);

namespace Rephper\Ir;

final class OpArray
{
    /**
     * @param list<string> $flags
     * @param list<array<string, mixed>> $arguments
     * @param list<string> $variables
     * @param list<Literal> $literals
     * @param list<array<string, mixed>>|null $staticVariables
     * @param list<TryCatch> $tryCatch
     * @param list<array<string, mixed>> $liveRanges
     * @param list<BreakContinue>|null $breaks
     * @param list<Label>|null $labels
     * @param list<int|null> $dynamicFunctions
     * @param list<array<string, mixed>> $attributes
     * @param list<Instruction> $instructions
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly ?string $name,
        public readonly ?string $namespace,
        public readonly string $filename,
        public readonly int $lineStart,
        public readonly int $lineEnd,
        public readonly ?Scope $scope,
        public readonly ?PropertyHook $propertyHook,
        public readonly array $flags,
        public readonly int $numArgs,
        public readonly int $requiredNumArgs,
        public readonly ?array $returnType,
        public readonly array $arguments,
        public readonly array $variables,
        public readonly int $temporaryVariables,
        public readonly array $literals,
        public readonly ?array $staticVariables,
        public readonly array $tryCatch,
        public readonly array $liveRanges,
        public readonly ?array $breaks,
        public readonly ?array $labels,
        public readonly array $dynamicFunctions,
        public readonly array $attributes,
        public readonly ?string $docComment,
        public readonly array $instructions,
        public readonly array $extra,
    ) {
    }

    public function instructionCount(): int
    {
        return count($this->instructions);
    }

    public function instruction(int $index): ?Instruction
    {
        return $this->instructions[$index] ?? null;
    }

    public function literal(int $index): ?Literal
    {
        return $this->literals[$index] ?? null;
    }
}
