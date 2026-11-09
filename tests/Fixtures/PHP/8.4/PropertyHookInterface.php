<?php

interface Readable
{
    public int $value { get; }
}

interface Pair
{
    public int $value { get; set; }
}

class ImplementsReadable implements Readable
{
    public int $value = 1 {
        get => $this->value;
    }
}

class ImplementsPair implements Pair
{
    public int $value = 1 {
        get => $this->value;
        set => $value;
    }
}
