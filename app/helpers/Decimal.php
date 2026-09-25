<?php
declare(strict_types=1);

namespace Nova\Helpers;

/**
 * Fixed 2-decimal money arithmetic using integer cents (no float).
 */
final class Decimal
{
    public static function normalize(string|int|float $amount): string
    {
        $cents = self::toCents($amount);
        return self::fromCents($cents);
    }

    public static function toCents(string|int|float $amount): int
    {
        if (is_int($amount)) {
            return $amount * 100;
        }
        // Never trust binary float bits for money — format then parse.
        if (is_float($amount)) {
            if (!is_finite($amount)) {
                return 0;
            }
            $amount = number_format($amount, 4, '.', '');
        }
        $s = trim((string) $amount);
        $s = preg_replace('/[€$£¥]|GH₵/u', '', $s) ?? $s;
        $s = str_replace([' ', ','], ['', '.'], $s);
        if ($s === '' || $s === '-' || $s === '+') {
            return 0;
        }
        $neg = str_starts_with($s, '-');
        if ($neg || str_starts_with($s, '+')) {
            $s = substr($s, 1);
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $s)) {
            return 0;
        }
        $parts = explode('.', $s, 2);
        $whole = (int) $parts[0];
        $frac = $parts[1] ?? '0';
        $frac = substr(str_pad($frac, 2, '0'), 0, 2);
        // Round third digit if present in original
        if (isset($parts[1]) && strlen($parts[1]) > 2) {
            $third = (int) $parts[1][2];
            $fracInt = (int) $frac;
            if ($third >= 5) {
                $fracInt++;
            }
            if ($fracInt >= 100) {
                $whole++;
                $fracInt = 0;
            }
            $frac = str_pad((string) $fracInt, 2, '0', STR_PAD_LEFT);
        }
        $cents = $whole * 100 + (int) $frac;
        return $neg ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        $neg = $cents < 0;
        $cents = abs($cents);
        $whole = intdiv($cents, 100);
        $frac = $cents % 100;
        return ($neg ? '-' : '') . $whole . '.' . str_pad((string) $frac, 2, '0', STR_PAD_LEFT);
    }

    public static function add(string $a, string $b): string
    {
        return self::fromCents(self::toCents($a) + self::toCents($b));
    }

    public static function sub(string $a, string $b): string
    {
        return self::fromCents(self::toCents($a) - self::toCents($b));
    }

    public static function negate(string $amount): string
    {
        return self::fromCents(-self::toCents($amount));
    }

    public static function abs(string $amount): string
    {
        return self::fromCents(abs(self::toCents($amount)));
    }

    /** @return int -1, 0, or 1 */
    public static function cmp(string $a, string $b): int
    {
        $d = self::toCents($a) - self::toCents($b);
        return $d <=> 0;
    }

    public static function isPositive(string $amount): bool
    {
        return self::toCents($amount) > 0;
    }

    public static function max(string $a, string $b): string
    {
        return self::cmp($a, $b) >= 0 ? self::normalize($a) : self::normalize($b);
    }

    public static function min(string $a, string $b): string
    {
        return self::cmp($a, $b) <= 0 ? self::normalize($a) : self::normalize($b);
    }

    public static function zero(): string
    {
        return '0.00';
    }
}
