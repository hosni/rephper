<?php

function outer(): void
{
    function inner(): int
    {
        return 1;
    }
}
