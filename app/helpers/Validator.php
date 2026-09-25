<?php
declare(strict_types=1);

namespace Nova\Helpers;

final class Validator
{
    public static function email(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public static function money(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw)) {
            $raw = str_replace([',', ' '], ['.', ''], trim($raw));
        }
        if (!is_numeric($raw)) {
            return null;
        }
        $n = (float) $raw;
        if (!is_finite($n) || $n <= 0) {
            return null;
        }
        // Cap absurd values
        if ($n > 999999999.99) {
            return null;
        }
        return number_format($n, 2, '.', '');
    }

    public static function currency(string $code, array $allowed): bool
    {
        return in_array(strtoupper($code), $allowed, true);
    }

    public static function date(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    public static function uuid(string $id): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id
        );
    }

    public static function str(mixed $v, int $min, int $max): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) {
            return null;
        }
        return $v;
    }
}
