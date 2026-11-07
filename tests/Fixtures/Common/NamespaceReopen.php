<?php

namespace App {
    function alpha(): int
    {
        return 1;
    }
}

namespace Other {
    function beta(): int
    {
        return 2;
    }
}

namespace App {
    function delta(): int
    {
        return 4;
    }

    $value = delta();
}
