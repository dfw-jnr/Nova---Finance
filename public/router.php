<?php
declare(strict_types=1);

/**
 * Router for PHP built-in server:
 * php -S localhost:8080 router.php
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$file = __DIR__ . $uri;

if ($uri !== '/' && is_file($file) && !str_ends_with($uri, '.php')) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'woff2' => 'font/woff2',
        'ico' => 'image/x-icon',
        'webmanifest' => 'application/manifest+json',
    ];
    if (isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext]);
        header('X-Content-Type-Options: nosniff');
        // Versioned assets can be cached hard; others briefly.
        if (!empty($_GET['v']) || in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'woff2', 'ico'], true)) {
            header('Cache-Control: public, max-age=31536000, immutable');
        } else {
            header('Cache-Control: public, max-age=300');
        }
        readfile($file);
        return true;
    }
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
