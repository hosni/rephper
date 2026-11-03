<?php

interface Named
{
    public function name(): string;
}

trait Label
{
    public function label(): string
    {
        return 'label';
    }
}

class Base
{
    public int $id = 1;

    public function id(): int
    {
        return $this->id;
    }
}

class Item extends Base implements Named
{
    use Label;

    public string $title = 'item';

    public function name(): string
    {
        return $this->title;
    }
}
