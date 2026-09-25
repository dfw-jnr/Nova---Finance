<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Csrf;
use Nova\Helpers\Request;
use Nova\Helpers\Response;
use Nova\Helpers\Validator;
use Nova\Repositories\BudgetRepository;
use Nova\Repositories\CategoryRepository;
use Nova\Services\AuthService;

$auth = new AuthService();
$user = $auth->requireUser();
$userId = (int) $user['id'];
$repo = new BudgetRepository();
$method = Request::method();
$body = Request::json();

if ($method === 'GET') {
    $year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
    $month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
    Response::ok($repo->listForUser($userId, $year, $month));
}

if ($method === 'POST') {
    Csrf::requireValid(Request::bearerOrBodyCsrf($body));
    $name = Validator::str($body['name'] ?? '', 1, 120);
    $limit = Validator::money($body['limit_amount'] ?? $body['limit'] ?? null);
    $categoryId = (int) ($body['category_id'] ?? 0);
    $year = (int) ($body['year'] ?? date('Y'));
    $month = (int) ($body['month'] ?? date('n'));

    $cats = new CategoryRepository();
    if (!$name || !$limit || !$cats->findAccessible($categoryId, $userId)) {
        Response::error('VALIDATION_ERROR', 'Invalid budget data.');
    }
    if ($month < 1 || $month > 12) {
        Response::error('VALIDATION_ERROR', 'Invalid month.');
    }

    try {
        $id = $repo->create($userId, $name, $year, $month, $user['currency'], $categoryId, $limit);
        Response::ok(['id' => $id], 201);
    } catch (Throwable $e) {
        Response::error('SERVER_ERROR', 'Could not create budget.', 500);
    }
}

Response::error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
