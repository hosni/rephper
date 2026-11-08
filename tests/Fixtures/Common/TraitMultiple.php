<?php

trait One
{
    public function one(): string
    {
        return 'one';
    }
}

trait Two
{
    public function two(): string
    {
        return 'two';
    }
}

class Pair
{
    use One, Two;
}

class Box
{
    use One;

    public string $name = 'box';

    public function label(): string
    {
        return 'box';
    }
}
