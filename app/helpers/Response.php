<?php
declare(strict_types=1);

namespace Nova\Helpers;

final class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function ok(mixed $data = null, int $status = 200): void
    {
        self::json(['success' => true, 'data' => $data], $status);
    }

    public static function error(string $code, string $message, int $status = 400, array $extra = []): void
    {
        $error = array_merge(['code' => $code, 'message' => $message], $extra);
        self::json(['success' => false, 'error' => $error], $status);
    }
}
