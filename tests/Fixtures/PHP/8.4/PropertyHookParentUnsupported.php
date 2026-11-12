<?php

class AndParent
{
    public int $value = 1 {
        set {
            $this->value = $value;
        }
    }
}

class AndChild extends AndParent
{
    public int $value = 1 {
        set => parent::$value::set($value && 1);
    }
}

class NullParent
{
    public int $value = 1 {
        set {
            $this->value = $value;
        }
    }
}

class NullChild extends NullParent
{
    public int $value = 1 {
        set => parent::$value::set($value ?? 1);
    }
}

class MatchParent
{
    public int $value = 1 {
        set {
            $this->value = $value;
        }
    }
}

class MatchChild extends MatchParent
{
    public int $value = 1 {
        set => parent::$value::set(match ($value) { 0 => 1, 1 => 2, 2 => 3, default => $value });
    }
}

class OtherParent
{
    public int $value = 1 {
        set {
            $this->value = $value;
        }
    }

    public int $other = 2;
}

class OtherChild extends OtherParent
{
    public int $value = 1 {
        set => parent::$value::set($this->other);
    }
}
