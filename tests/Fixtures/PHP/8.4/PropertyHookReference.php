<?php

class ByRef
{
    public int $value = 1 {
        &get => $this->value;
    }
}

class FinalRef
{
    public int $value = 1 {
        final &get => $this->value;
    }
}
