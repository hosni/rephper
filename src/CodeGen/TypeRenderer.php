<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

/**
 * Renders a type stored on an op array.
 *
 * Zend keeps a class, intersection, or union list on the type and stores
 * builtin parts in the type mask. `display` is `zend_type_to_string()` of
 * that whole type, so it is the complete spelling. `members` lists only the
 * class and intersection parts. This accepts `display` when it agrees with
 * `kind`, `nullable`, `name`, and `members`. A type that cannot be checked
 * is not omitted and is not replaced.
 */
final class TypeRenderer
{
    /** @var list<string> */
    private const BUILTINS = [
        'int', 'float', 'string', 'bool', 'array', 'object', 'callable', 'iterable',
        'mixed', 'void', 'never', 'false', 'true', 'null', 'resource',
    ];

    private const NAME = '/\A\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*/';

    private string $subject = 'type';

    private string $input = '';

    private int $offset = 0;

    public function render(mixed $type, string $subject, int $depth = 0, ?NameQualifier $names = null): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($depth > 8) {
            throw new SourceGenerationException('Cannot generate PHP for ' . $subject . ': the type is nested too deeply.');
        }
        if (!is_array($type) || array_is_list($type)) {
            throw new SourceGenerationException('Cannot generate PHP for ' . $subject . ': the type is not an object.');
        }

        $this->subject = $subject;
        foreach (['display', 'nullable', 'kind'] as $key) {
            if (!array_key_exists($key, $type)) {
                $this->fail($key . ' is missing');
            }
        }
        $display = $type['display'];
        $nullable = $type['nullable'];
        $kind = $type['kind'];
        if (!is_string($display) || $display === '') {
            $this->fail('display is missing');
        }
        if (!is_bool($nullable)) {
            $this->fail('nullable is not a boolean');
        }
        if (!is_string($kind)) {
            $this->fail('kind is missing');
        }

        $name = $type['name'] ?? null;
        if ($name !== null && !is_string($name)) {
            $this->fail('name is not a string');
        }
        $members = $type['members'] ?? null;
        if ($members !== null && (!is_array($members) || !array_is_list($members))) {
            $this->fail('members is not a list');
        }

        $parsed = $this->parse($display);
        $this->assertNullable($nullable, $parsed);
        match ($kind) {
            'builtin' => $this->assertBuiltin($name, $members, $parsed),
            'name' => $this->assertName($name, $members, $parsed),
            'intersection' => $this->assertIntersection($nullable, $members, $parsed, $display, $subject, $depth),
            'union' => $this->assertUnion($members, $parsed, $subject, $depth),
            default => $this->fail('kind ' . $kind . ' is not a PHP type'),
        };

        if ($depth === 0 && $names !== null) {
            return $names->type($display);
        }

        return $display;
    }

    /**
     * @param array{prefixed: bool, parts: list<array{spelling: string, intersection: bool, builtin: bool, names: list<string>}>} $parsed
     */
    private function assertNullable(bool $nullable, array $parsed): void
    {
        $hasNull = $parsed['prefixed'];
        foreach ($parsed['parts'] as $part) {
            if (!$part['intersection'] && $part['spelling'] === 'null') {
                $hasNull = true;
            }
        }
        $onlyMixed = count($parsed['parts']) === 1
            && !$parsed['parts'][0]['intersection']
            && $parsed['parts'][0]['spelling'] === 'mixed';
        if ($nullable !== ($hasNull || $onlyMixed)) {
            $this->fail('nullable does not match the type');
        }
    }

    /**
     * @param list<mixed>|null $members
     * @param array{prefixed: bool, parts: list<array{spelling: string, intersection: bool, builtin: bool, names: list<string>}>} $parsed
     */
    private function assertBuiltin(?string $name, ?array $members, array $parsed): void
    {
        if ($name !== null) {
            $this->fail('a builtin type has a class name');
        }
        if ($members !== null && $members !== []) {
            $this->fail('a builtin type has members');
        }
        foreach ($parsed['parts'] as $part) {
            if ($part['intersection'] || !$part['builtin']) {
                $this->fail('a builtin type contains ' . $part['spelling']);
            }
        }
    }

    /**
     * @param list<mixed>|null $members
     * @param array{prefixed: bool, parts: list<array{spelling: string, intersection: bool, builtin: bool, names: list<string>}>} $parsed
     */
    private function assertName(?string $name, ?array $members, array $parsed): void
    {
        if ($name === null || $name === '') {
            $this->fail('a named type has no name');
        }
        if ($members !== null && $members !== []) {
            $this->fail('a named type has members');
        }
        $classes = [];
        foreach ($parsed['parts'] as $part) {
            if ($part['intersection']) {
                $this->fail('a named type contains an intersection');
            }
            if (!$part['builtin']) {
                $classes[] = $part['spelling'];
            }
        }
        if ($classes !== [$name]) {
            $this->fail('the class name does not match the type');
        }
    }

    /**
     * @param list<mixed>|null $members
     * @param array{prefixed: bool, parts: list<array{spelling: string, intersection: bool, builtin: bool, names: list<string>}>} $parsed
     */
    private function assertIntersection(
        bool $nullable,
        ?array $members,
        array $parsed,
        string $display,
        string $subject,
        int $depth,
    ): void {
        if ($parsed['prefixed'] || $nullable) {
            $this->fail('an intersection type is nullable');
        }
        if (count($parsed['parts']) !== 1 || !$parsed['parts'][0]['intersection']) {
            $this->fail('an intersection type is not only class names');
        }
        if ($members === null || count($members) < 2) {
            $this->fail('an intersection type has no members');
        }
        if (count($members) !== count($parsed['parts'][0]['names'])) {
            $this->fail('intersection members do not match the type');
        }
        if ($parsed['parts'][0]['spelling'] !== $display) {
            $this->fail('intersection display does not match its members');
        }
        foreach ($members as $index => $member) {
            $rendered = $this->render($member, $subject . ' member', $depth + 1);
            $this->subject = $subject;
            if ($rendered !== $parsed['parts'][0]['names'][$index]) {
                $this->fail('intersection members do not match the type');
            }
        }
    }

    /**
     * @param list<mixed>|null $members
     * @param array{prefixed: bool, parts: list<array{spelling: string, intersection: bool, builtin: bool, names: list<string>}>} $parsed
     */
    private function assertUnion(?array $members, array $parsed, string $subject, int $depth): void
    {
        if ($parsed['prefixed']) {
            $this->fail('a union type uses a nullable prefix');
        }
        if ($members === null || $members === []) {
            $this->fail('a union type has no members');
        }
        $unused = $parsed['parts'];
        foreach ($members as $member) {
            $rendered = $this->render($member, $subject . ' member', $depth + 1);
            $this->subject = $subject;
            $found = false;
            foreach ($unused as $index => $part) {
                if ($part['spelling'] === $rendered) {
                    unset($unused[$index]);
                    $found = true;
                    break;
                }
            }
            if (!$found || !is_string($rendered)) {
                $this->fail('a union member is not in the type');
            }
        }
        foreach ($unused as $part) {
            if ($part['intersection'] || !$part['builtin']) {
                $this->fail('the type contains ' . $part['spelling'] . ', which is not in the IR members or builtin mask');
            }
        }
    }

    /**
     * @return array{prefixed: bool, parts: list<array{spelling: string, intersection: bool, builtin: bool, names: list<string>}>}
     */
    private function parse(string $display): array
    {
        $this->input = $display;
        $this->offset = 0;
        $prefixed = $this->eat('?');
        $parts = [$this->intersection()];
        while ($this->eat('|')) {
            $parts[] = $this->intersection();
        }
        if ($this->offset !== strlen($this->input)) {
            $this->fail('display is not a PHP type');
        }
        if ($prefixed && (count($parts) !== 1 || $parts[0]['intersection'])) {
            $this->fail('nullable prefix is not a single type');
        }

        return ['prefixed' => $prefixed, 'parts' => $parts];
    }

    /**
     * @return array{spelling: string, intersection: bool, builtin: bool, names: list<string>}
     */
    private function intersection(): array
    {
        $part = $this->primary();
        if ($this->peek() !== '&') {
            return $part;
        }
        if ($part['intersection'] || $part['builtin']) {
            $this->fail('display is not a PHP type');
        }
        $names = [$part['spelling']];
        while ($this->eat('&')) {
            $next = $this->primary();
            if ($next['intersection'] || $next['builtin']) {
                $this->fail('display is not a PHP type');
            }
            $names[] = $next['spelling'];
        }

        return [
            'spelling' => implode('&', $names),
            'intersection' => true,
            'builtin' => false,
            'names' => $names,
        ];
    }

    /**
     * @return array{spelling: string, intersection: bool, builtin: bool, names: list<string>}
     */
    private function primary(): array
    {
        if ($this->eat('(')) {
            $part = $this->intersection();
            if ($this->peek() === '|') {
                $this->fail('display is not a PHP type');
            }
            if (!$this->eat(')')) {
                $this->fail('display is not a PHP type');
            }
            if (!$part['intersection']) {
                $this->fail('display is not a PHP type');
            }

            return $part;
        }

        $rest = substr($this->input, $this->offset);
        if (preg_match(self::NAME, $rest, $match) !== 1) {
            $this->fail('display is not a PHP type');
        }
        $this->offset += strlen($match[0]);
        $builtin = in_array($match[0], self::BUILTINS, true);

        return [
            'spelling' => $match[0],
            'intersection' => false,
            'builtin' => $builtin,
            'names' => [$match[0]],
        ];
    }

    private function eat(string $character): bool
    {
        if ($this->peek() !== $character) {
            return false;
        }
        $this->offset++;

        return true;
    }

    private function peek(): string
    {
        return $this->offset < strlen($this->input) ? $this->input[$this->offset] : '';
    }

    private function fail(string $reason): never
    {
        throw new SourceGenerationException('Cannot generate PHP for ' . $this->subject . ': ' . $reason . '.');
    }
}
