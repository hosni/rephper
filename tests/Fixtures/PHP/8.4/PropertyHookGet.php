<?php

class GetOnly
{
    public int $value = 1 {
        get => $this->value + 1;
    }
}
