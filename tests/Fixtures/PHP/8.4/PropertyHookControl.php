<?php

class Control
{
    public int $value = 0 {
        get {
            $extra = $this->plain;
            if ($extra > 0) {
                return $this->value + $extra;
            }

            return match ($this->value) {
                0 => 1,
                1 => 2,
                2 => 3,
                default => $this->value,
            };
        }
        set {
            if ($value < 0) {
                $value = 0;
            }
            $this->value = $value;
        }
    }

    public int $plain = 2;
}
