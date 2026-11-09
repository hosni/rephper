<?php

class Promoted
{
    public function __construct(
        public int $value = 1 {
            get => $this->value + 1;
            set => $value;
        },
        public string $name = 'n',
    ) {
    }
}
