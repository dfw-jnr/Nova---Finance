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
            $raw = trim($raw);
            $raw = preg_replace('/[€$£¥]|GH₵|USD|EUR|GBP|GHS/iu', '', $raw) ?? $raw;
            $raw = str_replace([' ', "\xc2\xa0"], '', $raw);
            // European 1.234,56 → 1234.56
            if (preg_match('/^\d{1,3}(\.\d{3})+,\d{1,2}$/', $raw) || preg_match('/^\d+,\d{1,2}$/', $raw)) {
                $raw = str_replace('.', '', $raw);
                $raw = str_replace(',', '.', $raw);
            } else {
                // US thousands: 1,234.56
                if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $raw)) {
                    $raw = str_replace(',', '', $raw);
                } else {
                    $raw = str_replace(',', '.', $raw);
                }
            }
        }
        if (!is_numeric($raw) && !(is_string($raw) && preg_match('/^-?\d+(\.\d+)?$/', $raw))) {
            return null;
        }
        $normalized = Decimal::normalize((string) $raw);
        if (!Decimal::isPositive($normalized)) {
            return null;
        }
        if (Decimal::cmp($normalized, '999999999.99') > 0) {
            return null;
        }
        return $normalized;
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
