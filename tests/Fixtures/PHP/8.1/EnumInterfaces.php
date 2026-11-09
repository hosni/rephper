<?php

interface Colorful
{
    public function color(): string;
}

enum Paint: string implements Colorful, \UnitEnum
{
    case Red = 'red';

    public function color(): string
    {
        return 'red';
    }
}
