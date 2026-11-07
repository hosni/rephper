<?php

namespace {
    function alpha(int $value): int
    {
        return $value + 1;
    }

    $global = alpha(1);
}

namespace App {
    function beta(int $value): int
    {
        return $value + 2;
    }

    $named = beta(1);
}
