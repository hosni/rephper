<?php

#[Attribute]
class Note
{
    public function __construct(public string $message)
    {
    }
}

class Base
{
    public int $id = 1;
}

trait Alpha
{
    public string $fromTrait = 'alpha';

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

/** The composed item. */
class Item extends Base
{
    use Alpha, Beta, Gamma {
        label as title;
        Beta::id as private;
        Alpha::name insteadof Beta, Gamma;
    }

    /** The item title. */
    #[Note('use name()')]
    public string $title = 'item';

    /** The scale. */
    #[Note('unused')]
    public const int SCALE = 1;

    public function name(): string
    {
        return 'item';
    }
}
