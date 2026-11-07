<?php

namespace App;

function take(\stdClass $value): \RuntimeException
{
    return $value;
}

function local(Widget $value): Widget
{
    return $value;
}
