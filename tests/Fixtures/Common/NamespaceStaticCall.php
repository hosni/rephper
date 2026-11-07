<?php

namespace App;

use App\Services\Calculator as Calc;

function calculate(): int
{
    return Calc::value();
}
