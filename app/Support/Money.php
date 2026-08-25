<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Decimal money arithmetic backed by bcmath.
 *
 * Every amount in this application is LKR stored as DECIMAL(15,2). Floats are
 * never used for arithmetic: values move around as numeric strings and all
 * operations here round half-up to two decimal places.
 */
final class Money
{
    public const SCALE = 2;

    /** Extra digits kept during intermediate steps before rounding. */
    private const GUARD = 6;

    /**
     * Normalise any numeric-ish input to a canonical "0.00" string.
     */
    public static function of(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        if (is_string($value)) {
            $value = trim(str_replace([',', ' '], '', $value));
        }

        if (is_bool($value) || ! is_numeric($value)) {
            throw new InvalidArgumentException('Value is not a valid monetary amount.');
        }

        // Floats may arrive from JSON payloads; render with enough precision that
        // the guard digits survive, then round down to the storage scale.
        if (is_float($value)) {
            $value = number_format($value, self::GUARD, '.', '');
        }

        return self::round((string) $value);
    }

    public static function add(mixed ...$values): string
    {
        $total = '0.00';

        foreach ($values as $value) {
            $total = bcadd($total, self::of($value), self::SCALE);
        }

        return self::normaliseZero($total);
    }

    public static function sub(mixed $a, mixed $b): string
    {
        return self::normaliseZero(bcsub(self::of($a), self::of($b), self::SCALE));
    }

    /**
     * Multiply a money amount by a (possibly fractional) multiplier.
     */
    public static function mul(mixed $amount, mixed $multiplier): string
    {
        $raw = bcmul(self::raw($amount), self::raw($multiplier), self::SCALE + self::GUARD);

        return self::round($raw);
    }

    public static function div(mixed $amount, mixed $divisor): string
    {
        $divisorRaw = self::raw($divisor);

        if (bccomp($divisorRaw, '0', self::SCALE + self::GUARD) === 0) {
            return '0.00';
        }

        $raw = bcdiv(self::raw($amount), $divisorRaw, self::SCALE + self::GUARD);

        return self::round($raw);
    }

    /**
     * @return int -1 when $a < $b, 0 when equal, 1 when $a > $b
     */
    public static function cmp(mixed $a, mixed $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function isZero(mixed $value): bool
    {
        return self::cmp($value, '0') === 0;
    }

    public static function isNegative(mixed $value): bool
    {
        return self::cmp($value, '0') < 0;
    }

    public static function isPositive(mixed $value): bool
    {
        return self::cmp($value, '0') > 0;
    }

    public static function gt(mixed $a, mixed $b): bool
    {
        return self::cmp($a, $b) > 0;
    }

    public static function gte(mixed $a, mixed $b): bool
    {
        return self::cmp($a, $b) >= 0;
    }

    public static function lt(mixed $a, mixed $b): bool
    {
        return self::cmp($a, $b) < 0;
    }

    public static function lte(mixed $a, mixed $b): bool
    {
        return self::cmp($a, $b) <= 0;
    }

    public static function abs(mixed $value): string
    {
        $value = self::of($value);

        return self::isNegative($value) ? self::sub('0', $value) : $value;
    }

    public static function max(mixed $a, mixed $b): string
    {
        return self::cmp($a, $b) >= 0 ? self::of($a) : self::of($b);
    }

    public static function min(mixed $a, mixed $b): string
    {
        return self::cmp($a, $b) <= 0 ? self::of($a) : self::of($b);
    }

    /**
     * Clamp negatives to zero — used wherever "remaining" must not go below 0.
     */
    public static function floorAtZero(mixed $value): string
    {
        return self::max($value, '0');
    }

    /**
     * @param  iterable<mixed>  $values
     */
    public static function sum(iterable $values): string
    {
        $total = '0.00';

        foreach ($values as $value) {
            $total = bcadd($total, self::of($value), self::SCALE);
        }

        return self::normaliseZero($total);
    }

    /**
     * A percentage of an amount, e.g. percentOf(50000, 30) === "15000.00".
     */
    public static function percentOf(mixed $amount, mixed $percentage): string
    {
        return self::div(self::mul($amount, $percentage), '100');
    }

    /**
     * What percentage $part is of $whole, as a float for display/charting only.
     * Returns 0.0 when the whole is zero.
     */
    public static function percentage(mixed $part, mixed $whole): float
    {
        if (self::isZero($whole)) {
            return 0.0;
        }

        // Divide at full guard precision before scaling up, otherwise rounding to
        // two decimals first would distort the ratio (65000/300000 -> 22 not 21.67).
        $ratio = bcdiv(self::raw($part), self::raw($whole), self::SCALE + self::GUARD);

        return round((float) bcmul($ratio, '100', self::SCALE + self::GUARD), 2);
    }

    /**
     * Split an amount into $parts as evenly as possible, giving any remaining
     * cents to the earliest parts so the pieces always sum back to the total.
     *
     * @return list<string>
     */
    public static function split(mixed $amount, int $parts): array
    {
        if ($parts < 1) {
            return [];
        }

        $amount = self::of($amount);
        $base = self::truncate(bcdiv($amount, (string) $parts, self::SCALE + self::GUARD));

        $slices = array_fill(0, $parts, $base);
        $remainder = self::sub($amount, self::mul($base, (string) $parts));

        // Distribute leftover cents one at a time.
        $cent = '0.01';
        $index = 0;
        while (self::isPositive($remainder) && $index < $parts) {
            $slices[$index] = self::add($slices[$index], $cent);
            $remainder = self::sub($remainder, $cent);
            $index++;
        }

        return $slices;
    }

    /**
     * Round a raw numeric string half-up to the storage scale.
     */
    private static function round(string $raw): string
    {
        $negative = str_starts_with($raw, '-');
        $abs = ltrim($raw, '-');

        // Half-up: add 0.005 then truncate.
        $bumped = bcadd($abs, '0.'.str_repeat('0', self::SCALE).'5', self::SCALE + 1);
        $result = bcadd($bumped, '0', self::SCALE);

        return self::normaliseZero($negative ? '-'.$result : $result);
    }

    private static function truncate(string $raw): string
    {
        return self::normaliseZero(bcadd($raw, '0', self::SCALE));
    }

    /**
     * Keep "-0.00" out of the system so comparisons and display stay clean.
     */
    private static function normaliseZero(string $value): string
    {
        return $value === '-0.00' ? '0.00' : $value;
    }

    /**
     * Raw numeric string without forcing it down to 2 decimals — used for the
     * left-hand side of multiply/divide so precision is not lost early.
     */
    private static function raw(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_string($value)) {
            $value = trim(str_replace([',', ' '], '', $value));
        }

        if (is_bool($value) || ! is_numeric($value)) {
            throw new InvalidArgumentException('Value is not a valid numeric amount.');
        }

        if (is_float($value)) {
            return number_format($value, self::GUARD, '.', '');
        }

        return (string) $value;
    }
}
