<?php

namespace App\Domain;

class ParentBox
{
    public int $value = 1 {
        get => $this->value;
    }
}

class ChildBox extends ParentBox
{
    public int $value = 1 {
        get => parent::$value::get() + 4;
    }

    public function read(): int
    {
        return $this->value;
    }
}
