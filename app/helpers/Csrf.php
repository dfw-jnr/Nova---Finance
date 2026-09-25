<?php
declare(strict_types=1);

namespace Nova\Helpers;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function validate(?string $token): bool
    {
        if (!$token || empty($_SESSION['_csrf'])) {
            return false;
        }
        return hash_equals($_SESSION['_csrf'], $token);
    }

    public static function requireValid(?string $token): void
    {
        if (!self::validate($token)) {
            Response::error('CSRF_INVALID', 'Invalid security token.', 419);
        }
    }
}
