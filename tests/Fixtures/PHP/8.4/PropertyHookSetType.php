<?php

declare(strict_types=1);

class WiderSet
{
    public int $value = 1 {
        set(int|string $incoming) {
            $this->value = 4;
        }
    }
}
