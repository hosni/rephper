<?php

namespace App\Shapes;

class Widget
{
    public function id(): int
    {
        return 7;
    }
}

class Box extends \stdClass
{
    public Widget $widget;

    public function label(int $n): int
    {
        return $n + 1;
    }
}
