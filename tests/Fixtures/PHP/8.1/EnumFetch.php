<?php

enum Source: int
{
    case X = 4;
}

enum Copy: int
{
    case Y = Source::X->value;
}
