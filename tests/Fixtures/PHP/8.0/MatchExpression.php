<?php

function classify(int $value): string
{
    return match ($value) {
        0 => 'zero',
        1, 2 => 'small',
        default => 'other',
    };
}
