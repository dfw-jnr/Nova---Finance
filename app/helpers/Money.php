<?php
declare(strict_types=1);

namespace Nova\Helpers;

final class Money
{
    public static function format(string|float $amount, string $currency = 'EUR'): string
    {
        $normalized = Decimal::normalize((string) $amount);
        $neg = str_starts_with($normalized, '-');
        $abs = Decimal::abs($normalized);
        $symbols = [
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'GHS' => 'GH₵',
        ];
        $sym = $symbols[$currency] ?? ($currency . ' ');
        $parts = explode('.', $abs);
        $whole = number_format((int) $parts[0], 0, '.', ',');
        $frac = $parts[1] ?? '00';
        return ($neg ? '−' : '') . $sym . $whole . '.' . $frac;
    }

    public static function signed(string|float $amount, string $type, string $currency = 'EUR'): string
    {
        $abs = Decimal::abs(Decimal::normalize((string) $amount));
        $prefix = $type === 'income' ? '+' : ($type === 'transfer' ? '' : '−');
        $base = self::format($abs, $currency);
        if ($type === 'transfer') {
            return $base;
        }
        return $prefix . ltrim($base, '+−');
    }
}
