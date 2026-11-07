<?php

class Scale
{
    public const int BASE = 10;

    protected const string NAME = 'scale';

    private const FLAG = true;

    public function apply(int $value): int
    {
        return $value + 1;
    }
}
