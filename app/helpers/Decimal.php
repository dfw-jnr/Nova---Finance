<?php
declare(strict_types=1);

namespace Nova\Helpers;

final class Decimal
{
    /** Negate a decimal string without bcmath. */
    public static function negate(string $amount): string
    {
        if (str_starts_with($amount, '-')) {
            return ltrim($amount, '-');
        }
        return '-' . $amount;
    }
}
