<?php

function process(mixed $value): void
{
}

function processKey(mixed $key, mixed $value): void
{
}

function getItems(): array
{
    return [1, 2, 3];
}

function simple(array $items): void
{
    foreach ($items as $value) {
        process($value);
    }
}

function withKey(array $items): void
{
    foreach ($items as $key => $value) {
        processKey($key, $value);
    }
}

function byRef(array $items): void
{
    foreach ($items as &$value) {
        $value++;
    }
}

function keyRef(array $items): void
{
    foreach ($items as $key => &$value) {
        $value++;
    }
}

function callIterable(): void
{
    foreach (getItems() as $value) {
        process($value);
    }
}

function emptyBody(array $items): void
{
    foreach ($items as $value) {
    }
}

function ifElse(array $items): void
{
    foreach ($items as $value) {
        if ($value > 0) {
            process($value);
        } else {
            process($value);
        }
    }
}

function nested(array $rows): void
{
    foreach ($rows as $row) {
        foreach ($row as $value) {
            process($value);
        }
    }
}

function foreachInFor(int $n, array $rows): void
{
    for ($i = 0; $i < $n; $i++) {
        foreach ($rows as $row) {
            process($row);
        }
    }
}

function forInForeach(array $rows): void
{
    foreach ($rows as $row) {
        for ($i = 0; $i < $row; $i++) {
            process($i);
        }
    }
}

function foreachInWhile(array $groups): void
{
    while ($groups !== []) {
        $group = array_shift($groups);
        foreach ($group as $value) {
            process($value);
        }
    }
}

function whileInForeach(array $rows): void
{
    foreach ($rows as $row) {
        $j = 0;
        while ($j < $row) {
            process($j);
            $j++;
        }
    }
}

function foreachInDo(array $groups): void
{
    do {
        $group = array_shift($groups);
        foreach ($group as $value) {
            process($value);
        }
    } while ($groups !== []);
}

function doInForeach(array $rows): void
{
    foreach ($rows as $row) {
        $j = 0;
        do {
            process($j);
            $j++;
        } while ($j < $row);
    }
}

function breakContinue(array $items): void
{
    foreach ($items as $value) {
        if ($value < 0) {
            continue;
        }
        if ($value === 0) {
            break;
        }
        process($value);
    }
}

function breakTwo(array $rows): void
{
    foreach ($rows as $row) {
        foreach ($row as $value) {
            if ($value === 0) {
                break 2;
            }
            process($value);
        }
    }
}

function continueTwo(array $rows): void
{
    foreach ($rows as $row) {
        foreach ($row as $value) {
            if ($value < 0) {
                continue 2;
            }
            process($value);
        }
    }
}

function earlyReturn(array $items): int
{
    foreach ($items as $value) {
        if ($value < 0) {
            return $value;
        }
    }

    return 0;
}

function thrown(array $items): void
{
    foreach ($items as $value) {
        if ($value < 0) {
            throw new Exception('bad');
        }
    }
}

function destructure(array $pairs): void
{
    foreach ($pairs as [$key, $value]) {
        processKey($key, $value);
    }
}

function listAssign(array $pairs): void
{
    foreach ($pairs as $key => [$value, $extra]) {
        processKey($key, $value);
    }
}

function refAfter(array $items): mixed
{
    foreach ($items as &$value) {
        $value++;
    }

    return $value;
}

function sequential(array $left, array $right): void
{
    foreach ($left as $value) {
        process($value);
    }
    foreach ($right as $value) {
        process($value);
    }
}

function foreachInIf(array $items, bool $go): void
{
    if ($go) {
        foreach ($items as $value) {
            process($value);
        }
    } else {
        process($items);
    }
}

function destructureInFor(int $n, array $pairs): void
{
    for ($i = 0; $i < $n; $i++) {
        foreach ($pairs as [$key, $value]) {
            processKey($key, $value);
        }
    }
}
