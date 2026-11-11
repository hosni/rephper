<?php

trait ReferenceGet
{
    public function label(): string
    {
        return 'get';
    }

    public int $value = 1 {
        &get => $this->value;
    }
}

trait ValueSet
{
    public function label(): string
    {
        return 'set';
    }

    public int $value = 1 {
        set => $value;
    }
}

class Adapted
{
    use ReferenceGet, ValueSet {
        ReferenceGet::label insteadof ValueSet;
        ValueSet::label as setLabel;
    }
}
