<?php

function sequence(int $limit): Generator
{
    for ($i = 0; $i < $limit; $i++) {
        yield $i;
    }
}
