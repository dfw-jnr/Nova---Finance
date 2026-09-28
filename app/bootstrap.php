<?php
declare(strict_types=1);

/**
 * Application bootstrap — loaded by public entrypoints.
 */

$config = require __DIR__ . '/config/env.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Nova\\')) {
        return;
    }
    $relative = str_replace('Nova\\', '', $class);
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
    $map = [
        'Helpers' => 'helpers',
        'Services' => 'services',
        'Repositories' => 'repositories',
        'Controllers' => 'controllers',
        'Middleware' => 'middleware',
    ];
    $parts = explode(DIRECTORY_SEPARATOR, $relative);
    $ns = $parts[0] ?? '';
    if (isset($map[$ns])) {
        $parts[0] = $map[$ns];
    }
    $path = __DIR__ . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Nova\Helpers\Database;

$secure = !empty($config['session']['secure']);
session_name($config['session']['name']);
session_set_cookie_params([
    'lifetime' => (int) $config['session']['lifetime'],
    'path' => '/',
    'secure' => $secure,
    'httponly' => (bool) $config['session']['httponly'],
    'samesite' => $config['session']['samesite'],
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    Database::connect($config['db']);
} catch (Throwable $e) {
    error_log('NOVA DB connect failed: ' . $e->getMessage());
    http_response_code(500);
    $isApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
    $detail = !empty($config['debug']) ? $e->getMessage() : null;
    $message = 'Database unavailable. If you use managed MySQL, check DATABASE_URL / that the host still exists.';
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'DB_CONNECTION',
                'message' => $message,
                'detail' => $detail,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>NOVA — Unavailable</title>';
    echo '<style>body{font-family:system-ui,sans-serif;background:#0b0d10;color:#e8eaed;display:grid;place-items:center;min-height:100vh;margin:0;padding:1.5rem;text-align:center}';
    echo 'h1{font-size:1.25rem;margin:0 0 .75rem}p{color:#9aa3b2;max-width:28rem;line-height:1.45}</style></head><body>';
    echo '<div><h1>NOVA can’t reach the database</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    if ($detail) {
        echo '<p style="font-size:.8rem;color:#6b7380">' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    echo '</div></body></html>';
    exit;
}

return $config;
