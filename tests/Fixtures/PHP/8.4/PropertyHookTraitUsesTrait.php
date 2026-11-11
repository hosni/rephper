<?php

trait ReferenceGet
{
    public int $value = 1 {
        &get => $this->value;
    }
}

trait AddsSet
{
    use ReferenceGet;

    public int $value = 1 {
        set => $value;
    }
}

class UsesAdded
{
    use AddsSet;
}
