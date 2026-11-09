<?php

class SetThenGet
{
    public int $value = 1 {
        set => $value;
        get => $this->value + 1;
    }
}

class OneLine
{
    public int $value = 1 { get => $this->value; set => $value; }
}
