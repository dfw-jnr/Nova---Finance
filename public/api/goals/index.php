<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Csrf;
use Nova\Helpers\Request;
use Nova\Helpers\Response;
use Nova\Helpers\Validator;
use Nova\Repositories\GoalRepository;
use Nova\Services\AuthService;

$auth = new AuthService();
$user = $auth->requireUser();
$userId = (int) $user['id'];
$repo = new GoalRepository();
$method = Request::method();
$body = Request::json();
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($method === 'GET') {
    Response::ok($repo->listForUser($userId));
}

if ($method === 'POST' && ($body['action'] ?? '') === 'contribute' && $id > 0) {
    Csrf::requireValid(Request::bearerOrBodyCsrf($body));
    $amount = Validator::money($body['amount'] ?? null);
    if (!$amount || !$repo->findOwned($id, $userId)) {
        Response::error('VALIDATION_ERROR', 'Invalid contribution.');
    }
    $repo->addAmount($id, $userId, $amount);
    Response::ok($repo->findOwned($id, $userId));
}

if ($method === 'POST') {
    Csrf::requireValid(Request::bearerOrBodyCsrf($body));
    $name = Validator::str($body['name'] ?? '', 1, 120);
    $target = Validator::money($body['target_amount'] ?? $body['target'] ?? null);
    $current = '0.00';
    if (isset($body['current_amount'])) {
        $current = Validator::money($body['current_amount']) ?? '0.00';
    }
    $targetDate = $body['target_date'] ?? null;
    if ($targetDate && !Validator::date((string) $targetDate)) {
        Response::error('VALIDATION_ERROR', 'Invalid target date.');
    }
    if (!$name || !$target) {
        Response::error('VALIDATION_ERROR', 'Invalid goal data.');
    }
    $gid = $repo->create($userId, [
        'name' => $name,
        'target_amount' => $target,
        'current_amount' => $current,
        'currency' => $user['currency'],
        'target_date' => $targetDate,
        'accent' => $body['accent'] ?? '#6EA8FE',
    ]);
    Response::ok($repo->findOwned($gid, $userId), 201);
}

if ($method === 'DELETE' && $id > 0) {
    Csrf::requireValid(Request::bearerOrBodyCsrf($body));
    if (!$repo->findOwned($id, $userId)) {
        Response::error('NOT_FOUND', 'Goal not found.', 404);
    }
    $repo->delete($id, $userId);
    Response::ok(['deleted' => true]);
}

Response::error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
