<?php

#[Attribute]
class Note
{
    public function __construct(public string $text)
    {
    }
}

class Attributed
{
    /** The property. */
    #[Note('property')]
    public int $value = 1 {
        /** The getter. */
        #[Note('get')]
        get => $this->value;
        /** The setter. */
        #[Note('set')]
        set(#[Note('param')] int $value) => $value;
    }
}
