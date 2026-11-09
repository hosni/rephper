<?php

class FinalHook
{
    public int $value = 1 {
        final get => $this->value + 1;
        set => $value;
    }
}

class FinalHookChild extends FinalHook
{
    public int $value = 1 {
        set => $value + 2;
    }
}

class FinalProperty
{
    final public int $value = 1 {
        get => $this->value;
    }
}
