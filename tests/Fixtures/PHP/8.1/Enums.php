<?php

interface Colorful
{
    public function color(): string;
}

enum Status: string implements Colorful
{
    case Open = 'open';
    case Closed = 'closed';

    public function color(): string
    {
        return $this->value;
    }
}
