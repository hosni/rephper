<?php

function simpleWhile(int $n): int
{
    $i = 0;
    while ($i < $n) {
        $i++;
    }

    return $i;
}

function simpleDo(int $n): int
{
    $i = 0;
    do {
        $i++;
    } while ($i < $n);

    return $i;
}

function simpleFor(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        $total++;
    }

    return $total;
}

function emptyFor(int $n): int
{
    for ($i = 0; $i < $n; $i++) {
    }

    return $i;
}

function simpleForeach(array $values): int
{
    $total = 0;
    foreach ($values as $value) {
        $total += $value;
    }

    return $total;
}

function nestedWhile(int $n): int
{
    $i = 0;
    $total = 0;
    while ($i < $n) {
        $j = 0;
        while ($j < $i) {
            $total++;
            $j++;
        }
        $i++;
    }

    return $total;
}

function nestedMixed(int $n, array $rows): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        foreach ($rows as $row) {
            $total += $row;
        }
    }

    return $total;
}

function multiBack(int $n): int
{
    $i = 0;
    while ($i < $n) {
        if ($i === 1) {
            continue;
        }
        $i++;
    }

    return $i;
}

function broken(int $n): int
{
    $i = 0;
    while ($i < $n) {
        if ($i === 3) {
            break;
        }
        $i++;
    }

    return $i;
}

function continued(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($i === 2) {
            continue;
        }
        $total++;
    }

    return $total;
}

function breakTwo(int $n): int
{
    $total = 0;
    while ($n > 0) {
        $j = 0;
        while ($j < $n) {
            if ($j === 1) {
                break 2;
            }
            $j++;
            $total++;
        }
        $n--;
    }

    return $total;
}

function continueTwo(int $n): int
{
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        $j = 0;
        while ($j < $i) {
            if ($j === 0) {
                continue 2;
            }
            $total++;
            $j++;
        }
    }

    return $total;
}

function conditionalBody(int $n): int
{
    $i = 0;
    $total = 0;
    while ($i < $n) {
        if ($i % 2 === 0) {
            $total++;
        } else {
            $total += 2;
        }
        $i++;
    }

    return $total;
}

function sequential(int $n): int
{
    $i = 0;
    while ($i < $n) {
        $i++;
    }
    $j = 0;
    while ($j < $n) {
        $j++;
    }

    return $i + $j;
}

function backwardGoto(int $n): int
{
    $i = 0;
    start:
    $i++;
    if ($i < $n) {
        goto start;
    }

    return $i;
}

function notALoop(int $n): int
{
    goto mid;
    start:
    $n = 1;
    mid:
    if ($n > 0) {
        goto start;
    }

    return $n;
}

function dead(int $n): int
{
    return 0;
    while ($n > 0) {
        break;
    }
}

function andWhile(int $a, int $b): int
{
    $i = 0;
    while ($a && $i < $b) {
        $i++;
    }

    return $i;
}

function whileTrue(int $n): int
{
    while (true) {
        if ($n === 0) {
            break;
        }
        $n--;
    }

    return $n;
}

function doReturn(int $n): int
{
    $i = 0;
    do {
        if ($n < 0) {
            return 0;
        }
        $i++;
    } while ($i < $n);

    return $i;
}

function doContinue(int $n): int
{
    $i = 0;
    do {
        $i++;
        if ($i === 2) {
            continue;
        }
    } while ($i < $n);

    return $i;
}
