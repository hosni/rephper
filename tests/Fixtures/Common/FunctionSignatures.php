<?php

function calculator(int $a, int $b): int
{
    return $a + $b;
}

function plain($a, $b)
{
    return $a;
}

function nullable(?int $a, int|null $b): ?string
{
    return null;
}

function unions(int|string $value): int|string|null
{
    return null;
}

function intersections(\Traversable&\Countable $value): \Traversable&\Countable
{
    return $value;
}

function dnf((\Traversable&\Countable)|int $value): (\Traversable&\Countable)|null
{
    return null;
}

function both((\Traversable&\Countable)|(\Iterator&\Stringable)|int|null $value): void
{
}

function falseType(false $value): false
{
    return $value;
}

function truthy(true $value): true
{
    return $value;
}

function mixedish(mixed $value): mixed
{
    return $value;
}

function voidish(): void
{
}

function iterate(iterable $values): iterable
{
    return $values;
}

function neverReturn(\Throwable $error): never
{
    throw $error;
}

function refs(int &$a, string &...$rest): void
{
    $a = 1;
}

function &refReturn(array &$items): array
{
    return $items;
}

function collect(int $first, string ...$parts): int
{
    return $first;
}

function defaults(
    $none = null,
    bool $flag = true,
    int $count = 2,
    float $ratio = 1.5,
    string $label = 'ok',
    array $items = [1, 'a'],
    int $max = PHP_INT_MAX,
    string $eol = PHP_EOL,
    int $next = PHP_INT_MAX + 1,
    mixed $fallback = PHP_EOL ?? 'x',
    ?int $maybe = null,
): void {
}

#[\Deprecated(message: 'old', since: '1.0')]
function marked(#[\SensitiveParameter] string $secret = 'x'): void
{
}

function nestedFlow(int $n): int
{
    if ($n < 0) {
        return 0;
    }
    $items = [1, 2];
    $total = 0;
    foreach ($items as $item) {
        $total += $item;
    }

    return $total;
}

function calls(int $a, int $b): int
{
    return calculator($a, $b);
}

function matched(int $n): string
{
    return match ($n) {
        1 => 'one',
        2, 3 => 'few',
        default => 'other',
    };
}

function switched(int $value): int
{
    switch ($value) {
        case 1:
            return 1;
        case 2:
            return 2;
        case 3:
            return 3;
        case 4:
            return 4;
        default:
            return 0;
    }
}

function handled(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException $exception) {
        report($exception);

        return 0;
    } finally {
        cleanup($n);
    }

    return $n;
}

$result = calculator(1, 2);
