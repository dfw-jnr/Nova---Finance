<?php
declare(strict_types=1);

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Csrf;
use Nova\Helpers\Request;
use Nova\Helpers\Response;
use Nova\Repositories\TransactionRepository;
use Nova\Services\AuthService;
use Nova\Services\TransactionService;

$auth = new AuthService();
$user = $auth->requireUser();
$userId = (int) $user['id'];
$method = Request::method();
$body = Request::json();
$service = new TransactionService();
$repo = new TransactionRepository();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

try {
    if ($method === 'GET' && $id > 0) {
        $row = $repo->findOwned($id, $userId);
        if (!$row) {
            Response::error('NOT_FOUND', 'Transaction not found.', 404);
        }
        Response::ok($row);
    }

    if ($method === 'GET') {
        $filters = [
            'type' => $_GET['type'] ?? null,
            'category_id' => $_GET['category_id'] ?? null,
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
            'q' => $_GET['q'] ?? null,
            'sort' => $_GET['sort'] ?? 'date_desc',
        ];
        Response::ok($repo->list($userId, $filters));
    }

    if ($method === 'POST') {
        Csrf::requireValid(Request::bearerOrBodyCsrf($body));
        $row = $service->create($userId, $body, $user['currency']);
        Response::ok($row, 201);
    }

    if (($method === 'PUT' || $method === 'PATCH') && $id > 0) {
        Csrf::requireValid(Request::bearerOrBodyCsrf($body));
        $row = $service->update($userId, $id, $body, $user['currency']);
        Response::ok($row);
    }

    if ($method === 'DELETE' && $id > 0) {
        Csrf::requireValid(Request::bearerOrBodyCsrf($body));
        $service->delete($userId, $id);
        Response::ok(['deleted' => true]);
    }
} catch (Throwable $e) {
    if (!empty($config['debug'])) {
        Response::error('SERVER_ERROR', 'Unable to process transaction.', 500, [
            'detail' => $e->getMessage(),
        ]);
    }
    Response::error('SERVER_ERROR', 'Unable to process transaction.', 500);
}

Response::error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
