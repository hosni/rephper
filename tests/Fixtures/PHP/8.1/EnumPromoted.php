<?php

enum Token
{
    case One;

    public function __construct(public int $value)
    {
    }
}
