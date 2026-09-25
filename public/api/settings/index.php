<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Csrf;
use Nova\Helpers\Request;
use Nova\Helpers\Response;
use Nova\Repositories\SettingsRepository;
use Nova\Services\AuthService;

$auth = new AuthService();
$user = $auth->requireUser();
$userId = (int) $user['id'];
$repo = new SettingsRepository();
$method = Request::method();
$body = Request::json();

if ($method === 'GET') {
    Response::ok($repo->all($userId));
}

if ($method === 'POST' || $method === 'PUT') {
    Csrf::requireValid(Request::bearerOrBodyCsrf($body));
    $theme = (string) ($body['theme'] ?? '');
    if (!in_array($theme, ['light', 'dark', 'system'], true)) {
        Response::error('VALIDATION_ERROR', 'Invalid theme.');
    }
    $repo->set($userId, 'theme', $theme);
    Response::ok(['theme' => $theme]);
}

Response::error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
