<?php

abstract class Shape
{
    abstract public function area(): int;

    public function scale(int $factor): int
    {
        return $factor + 1;
    }
}

final class Square extends Shape
{
    public function area(): int
    {
        return 4;
    }
}
