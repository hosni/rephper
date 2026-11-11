<?php

trait ReferenceGet
{
    public int $value = 1 {
        &get => $this->value;
    }
}

class ReferenceParent
{
    use ReferenceGet;
}

class ReferenceChild extends ReferenceParent
{
    public int $value = 1 {
        set => $value;
    }
}
