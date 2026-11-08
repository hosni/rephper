<?php

trait Alpha
{
    public function label(): string
    {
        return 'alpha';
    }

    public function name(): string
    {
        return 'alpha';
    }
}

trait Beta
{
    public function name(): string
    {
        return 'beta';
    }

    public function id(): int
    {
        return 2;
    }
}

trait Gamma
{
    public function name(): string
    {
        return 'gamma';
    }
}

class Item
{
    use Alpha, Beta, Gamma {
        label as title;
        Beta::id as private;
        Alpha::label as heading;
        Alpha::name insteadof Beta, Gamma;
    }

    public string $caption = 'item';

    public function caption(): string
    {
        return 'item';
    }
}
