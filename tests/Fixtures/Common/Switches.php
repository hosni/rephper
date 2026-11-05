<?php

function pick(int $value): int
{
    return $value;
}

function simpleInt(int $value): int
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
        case 5:
            return 5;
    }

    return 0;
}

function simpleString(string $value): string
{
    switch ($value) {
        case 'a':
            return 'one';
        case 'b':
        case 'c':
            return 'few';
    }

    return 'other';
}

function withDefault(int $value): int
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

function breakEvery(int $value): int
{
    $n = 0;
    switch ($value) {
        case 1:
            $n = 1;
            break;
        case 2:
            $n = 2;
            break;
        case 3:
            $n = 3;
            break;
        case 4:
            $n = 4;
            break;
        case 5:
            $n = 5;
            break;
    }

    return $n;
}

function sharedLabels(int $value): int
{
    switch ($value) {
        case 1:
        case 2:
            return 2;
        case 3:
        case 4:
            return 4;
        default:
            return 0;
    }
}

function emptyCases(int $value): int
{
    $n = 0;
    switch ($value) {
        case 1:
        case 2:
        case 3:
        case 4:
        case 5:
            break;
    }

    return $n;
}

function fallthrough(int $value): int
{
    $n = 0;
    switch ($value) {
        case 1:
            $n += 1;
        case 2:
            $n += 2;
            break;
        case 3:
            $n += 3;
        case 4:
            $n += 4;
        default:
            $n += 8;
    }

    return $n;
}

function throwInCase(int $value): int
{
    switch ($value) {
        case 1:
            throw new Exception('bad');
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

function nested(int $value, int $other): int
{
    switch ($value) {
        case 1:
            switch ($other) {
                case 1:
                    return 11;
                case 2:
                    return 12;
                case 3:
                    return 13;
                case 4:
                    return 14;
                default:
                    return 10;
            }
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

function defaultFirst(int $value): int
{
    switch ($value) {
        default:
            return 0;
        case 1:
            return 1;
        case 2:
            return 2;
        case 3:
            return 3;
        case 4:
            return 4;
    }
}

function caseOrder(int $value): int
{
    switch ($value) {
        case 30:
            return 30;
        case 2:
            return 2;
        case 10:
            return 10;
        case 7:
            return 7;
        case 100:
            return 100;
    }

    return 0;
}

function callSubject(int $value): int
{
    switch (pick($value)) {
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

function exprSubject(int $value): int
{
    switch ($value + 1) {
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

function switchTrue(int $value): int
{
    switch (true) {
        case 1:
            return 1;
        case 2:
            return 2;
        case 3:
            return 3;
        case 4:
            return 4;
        case 5:
            return 5;
    }

    return $value;
}

function duplicate(int $value): int
{
    switch ($value) {
        case 1:
            return 1;
        case 1:
            return 11;
        case 3:
            return 3;
        case 4:
            return 4;
        case 5:
            return 5;
    }

    return 0;
}

function continueAtEnd(int $n): int
{
    while ($n < 3) {
        switch ($n) {
            case 1:
                continue;
            case 2:
                continue 2;
            case 3:
                $n += 3;
                break;
            case 4:
                $n += 4;
                break;
            default:
                $n += 1;
        }
    }

    return $n;
}

function continueOnly(int $value): int
{
    switch ($value) {
        case 1:
            continue;
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

function breakTwoSwitch(int $value, int $other): int
{
    $n = 0;
    switch ($value) {
        case 1:
            switch ($other) {
                case 1:
                    break 2;
                case 2:
                    $n += 2;
                    break;
                case 3:
                    $n += 3;
                    break;
                case 4:
                    $n += 4;
                    break;
                default:
                    $n += 1;
            }
            $n += 10;
            break;
        case 2:
            $n += 20;
            break;
        case 3:
            $n += 30;
            break;
        case 4:
            $n += 40;
            break;
        default:
            $n += 50;
    }

    return $n;
}

function switchInWhile(int $n): int
{
    while ($n < 3) {
        switch ($n) {
            case 1:
                break;
            case 2:
                break 2;
            case 3:
                $n += 3;
                break;
            case 4:
                $n += 4;
                break;
            default:
                $n += 1;
        }
    }

    return $n;
}

function switchInDo(int $n): int
{
    do {
        switch ($n) {
            case 1:
                break;
            case 2:
                break 2;
            case 3:
                $n += 3;
                break;
            case 4:
                $n += 4;
                break;
            default:
                $n += 1;
        }
    } while ($n < 3);

    return $n;
}

function switchInFor(int $limit): int
{
    $n = 0;
    for ($i = 0; $i < $limit; $i++) {
        switch ($i) {
            case 1:
                $n += 1;
                break;
            case 2:
                $n += 2;
                break;
            case 3:
                $n += 3;
                break;
            case 4:
                break 2;
            default:
                $n += 8;
        }
    }

    return $n;
}

function switchInForeach(array $items): int
{
    $n = 0;
    foreach ($items as $item) {
        switch ($item) {
            case 1:
                $n += 1;
                break;
            case 2:
                $n += 2;
                break;
            case 3:
                break 2;
            case 4:
                $n += 4;
                break;
            default:
                $n += 8;
        }
    }

    return $n;
}

function switchInIf(int $flag, int $value): int
{
    if ($flag > 0) {
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

    return 9;
}

function ifInside(int $value, int $flag): int
{
    switch ($value) {
        case 1:
            if ($flag > 0) {
                return 1;
            } else {
                return 2;
            }
        case 2:
            return 3;
        case 3:
            return 4;
        case 4:
            return 5;
        default:
            return 0;
    }
}

function loopInside(int $value, int $limit, array $items): int
{
    $n = 0;
    switch ($value) {
        case 1:
            for ($i = 0; $i < $limit; $i++) {
                $n += $i;
            }
            break;
        case 2:
            while ($n < $limit) {
                $n++;
            }
            break;
        case 3:
            foreach ($items as $item) {
                $n += $item;
            }
            break;
        case 4:
            $n += 4;
            break;
        default:
            $n += 8;
    }

    return $n;
}

function sequential(int $value): int
{
    $n = 0;
    switch ($value) {
        case 1:
            $n += 1;
            break;
        case 2:
            $n += 2;
            break;
        case 3:
            $n += 3;
            break;
        case 4:
            $n += 4;
            break;
        case 5:
            $n += 5;
            break;
    }
    switch ($value) {
        case 1:
            return $n + 1;
        case 2:
            return $n + 2;
        case 3:
            return $n + 3;
        case 4:
            return $n + 4;
        default:
            return $n;
    }
}

function emptyDefaultLast(int $value): int
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
    }

    return 0;
}

function comparisonChain(int $value): int
{
    switch ($value) {
        case 1:
            return 1;
        default:
            return 0;
    }
}

function ambiguousBreak(int $value): int
{
    $n = 0;
    while ($n < 2) {
        switch (true) {
            case 1:
                break;
            case 2:
                break 2;
            case 3:
                $n++;
                break;
            case 4:
                $n++;
                break;
            case 5:
                $n++;
                break;
        }
    }

    return $n;
}
