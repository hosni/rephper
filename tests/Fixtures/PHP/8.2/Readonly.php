<?php

readonly class Point
{
    public function __construct(public int $x, public int $y)
    {
    }
}

class Measured
{
    public readonly int $width;

    public function __construct(int $width)
    {
        $this->width = $width;
    }
}
