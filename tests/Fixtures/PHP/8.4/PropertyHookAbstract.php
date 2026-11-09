<?php

abstract class AbstractGet
{
    abstract public int $value { get; }
}

abstract class AbstractMixed
{
    abstract public int $value {
        get => 1;
        set;
    }
}
