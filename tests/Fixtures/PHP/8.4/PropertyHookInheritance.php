<?php

class ParentBox
{
    public int $value = 1 {
        get => $this->value + 1;
        set => $value * 2;
    }
}

class ChildGet extends ParentBox
{
    public int $value = 1 {
        get => $this->value + 3;
    }
}

class ChildSet extends ParentBox
{
    public int $value = 1 {
        set => $value + 4;
    }
}

class ChildSame extends ParentBox
{
    public int $value = 2;
}
