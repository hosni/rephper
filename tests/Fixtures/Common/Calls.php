<?php

function calculate(int $price, int $quantity = 0): int
{
    return $price * $quantity;
}

function greet(): void
{
}

function byReference(int &$n): void
{
    $n = 1;
}

function take(mixed $value, int $n): mixed
{
    return $value;
}

function assigned(int $price, int $quantity): void
{
    $result = calculate($price, $quantity);
}

function returned(int $price, int $quantity): int
{
    return calculate($price, $quantity);
}

function literals(): int
{
    return calculate(10, 20);
}

function expressionArg(int $a, int $b, int $c): int
{
    return calculate($a + $b, $c);
}

function nested(int $a, int $b): int
{
    return calculate(calculate($a), $b);
}

function added(int $price, int $quantity, int $tax): int
{
    return calculate($price, $quantity) + $tax;
}

function namedArgs(int $quantity, int $price): void
{
    unknown_func(quantity: $quantity, price: $price);
}

function rewrittenName(int $price): int
{
    return calculate(10, quantity: $price);
}

function mixedArgs(string $label): void
{
    unknown_func(1, label: $label);
}

function unpacked(array $args): void
{
    calculate(...$args);
}

function unpackedAfter(int $price, array $args): void
{
    unknown_func($price, ...$args);
}

function referenced(array $items): void
{
    sort($items);
}

function callsByReference(int &$n): void
{
    byReference($n);
}

function noArguments(): void
{
    greet();
}

function forward(int $n): int
{
    return notYet($n);
}

function notYet(int $n): int
{
    return $n;
}

function sideEffect(string $text): void
{
    strtolower($text);
}

function dynamic(string $name, int $price): void
{
    $name($price);
}

function methodCall(object $obj): void
{
    $obj->name($obj);
}

function constructed(): void
{
    $box = new Exception('bad');
}

function firstClass(): void
{
    $first = strlen(...);
}

function withNew(int $n): mixed
{
    return take(new Exception('bad'), $n);
}

function withDynamic(int $n): mixed
{
    $name = 'take';

    return take($name($n), $n);
}
