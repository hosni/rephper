<?php

trait VirtualReference
{
    public int $store = 1;

    public int $value {
        &get => $this->store;
    }
}

class VirtualParent
{
    use VirtualReference;
}

class VirtualChild extends VirtualParent
{
    public int $value {
        set {
            $this->store = $value;
        }
    }
}
