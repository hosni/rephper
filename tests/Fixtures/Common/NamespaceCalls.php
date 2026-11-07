<?php

namespace App;

use function App\add as plus;

function add(int $a, int $b): int
{
    return $a + $b;
}

function calculate(int $a, int $b): int
{
    return add($a, $b);
}

function absolute(int $a, int $b): int
{
    return \App\add($a, $b);
}

function aliased(int $a, int $b): int
{
    return plus($a, $b);
}
