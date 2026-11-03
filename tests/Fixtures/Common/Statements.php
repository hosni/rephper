<?php

function assignLiteral(): void
{
    $a = 10;
}

function assignSequence(): void
{
    $a = 10;
    $b = 20;
    $c = null;
}

function assignArithmetic(int $a, int $b): void
{
    $result = $a + $b;
}

function assignCall(string $text): void
{
    $result = strtolower($text);
}

function callSideEffect(string $text): void
{
    strtolower($text);
}

function returnLiteral(): int
{
    return 7;
}

function returnVariable(int $value): int
{
    return $value;
}

function returnExpression(int $price, int $quantity): int
{
    return $price * $quantity;
}

function returnNothing(): void
{
    return;
}

function throwLiteral(): void
{
    throw new Exception('bad');
}

function echoValue(string $text): void
{
    echo $text;
}
