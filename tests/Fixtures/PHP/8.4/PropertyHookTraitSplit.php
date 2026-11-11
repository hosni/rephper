<?php

trait ReferenceGet
{
    public int $value = 1 {
        &get => $this->value;
    }
}

trait ValueSet
{
    public int $value = 1 {
        set => $value;
    }
}

class SplitHooks
{
    use ReferenceGet, ValueSet;
}
