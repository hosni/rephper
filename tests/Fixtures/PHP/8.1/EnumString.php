<?php

#[Attribute]
class Mark
{
    public function __construct(public string $note = 'n')
    {
    }
}

/** Status values. */
#[Mark('status')]
enum Status: string
{
    /** Open case. */
    #[Deprecated(message: 'old', since: '1.0')]
    #[Mark('open')]
    case Open = 'open';

    case Closed = 'cl' . 'osed';
}
