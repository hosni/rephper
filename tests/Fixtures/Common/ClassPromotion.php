<?php

class Point
{
    public function __construct(public readonly int $x, public int $y = 2, private int $z = 3)
    {
    }

    public function extra(int $value): int
    {
        return $value + 1;
    }
}
