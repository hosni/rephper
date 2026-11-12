<?php

class BackedParent
{
    public int $value = 1 {
        get => $this->value + 1;
        set => $value * 2;
    }
}

class BackedChild extends BackedParent
{
    public int $value = 1 {
        get => parent::$value::get() + 3;
        set {
            parent::$value::set($value + 4);
        }
    }

    public function read(): int
    {
        return $this->value;
    }

    public function write(int $next): void
    {
        $this->value = $next;
    }
}

class VirtualParent
{
    private int $store = 2;

    public int $value {
        get => $this->store;
        set {
            $this->store = $value;
        }
    }
}

class VirtualChild extends VirtualParent
{
    public int $value {
        get => parent::$value::get() + 1;
        set {
            parent::$value::set($value + 1);
        }
    }
}

class UntypedParent
{
    public $value = 'a' {
        get => $this->value;
        set {
            $this->value = $value;
        }
    }
}

class UntypedChild extends UntypedParent
{
    public $value = 'a' {
        get => parent::$value::get();
        set {
            parent::$value::set($value . 'b');
        }
    }
}

class LevelOne
{
    public int $value = 1 {
        get => $this->value;
    }
}

class LevelTwo extends LevelOne
{
    public int $value = 1 {
        get => parent::$value::get() + 1;
    }
}

class LevelThree extends LevelTwo
{
    public int $value = 1 {
        get => parent::$value::get() + 2;
    }
}

class MultiParent
{
    public int $value = 4 {
        get => $this->value;
    }
}

class MultiChild extends MultiParent
{
    public int $value = 4 {
        get => parent::$value::get() + parent::$value::get();
    }
}

class AssignParent
{
    public int $value = 5 {
        get => $this->value;
    }
}

class AssignChild extends AssignParent
{
    public int $value = 5 {
        get {
            $n = parent::$value::get();
            return $n + 1;
        }
    }
}

class CallParent
{
    public int $value = 1 {
        set {
            $this->value = $value;
        }
    }
}

class CallChild extends CallParent
{
    public int $value = 1 {
        set {
            parent::$value::set(abs($value));
        }
    }
}

class NamedParent
{
    public int $value = 1 {
        set {
            $this->value = $value;
        }
    }
}

class NamedChild extends NamedParent
{
    public int $value = 1 {
        set {
            parent::$value::set(value: $value);
        }
    }
}
