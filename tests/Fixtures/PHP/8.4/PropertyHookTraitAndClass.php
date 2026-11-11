<?php

trait ReferenceGet
{
    public int $value = 1 {
        &get => $this->value;
    }
}

class AddsSet
{
    use ReferenceGet;

    public int $value = 1 {
        set => $value;
    }
}
