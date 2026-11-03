<?php

function values(): void
{
    $a = 10;
    $b = 20;
    $c = null;
    $d = true;
}

function arithmetic(int $a, int $b): int
{
    $result = $a + $b;
    $result = $a - $b;
    $result = $a * $b;
    $result = $a / $b;
    $result = $a % $b;
    $result = -$a;

    return $result;
}

function precedence(int $a, int $b, int $c): int
{
    $result = $a + $b * $c;
    $result = ($a + $b) * $c;

    return $result;
}

function strings(string $name): string
{
    $name = 'John';
    $message = 'Hello ' . $name;

    return $message;
}

function comparisons(int $a, int $b): bool
{
    $result = $a === $b;
    $result = $a !== $b;
    $result = $a == $b;
    $result = $a != $b;
    $result = $a < $b;
    $result = $a >= $b;

    return $result;
}

function booleans(bool $a, bool $b): bool
{
    $result = !$a;
    $result = $a && $b;
    $result = $a || $b;

    return $result;
}

function assignments(int $price, int $quantity, int $tax): int
{
    $total = $price * $quantity + $tax;
    $count = 0;
    $count += 1;

    return $total;
}

function returnsValue(int $price, int $quantity): int
{
    return $price * $quantity;
}

function returnsNothing(): void
{
    return;
}

function callThenAdd(int $n, string $text): int
{
    return strlen($text) + $n;
}

function signs(int $a): int
{
    $minus = -$a;
    $plus = +$a;
    $bits = ~$a;

    return $bits;
}

function opaqueForms(string $text, array $items, int $index): void
{
    echo $text;
    echo $items[$index];
    echo strlen($text);
    echo $text . $text;
    sort($items);
}

function calledValue(string $text, int $n): int
{
    return strlen(strtolower($text)) + $n;
}

function calledSideEffect(string $text, int $n): void
{
    echo strtolower($text . $n);
}
