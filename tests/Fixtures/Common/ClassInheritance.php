<?php

$before = 1;

interface Named
{
    public function name(): string;
}

class Base
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}

class Item extends Base implements Named
{
    public function name(): string
    {
        return 'item';
    }

    public function mul(int $a, int $b): int
    {
        return $a * $b;
    }
}

$after = $before + 1;
