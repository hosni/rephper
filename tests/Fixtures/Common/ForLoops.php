<?php

function measure(string $text): int
{
    return strlen($text);
}

function check(int $i, int $n): bool
{
    return $i < $n;
}

function step(int $i): int
{
    return $i + 1;
}

function process(string $line): void
{
}

function simple(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        $total++;
    }

    return $total;
}

function emptyInit(int $n): int
{
    $i = 0;
    for (; $i < $n; $i++) {
        $i++;
    }

    return $i;
}

function emptyUpdate(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n;) {
        $total++;
        $i++;
    }

    return $total;
}

function multiInit(int $n): int
{
    $total = 0;
    for ($i = 0, $j = 1; $j < $n; $i++, $j++) {
        $total++;
    }

    return $total;
}

function multiUpdate(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++, $n--) {
        $total++;
    }

    return $total;
}

function assignCond(int $n): int
{
    $ok = false;
    $total = 0;
    for ($i = 0; $ok = $i < $n; $i++) {
        $total++;
    }

    return $total;
}

function callInit(string $text, int $n): int
{
    $total = 0;
    for ($i = measure($text); $i < $n; $i++) {
        $total++;
    }

    return $total;
}

function callCond(int $n): int
{
    $total = 0;
    for ($i = 0; check($i, $n); $i++) {
        $total++;
    }

    return $total;
}

function callUpdate(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i = step($i)) {
        $total++;
    }

    return $total;
}

function breakContinue(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($i === 1) {
            continue;
        }
        if ($i === 4) {
            break;
        }
        $total++;
    }

    return $total;
}

function breakTwo(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            if ($j === 1) {
                break 2;
            }
            $total++;
        }
    }

    return $total;
}

function continueTwo(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            if ($j === 0) {
                continue 2;
            }
            $total++;
        }
    }

    return $total;
}

function ifElse(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($i % 2 === 0) {
            $total++;
        } else {
            $total += 2;
        }
    }

    return $total;
}

function nestedFor(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $i; $j++) {
            $total++;
        }
    }

    return $total;
}

function forInWhile(int $n): int
{
    $i = 0;
    $total = 0;
    while ($i < $n) {
        for ($j = 0; $j < $i; $j++) {
            $total++;
        }
        $i++;
    }

    return $total;
}

function whileInFor(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        $j = 0;
        while ($j < $i) {
            $total++;
            $j++;
        }
    }

    return $total;
}

function doInFor(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        $j = 0;
        do {
            $total++;
            $j++;
        } while ($j < $i);
    }

    return $total;
}

function earlyReturn(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($i === 1) {
            return $total;
        }
        $total++;
    }

    return $total;
}

function thrown(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($i === 1) {
            throw new Exception('bad');
        }
        $total++;
    }

    return $total;
}

function whileAssign($handle): int
{
    $total = 0;
    while ($line = fgets($handle)) {
        process($line);
        $total++;
    }

    return $total;
}

function omitted(int $n): int
{
    $i = 0;
    for (;;) {
        if ($i === $n) {
            break;
        }
        $i++;
    }

    return $i;
}

function writtenTrue(int $n): int
{
    $i = 0;
    for (; true;) {
        if ($i === $n) {
            break;
        }
        $i++;
    }

    return $i;
}

function omittedUpdate(int $n): int
{
    $total = 0;
    for ($i = 0; ; $i++) {
        if ($i === $n) {
            break;
        }
        $total++;
    }

    return $total;
}

function ambiguous(array $items): int
{
    $n = 0;
    for ($i = 0; $value = $items[$i] ?? null; $i++) {
        $n++;
    }

    return $n;
}

function multilineInit(int $n): int
{
    $total = 0;
    for (
        $i = 0,
        $j = 1;
        $j < $n;
        $i++, $j++
    ) {
        $total++;
    }

    return $total;
}

function sameLine(int $n): int
{
    $total = 0; for ($i = 0; $i < $n; $i++) {
        $total++;
    }

    return $total;
}

function foreachInside(int $n, array $rows): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        foreach ($rows as $row) {
            $total += $row;
        }
    }

    return $total;
}
