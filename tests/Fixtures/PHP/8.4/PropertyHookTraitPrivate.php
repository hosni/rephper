<?php

class PrivateParent
{
    private int $value = 1 {
        &get => $this->value;
    }
}

class PublicChild extends PrivateParent
{
    public int $value = 1 {
        set => $value;
    }
}
