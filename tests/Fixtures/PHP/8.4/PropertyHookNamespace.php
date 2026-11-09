<?php

namespace App\Domain;

class Box
{
    public int $value = 1 {
        get => $this->value;
    }
}

class Child extends Box
{
    public int $value = 1 {
        set => $value + 1;
    }
}
