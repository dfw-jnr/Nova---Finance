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
    if (!empty($config['debug'])) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'DB_CONNECTION',
                'message' => 'Database connection failed. Check app/config/env.php and run database/schema.sql.',
            ],
        ]);
        exit;
    }
    http_response_code(500);
    echo 'Service unavailable';
    exit;
}

return $config;
