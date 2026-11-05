<?php

function simple(int $n): int
{
    try {
        operation($n);
    } finally {
        cleanup($n);
    }

    return $n;
}

function caught(int $n): int
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

function multi(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException $exception) {
        recover($exception);
    } catch (LogicException $exception) {
        report($exception);
    } finally {
        cleanup($n);
    }

    return $n;
}

function union(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException|LogicException $exception) {
        recover($exception);
    } finally {
        cleanup($n);
    }

    return $n;
}

function returnInTry(int $n): int
{
    try {
        return $n;
    } finally {
        cleanup($n);
    }
}

function returnInCatch(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException $exception) {
        return 1;
    } finally {
        cleanup($n);
    }

    return 0;
}

function throwInTry(int $n): int
{
    try {
        throw new RuntimeException('bad');
    } finally {
        cleanup($n);
    }
}

function rethrow(int $n): int
{
    try {
        operation($n);
    } catch (RuntimeException $exception) {
        throw $exception;
    } finally {
        cleanup($n);
    }
}

function implicit(int $n): int
{
    try {
        $value = $n / 0;
    } finally {
        cleanup($n);
    }

    return $n;
}

function returnInFinally(int $n): int
{
    try {
        return $n;
    } finally {
        return 1;
    }
}

function throwInFinally(int $n): int
{
    try {
        operation($n);
    } finally {
        throw new LogicException('from-finally');
    }
}

function multipleCalls(int $n): int
{
    try {
        if ($n < 0) {
            return 1;
        }
        operation($n);

        return 0;
    } finally {
        cleanup($n);
    }
}

function breakThrough(int $n): int
{
    while ($n > 0) {
        try {
            break;
        } finally {
            cleanup($n);
        }
    }

    return $n;
}

function continueThrough(int $n): int
{
    while ($n > 0) {
        try {
            continue;
        } finally {
            cleanup($n);
        }
    }

    return $n;
}

function breakTwo(int $n): int
{
    while ($n > 0) {
        while ($n > 0) {
            try {
                break 2;
            } finally {
                cleanup($n);
            }
        }
    }

    return $n;
}

function continueTwo(int $n): int
{
    while ($n > 0) {
        while ($n > 0) {
            try {
                continue 2;
            } finally {
                cleanup($n);
            }
        }
    }

    return $n;
}

function condInFinally(int $n): int
{
    try {
        operation($n);
    } finally {
        if ($n < 0) {
            recover($n);
        } else {
            report($n);
        }
    }

    return $n;
}

function loopInFinally(int $n): int
{
    try {
        operation($n);
    } finally {
        while ($n > 0) {
            cleanup($n);
            if ($n > 10) {
                break;
            }
            $n--;
        }
    }

    return $n;
}

function alwaysBreakInFinally(int $n): int
{
    try {
        operation($n);
    } finally {
        while ($n > 0) {
            cleanup($n);
            break;
        }
    }

    return $n;
}

function nested(int $n): int
{
    try {
        try {
            operation($n);
        } finally {
            innerCleanup($n);
        }
    } catch (RuntimeException $exception) {
        recover($exception);
    } finally {
        outerCleanup($n);
    }

    return $n;
}

function nestedCaught(int $n): int
{
    try {
        try {
            operation($n);
        } catch (RuntimeException $exception) {
            recover($exception);
        } finally {
            innerCleanup($n);
        }
    } catch (LogicException $exception) {
        report($exception);
    } finally {
        outerCleanup($n);
    }

    return $n;
}

function chained(int $n): int
{
    try {
        try {
            return $n;
        } finally {
            innerCleanup($n);
        }
    } finally {
        outerCleanup($n);
    }
}

function tryInLoop(int $n): int
{
    while ($n > 0) {
        try {
            operation($n);
        } finally {
            cleanup($n);
        }
        $n--;
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
    } finally {
        cleanup($n);
    }

    return $n;
}

function tryInFinally(int $n): int
{
    try {
        operation($n);
    } finally {
        try {
            recover($n);
        } catch (RuntimeException $exception) {
            report($exception);
        }
    }

    return $n;
}

function innerFinallyToOuterCatch(int $n): int
{
    try {
        try {
            operation($n);
        } finally {
            throw new RuntimeException('inner');
        }
    } catch (RuntimeException $exception) {
        recover($exception);
    }

    return $n;
}
