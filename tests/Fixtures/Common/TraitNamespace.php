<?php

namespace App\Tools;

trait Marker
{
    public function mark(): string
    {
        return 'mark';
    }
}

class Noted
{
    use Marker;

    public int $count = 1;

    public function extra(): int
    {
        return 4;
    }
}
