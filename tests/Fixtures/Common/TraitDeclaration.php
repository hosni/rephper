<?php

#[Attribute]
class Mark
{
    public function __construct(public string $message)
    {
    }
}

/** Records a label. */
#[Mark('trait')]
trait Label
{
    /** The stored label. */
    #[Mark('property')]
    public string $label = 'label';

    /** The scale. */
    #[Mark('constant')]
    public const int SCALE = 2;

    public function text(): string
    {
        return 'label';
    }
}

class Speaker
{
    use Label;
}
