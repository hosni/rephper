<?php

function classify(int $value): string
{
    if ($value > 100) {
        return 'high';
    }

    return 'low';
}
