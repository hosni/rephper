<?php

enum Mix: int
{
    case A = 1;

    public const int N = 2;

    case B = 3;

    final protected const string LABEL = 'mix';

    case C = 4;
}
