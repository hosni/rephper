<?php

trait OrdinaryHooks
{
    public int $value = 1 {
        get => $this->value;
        set => $value;
    }
}

class UsesOrdinary
{
    use OrdinaryHooks;
}
