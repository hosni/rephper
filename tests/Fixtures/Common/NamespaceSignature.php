<?php

namespace App;

#[\Deprecated]
function &ref(#[\SensitiveParameter] array &$items, int $max = PHP_INT_MAX, int $full = \PHP_INT_MAX, int $flag = \ArrayObject::STD_PROP_LIST): array
{
    return $items;
}
