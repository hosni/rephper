<?php

declare(strict_types=1);

namespace Rephper\Ir;

/**
 * One user class, interface, trait, or enum from the compiled file.
 *
 * Methods are not nested here. They are op arrays whose scope names this
 * declaration. Properties and constants are only those declared on this type.
 * `parent` and `interfaces` are the direct names even when `linked` is absent.
 * Trait aliases and precedences are the class entry's adaptation lists.
 * Several `use` blocks are flattened into those lists.
 */
final class ClassDeclaration
{
    /**
     * @param list<string> $flags
     * @param list<string> $interfaces
     * @param list<string> $traits
     * @param list<TraitAlias> $traitAliases
     * @param list<TraitPrecedence> $traitPrecedences
     * @param list<ClassProperty> $properties
     * @param list<ClassConstant> $constants
     * @param list<array<string, mixed>> $attributes
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly array $flags,
        public readonly ?string $parent,
        public readonly array $interfaces,
        public readonly array $traits,
        public readonly array $traitAliases,
        public readonly array $traitPrecedences,
        public readonly string $filename,
        public readonly int $lineStart,
        public readonly int $lineEnd,
        public readonly ?string $enumBacking,
        public readonly array $properties,
        public readonly array $constants,
        public readonly array $attributes,
        public readonly ?string $docComment,
        public readonly array $extra,
    ) {
    }
}
