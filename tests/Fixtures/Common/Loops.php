<?php

function calculateTotal(array $values): int
{
    $total = 0;

    foreach ($values as $value) {
        $total += $value;
    }

    return $total;
}
