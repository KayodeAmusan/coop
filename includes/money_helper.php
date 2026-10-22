<?php
/** Decimal(15,2) money is calculated in integer minor units, never binary fractions. */
function moneyMinorUnits($value): int
{
    if ((!is_string($value) && !is_int($value) && !is_float($value)) ||
        !preg_match('/\A(-?)([0-9]{1,13})(?:\.([0-9]{1,2}))?\z/', (string) $value, $parts)) {
        throw new InvalidArgumentException('Money must be a decimal amount with at most two decimal places');
    }
    $minor = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0');
    return $parts[1] === '-' ? -$minor : $minor;
}

function moneyDecimal(int $minor): string
{
    if ($minor < -999999999999999 || $minor > 999999999999999) {
        throw new OverflowException('Money exceeds DECIMAL(15,2) range');
    }
    $absolute = abs($minor);
    return ($minor < 0 ? '-' : '') . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
}

function moneyAdd(int $left, int $right): int
{
    if ($left < -999999999999999 || $left > 999999999999999 ||
        $right < -999999999999999 || $right > 999999999999999) {
        throw new OverflowException('Money exceeds DECIMAL(15,2) range');
    }
    $sum = $left + $right;
    moneyDecimal($sum);
    return $sum;
}
