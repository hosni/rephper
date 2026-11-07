<?php

class Bucket
{
    public $open;

    public $openNull = null;

    public int $count = 2;

    protected string $label = 'bucket';

    private bool $ready = true;

    public static int $total = 4;

    public ?int $maybe = null;

    public int $bare;

    public function extra(int $value): int
    {
        return $value + 1;
    }
}

$mark = 1;
