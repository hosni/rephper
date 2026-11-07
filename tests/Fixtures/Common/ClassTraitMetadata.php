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
    public function label(): string
    {
        return 'beta';
    }

    public function name(): string
    {
        return 'beta';
    }

    public function id(): int
    {
        return 2;
    }
}

/** The composed item. */
class Item extends Base
{
    use Alpha, Beta {
        label as title;
        Beta::id as private;
        Alpha::name insteadof Beta, Alpha;
    }

    /** The item title. */
    #[Note('use name()')]
    public string $title = 'item';

    /** The scale. */
    #[Note('unused')]
    public const int SCALE = 1;

    public function name(): string
    {
        return $this->title;
    }
}
