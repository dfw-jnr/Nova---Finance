<?php
declare(strict_types=1);

namespace Nova\Helpers;

final class Request
{
    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function bearerOrBodyCsrf(array $body): ?string
    {
        return $body['_csrf']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? ($_POST['_csrf'] ?? null);
    }
}
