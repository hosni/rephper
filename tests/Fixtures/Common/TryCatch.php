<?php

function simple(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException $exception) {
        recover($exception);
    }

    return $n;
}

function multi(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException $exception) {
        recover($exception);
    } catch (LogicException $exception) {
        report($exception);
    }

    return $n;
}

function union(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException|LogicException $exception) {
        recover($exception);
    }

    return $n;
}

function mixed(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException|LogicException $exception) {
        recover($exception);
    } catch (Error $error) {
        report($error);
    }

    return $n;
}

function noVar(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException) {
        recover();
    }

    return $n;
}

function emptyCatch(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException) {
    }

    return $n;
}

function splitEmpty(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException) {
    } catch (LogicException) {
    }

    return $n;
}

function throwableCatch(int $n): int
{
    try {
        operation($n);
    } catch (Throwable $exception) {
        recover($exception);
    }

    return $n;
}

function explicitThrow(int $n): int
{
    try {
        throw new RuntimeException('bad');
    } catch (RuntimeException $exception) {
        recover($exception);
    }

    return $n;
}

function implicit(int $n): int
{
    try {
        $value = $n / 0;
        strlen($value);
    } catch (DivisionByZeroError $exception) {
        recover($exception);
    }

    return $n;
}

function returnInTry(int $n): int
{
    try {
        if ($n < 0) {
            return 1;
        }
        operation($n);
    } catch (RuntimeException $exception) {
        recover($exception);
    }

    return 0;
}

function returnInCatch(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException $exception) {
        return 1;
    }

    return 0;
}

function throwInCatch(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException $exception) {
        throw new LogicException('again');
    }

    return $n;
}

function rethrow(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException $exception) {
        throw $exception;
    }

    return $n;
}

function condInTry(int $n): int
{
    try {
        if ($n < 0) {
            operation($n);
        } else {
            recover($n);
        }
    } catch (RuntimeException $exception) {
        report($exception);
    }

    return $n;
}

function condInCatch(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException $exception) {
        if ($n < 0) {
            recover($exception);
        } else {
            report($exception);
        }
    }

    return $n;
}

function ifAroundTry(int $n): int
{
    if ($n < 0) {
        try {
            operation($n);
        } catch (RuntimeException $exception) {
            recover($exception);
        }
    } else {
        report($n);
    }

    return $n;
}

function loopInTry(int $n): int
{
    try {
        while ($n > 0) {
            operation($n);
            $n--;
        }
    } catch (RuntimeException $exception) {
        recover($exception);
    }

    return $n;
}

function loopInCatch(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException $exception) {
        while ($n > 0) {
            recover($exception);
            $n--;
        }
    }

    return $n;
}

function tryInWhile(int $n): int
{
    while ($n > 0) {
        try {
            operation($n);
        } catch (RuntimeException $exception) {
            recover($exception);
        }
        $n--;
    }

    return $n;
}

function tryInFor(int $n): int
{
    for ($i = 0; $i < $n; $i++) {
        try {
            operation($i);
        } catch (RuntimeException $exception) {
            recover($exception);
        }
    }

    return $n;
}

function tryInForeach(array $items): int
{
    foreach ($items as $item) {
        try {
            operation($item);
        } catch (RuntimeException $exception) {
            recover($exception);
        }
    }

    return count($items);
}

function nested(int $n): int
{
    try {
        try {
            operation($n);
        } catch (RuntimeException $inner) {
            recover($inner);
        }
    } catch (LogicException $outer) {
        report($outer);
    }

    return $n;
}

function innerToOuter(int $n): int
{
    try {
        try {
            operation($n);
        } catch (RuntimeException $inner) {
            throw new LogicException('wrapped');
        }
    } catch (LogicException $outer) {
        report($outer);
    }

    return $n;
}

function switchInCatch(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException $exception) {
        switch ($n) {
            case 1:
                recover($exception);
                break;
            case 2:
                report($exception);
                break;
            case 3:
                log($exception);
                break;
            case 4:
                recover($exception);
                break;
            case 5:
                report($exception);
                break;
            default:
                log($exception);
                break;
        }
    }

    return $n;
}

function matchInCatch(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException $exception) {
        $label = match ($n) {
            1 => 'one',
            2 => 'two',
            default => 'other',
        };
        recover($label);
    }

    return $n;
}

function tryFinally(int $n): int
{
    try {
        operation($n);
    } finally {
        cleanup($n);
    }

    return $n;
}

function tryCatchFinally(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException $exception) {
        recover($exception);
    } finally {
        cleanup($n);
    }

    return $n;
}

function ambiguous(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException) {} catch (LogicException) {}

    return $n;
}

function containsFinally(int $n): int
{
    try {
        try {
            operation($n);
        } finally {
            cleanup($n);
        }
    } catch (RuntimeException $exception) {
        recover($exception);
    }

    return $n;
}

function sibling(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException $exception) {
        recover($exception);
    }
    try {
        operation($n);
    } finally {
        cleanup($n);
    }

    return $n;
}
