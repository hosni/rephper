<?php

function guard(int $value): int
{
    try {
        if ($value < 0) {
            throw new Exception('negative');
        }
    } catch (Exception $exception) {
        return 0;
    } finally {
        $value = 1;
    }

    return $value;
}
