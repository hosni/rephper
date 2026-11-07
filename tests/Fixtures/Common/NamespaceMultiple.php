<?php

namespace App {
    function alpha(int $value): int
    {
        return $value + 1;
    }
}

namespace Other {
    function beta(int $value): int
    {
        return $value + 2;
    }
}
