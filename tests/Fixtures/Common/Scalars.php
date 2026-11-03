<?php

function scale(float $value): float
{
    $value += 0.5;

    return $value * 2.0;
}

$flags = [null, true, false, ['inner' => [1, 2]]];
$label = 'a'.'b';
$binary = "\xFF";
$embedded = "a\0b";
$negativeZero = -0.0;
$special = [NAN, INF];
