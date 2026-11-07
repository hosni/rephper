<?php

declare(strict_types=1);

namespace Rephper\Ir;

final class Document
{
    /**
     * @param list<OpArray> $opArrays
     * @param list<array<string, mixed>> $classes
     * @param list<NamespaceRegion> $namespaces
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly int $irVersion,
        public readonly PhpBuild $php,
        public readonly Compilation $compilation,
        public readonly array $opArrays,
        public readonly array $classes,
        public readonly array $namespaces,
        public readonly array $extra,
    ) {
    }

    public function opArrayNamed(?string $name): ?OpArray
    {
        foreach ($this->opArrays as $opArray) {
            if ($opArray->name === $name) {
                return $opArray;
            }
        }

        return null;
    }
}
