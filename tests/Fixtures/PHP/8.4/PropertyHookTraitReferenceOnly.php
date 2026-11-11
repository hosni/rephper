<?php

trait ReferenceOnly
{
    public int $value = 1 {
        &get => $this->value;
    }
}

class UsesReference
{
    use ReferenceOnly;
}
