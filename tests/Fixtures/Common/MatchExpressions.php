<?php

function getValue(): int
{
    return 1;
}

function calculate(int $value): int
{
    return $value + 1;
}

function label(string $text): string
{
    return $text;
}

function simpleInt(int $value): string
{
    return match ($value) {
        1 => 'one',
        2 => 'two',
        3 => 'three',
        default => 'other',
    };
}

function simpleString(string $value): string
{
    return match ($value) {
        'a' => 'one',
        'b', 'c' => 'few',
        default => 'other',
    };
}

function sharedArm(int $value): string
{
    return match ($value) {
        1, 2 => 'few',
        3 => 'three',
        default => 'other',
    };
}

function withDefault(int $value): int
{
    return match ($value) {
        1 => 10,
        2 => 20,
        default => 30,
    };
}

function withoutDefault(int $value): string
{
    return match ($value) {
        1 => 'one',
        2 => 'two',
    };
}

function subjectVar(int $value): string
{
    return match ($value) {
        1 => 'one',
        2 => 'two',
        default => 'other',
    };
}

function subjectCall(int $value): string
{
    return match (getValue()) {
        1 => 'one',
        2 => 'two',
        default => 'other',
    };
}

function subjectExpr(int $value): string
{
    return match ($value + 1) {
        1 => 'one',
        2 => 'two',
        default => 'other',
    };
}

function subjectCompare(int $value): string
{
    return match ($value > 0) {
        true => 'yes',
        false => 'no',
    };
}

function assigned(int $value): string
{
    $result = match ($value) {
        1 => 'one',
        2 => calculate($value),
        default => $value,
    };

    return label($result);
}

function returned(int $value): int
{
    return match ($value) {
        1 => 10,
        2 => 20,
        default => 30,
    };
}

function inArithmetic(int $value): int
{
    return 1 + match ($value) {
        1 => 10,
        2 => 20,
        default => 30,
    };
}

function inConcat(int $value): string
{
    return 'value: ' . match ($value) {
        1 => 'one',
        2 => 'two',
        default => 'other',
    };
}

function asArgument(int $value): int
{
    return calculate(match ($value) {
        1 => 10,
        2 => 20,
        default => 30,
    });
}

function callInArm(int $value): int
{
    return match ($value) {
        1 => calculate($value),
        2 => 2,
        default => 0,
    };
}

function mixedArmTypes(int $value): mixed
{
    return match ($value) {
        1 => 10,
        2 => 'two',
        3 => $value > 0,
        default => null,
    };
}

function nested(int $value, int $other): string
{
    return match ($value) {
        1 => match ($other) {
            1 => 'one',
            2 => 'two',
            default => 'inner',
        },
        2 => 'outer',
        default => 'other',
    };
}

function twoMatches(int $value): int
{
    $first = match ($value) {
        1 => 10,
        2 => 20,
        default => 30,
    };

    return match ($first) {
        10 => 1,
        20 => 2,
        default => 0,
    };
}

function matchInIf(int $flag, int $value): string
{
    if ($flag > 0) {
        return match ($value) {
            1 => 'one',
            2 => 'two',
            default => 'other',
        };
    }

    return 'no';
}

function matchInWhile(int $n): int
{
    while ($n < 3) {
        $n = match ($n) {
            0 => 1,
            1 => 2,
            default => 3,
        };
    }

    return $n;
}

function dynamicCond(int $value, int $limit): string
{
    return match ($value) {
        $limit => 'limit',
        1 => 'one',
        default => 'other',
    };
}

function oneCond(int $value): string
{
    return match ($value) {
        1 => 'one',
        default => 'other',
    };
}

function mixedTypes(int|string $value): string
{
    return match ($value) {
        1 => 'int',
        'a' => 'string',
        default => 'other',
    };
}

function numericString(string $value): string
{
    return match ($value) {
        '1' => 'one',
        '2' => 'two',
        default => 'other',
    };
}

function duplicateCond(int $value): string
{
    return match ($value) {
        1 => 'first',
        1 => 'second',
        default => 'other',
    };
}

function literalSubject(): string
{
    return match (1) {
        1 => 'one',
        2 => 'two',
        default => 'other',
    };
}

function boolArms(int $value): string
{
    return match ($value) {
        1 => 'one',
        2 => 'two',
        default => $value === 0 ? 'zero' : 'other',
    };
}

function defaultFirst(int $value): string
{
    return match ($value) {
        default => 'other',
        1 => 'one',
        2 => 'two',
    };
}
