<?php

function nested(int $n): int
{
    if ($n > 0) {
        if ($n > 10) {
            return $n;
        }

        return 1;
    }

    return 0;
}

function dowhile(int $n): int
{
    $i = 0;
    do {
        $i++;
    } while ($i < $n);

    return $i;
}

function nestedLoops(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        $j = 0;
        while ($j < $i) {
            if ($j === 1) {
                break 2;
            }
            $j++;
            $total++;
        }
    }

    return $total;
}

function choose(string $n): string
{
    switch ($n) {
        case 'a':
            return 'one';
        case 'b':
        case 'c':
            return 'few';
        default:
            return 'other';
    }
}

function jump(int $n): int
{
    if ($n < 0) {
        goto done;
    }
    $n = 1;
    done:
    return $n;
}

function early(int $n): int
{
    if ($n === 0) {
        return 0;
    }
    if ($n === 1) {
        return 1;
    }

    return $n;
}

function fail(int $n): int
{
    if ($n < 0) {
        throw new Exception('bad');
    }

    return $n;
}

function fallback(?int $n): int
{
    $value = $n ?? 0;
    $other = $n ?: 1;
    static $count = [1, 2][0];
    $count++;
    assert($n !== 2);

    return $value + $other + $count;
}
