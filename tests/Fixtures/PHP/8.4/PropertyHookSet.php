<?php

class SetOnly
{
    public int $value = 1 {
        set => $value * 2;
    }
}
