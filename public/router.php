<?php
declare(strict_types=1);

/**
 * Router for PHP built-in server:
 * php -S localhost:8080 router.php
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$file = __DIR__ . $uri;

if ($uri !== '/' && is_file($file)) {
    return false;
}

if (preg_match('#^/api/transactions/(\d+)/?$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/api/transactions/index.php';
    return true;
}

if (preg_match('#^/api/goals/(\d+)/?$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/api/goals/index.php';
    return true;
}

if (str_starts_with($uri, '/api/')) {
    $path = __DIR__ . rtrim($uri, '/') . '/index.php';
    if (is_file($path)) {
        require $path;
        return true;
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Endpoint not found.']]);
    return true;
}

if ($uri === '/login' || $uri === '/login.php') {
    require __DIR__ . '/login.php';
    return true;
}
if ($uri === '/register' || $uri === '/register.php') {
    require __DIR__ . '/register.php';
    return true;
}

require __DIR__ . '/index.php';
return true;
