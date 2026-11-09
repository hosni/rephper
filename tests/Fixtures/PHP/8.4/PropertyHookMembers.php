<?php

class MixedMembers
{
    public int $plain = 1;

    public int $hooked = 2 {
        get => $this->hooked + $this->plain;
    }

    public string $label = 'a';
}
