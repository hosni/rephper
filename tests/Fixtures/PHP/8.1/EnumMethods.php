<?php

enum Counter
{
    case One;

    case Two;

    public function next(int $n): int
    {
        return $n + 1;
    }

    private static function code(): int
    {
        return 7;
    }
}
