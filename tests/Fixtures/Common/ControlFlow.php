<?php

function walk(int $n, array $items): int
{
    $total = 0;

    if ($n > 0) {
        $total = $n;
    } else {
        $total = 0;
    }

    $label = $n > 10 ? 'high' : 'low';

    for ($i = 0; $i < $n; $i++) {
        if ($i === 2) {
            continue;
        }
        if ($i === 4) {
            break;
        }
        $total += $i;
    }

    $j = 0;
    while ($j < $n) {
        $j++;
    }

    foreach ($items as $item) {
        $total += $item;
    }

    return $total;
}
