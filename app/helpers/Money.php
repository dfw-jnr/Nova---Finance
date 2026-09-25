<?php
declare(strict_types=1);

namespace Nova\Helpers;

final class Money
{
    public static function format(string|float $amount, string $currency = 'EUR'): string
    {
        $n = (float) $amount;
        $symbols = [
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'GHS' => 'GH₵',
        ];
        $sym = $symbols[$currency] ?? ($currency . ' ');
        $sign = $n < 0 ? '−' : '';
        return $sign . $sym . number_format(abs($n), 2, '.', ',');
    }

    public static function signed(string|float $amount, string $type, string $currency = 'EUR'): string
    {
        $n = abs((float) $amount);
        $prefix = $type === 'income' ? '+' : '−';
        $base = self::format($n, $currency);
        // strip leading euro if already has symbol
        return $prefix . ltrim($base, '+−');
    }
}
