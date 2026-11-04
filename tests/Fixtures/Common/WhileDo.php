<?php

function emptyWhile(int $n): int
{
    $i = 0;
    while ($i < $n) {
    }

    return $i;
}

function compareWhile(int $n): int
{
    $i = 0;
    while ($i <= $n) {
        $i++;
    }

    return $i;
}

function whileReturn(int $n): int
{
    $i = 0;
    while ($i < $n) {
        if ($i === 1) {
            return $i;
        }
        $i++;
    }

    return $i;
}

function whileThrow(int $n): int
{
    $i = 0;
    while ($i < $n) {
        if ($i === 1) {
            throw new Exception('bad');
        }
        $i++;
    }

    return $i;
}

function whileElseContinue(int $n): int
{
    $i = 0;
    $total = 0;
    while ($i < $n) {
        if ($i === 1) {
            continue;
        } else {
            $total++;
        }
        $i++;
    }

    return $total;
}

function ifWhile(int $n): int
{
    $i = 0;
    if ($n > 0) {
        while ($i < $n) {
            $i++;
        }
    }

    return $i;
}

function emptyDo(int $n): int
{
    $i = 0;
    do {
    } while ($i < $n);

    return $i;
}

function doNeg(int $n): int
{
    $i = 0;
    do {
        $i++;
    } while (!$n);

    return $i;
}

function nestedDo(int $n): int
{
    $i = 0;
    do {
        $j = 0;
        do {
            $j++;
        } while ($j < $i);
        $i++;
    } while ($i < $n);

    return $i;
}

function mixedNest(int $n): int
{
    $i = 0;
    while ($i < $n) {
        $j = 0;
        do {
            $j++;
        } while ($j < 2);
        $i++;
    }

    return $i;
}

function continueTwoWhile(int $n): int
{
    $i = 0;
    $total = 0;
    while ($i < $n) {
        $j = 0;
        while ($j < $n) {
            if ($j === 0) {
                continue 2;
            }
            $total++;
            $j++;
        }
        $i++;
    }

    return $total;
}

function switchBreak(int $value): int
{
    $n = 0;
    while ($n < 3) {
        switch ($value) {
            case 1:
                break;
        }
        $n++;
    }

    return $n;
}

function switchBreakTwo(int $value): int
{
    $n = 0;
    while ($n < 3) {
        switch ($value) {
            case 1:
                break 2;
        }
        $n++;
    }

    return $n;
}

function switchContinue(int $value): int
{
    $n = 0;
    while ($n < 3) {
        switch ($value) {
            case 1:
                continue;
        }
        $n++;
    }

    return $n;
}

function switchContinueTwo(int $value): int
{
    $n = 0;
    while ($n < 3) {
        switch ($value) {
            case 1:
                continue 2;
        }
        $n++;
    }

    return $n;
}

function whileThenFor(int $n): int
{
    $i = 0;
    while ($i < $n) {
        $i++;
    }
    $total = 0;
    for ($j = 0; $j < $n; $j++) {
        $total++;
    }

    return $total;
}

function realSwitch(int $value): int
{
    $n = 0;
    while ($n < 2) {
        switch ($value) {
            case 1:
                $n++;
                break;
            case 2:
                break 2;
            default:
                $n += 3;
        }
    }

    return $n;
}
