<?php

enum Scale: int
{
    case X = 1;

    public const int N = 2;
}

enum Bits: int
{
    case Zero = 0;

    case Negative = -4;

    case Shifted = 1 << 3;

    case Computed = 1 + Scale::N;
}
