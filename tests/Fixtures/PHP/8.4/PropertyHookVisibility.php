<?php

class Asymmetric
{
    public private(set) int $locked = 1 {
        set => $value;
    }

    public protected(set) int $guarded = 2 {
        get => $this->guarded;
    }

    final public protected(set) int $marked = 3 {
        get => $this->marked;
    }
}

class Visibilities
{
    public int $pub = 1 {
        get => $this->pub;
    }

    protected int $pro = 2 {
        get => $this->pro;
    }

    private int $pri = 3 {
        get => $this->pri;
    }

    public function read(): string
    {
        return $this->pub . $this->pro . $this->pri;
    }
}
