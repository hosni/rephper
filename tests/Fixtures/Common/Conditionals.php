<?php

function plainIf(int $a): int
{
    $x = 0;
    if ($a) {
        $x = 1;
    }

    return $x;
}

function ifElse(int $a): int
{
    if ($a) {
        $x = 1;
    } else {
        $x = 2;
    }

    return $x;
}

function ifElseIf(int $a): int
{
    if ($a === 1) {
        $x = 1;
    } elseif ($a === 2) {
        $x = 2;
    } else {
        $x = 3;
    }

    return $x;
}

function manyElseIf(int $a): int
{
    if ($a === 1) {
        $x = 1;
    } elseif ($a === 2) {
        $x = 2;
    } elseif ($a === 3) {
        $x = 3;
    } else {
        $x = 4;
    }

    return $x;
}

function nestedIf(int $a, int $b): int
{
    if ($a) {
        if ($b) {
            $x = 1;
        } else {
            $x = 2;
        }
    } else {
        $x = 3;
    }

    return $x;
}

function emptyThen(int $a): int
{
    $x = 0;
    if ($a) {
    } else {
        $x = 1;
    }

    return $x;
}

function emptyElse(int $a): int
{
    if ($a) {
        $x = 1;
    } else {
    }

    return 0;
}

function emptyIf(int $a): int
{
    if ($a) {
    }

    return 0;
}

function returnTrue(int $a): int
{
    if ($a) {
        return 1;
    }

    return 0;
}

function returnBoth(int $a): int
{
    if ($a) {
        return 1;
    }

    return 2;
}

function returnBothElse(int $a): int
{
    if ($a) {
        return 1;
    } else {
        return 2;
    }
}

function thrown(int $a): int
{
    if ($a < 0) {
        throw new Exception('bad');
    }

    return $a;
}

function compared(int $a, int $b): int
{
    if ($a > $b) {
        return 1;
    }

    return 0;
}

function comparedEqual(int $a, int $b): int
{
    if ($a === $b) {
        return 1;
    }

    return 0;
}

function variable(int $a): int
{
    if ($a) {
        return 1;
    }

    return 0;
}

function negated(int $a): int
{
    if (!$a) {
        return 1;
    }

    return 0;
}

function sequential(int $a, int $b): int
{
    $x = 0;
    if ($a) {
        $x = 1;
    }
    if ($b) {
        $x = 2;
    }

    return $x;
}

function andCond(int $a, int $b): int
{
    if ($a && $b) {
        return 1;
    }

    return 0;
}

function orCond(int $a, int $b): int
{
    if ($a || $b) {
        return 1;
    }

    return 0;
}

function elseThenIf(int $a, int $b): int
{
    if ($a) {
        $x = 1;
    } else {
        $y = 2;
        if ($b) {
            $x = 3;
        } else {
            $x = 4;
        }
    }

    return $x;
}

function elseAssign(int $a): int
{
    if ($a) {
        return 1;
    } else {
        $x = 2;
    }

    return $x;
}

function elseifNoElse(int $a): int
{
    if ($a === 1) {
        $x = 1;
    } elseif ($a === 2) {
        $x = 2;
    }

    return $x;
}

function counted(int $n): int
{
    $i = 0;
    while ($i < $n) {
        $i++;
    }

    return $i;
}

function choose(string $n): string
{
    switch ($n) {
        case 'a':
            return 'one';
        default:
            return 'other';
    }
}

function jumped(int $n): int
{
    if ($n < 0) {
        goto done;
    }
    $n = 1;
    done:
    return $n;
}
