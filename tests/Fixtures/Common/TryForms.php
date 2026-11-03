<?php

function caught(int $n): int
{
    try {
        if ($n < 0) {
            throw new RuntimeException('bad');
        }
    } catch (RuntimeException $runtime) {
        return 1;
    } catch (Exception $exception) {
        return 2;
    }

    return 0;
}

function finalized(int $n): int
{
    try {
        if ($n < 0) {
            throw new Exception('bad');
        }

        return $n;
    } finally {
        $n = 1;
    }
}

function wrapped(int $n): int
{
    try {
        try {
            if ($n < 0) {
                throw new RuntimeException('bad');
            }
        } catch (RuntimeException $inner) {
            throw new LogicException('wrapped');
        }
    } catch (LogicException $outer) {
        return 1;
    }

    return 0;
}
