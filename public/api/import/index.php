<?php
declare(strict_types=1);

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Csrf;
use Nova\Helpers\Request;
use Nova\Helpers\Response;
use Nova\Services\AuthService;
use Nova\Services\ImportService;

$auth = new AuthService();
$user = $auth->requireUser();
$userId = (int) $user['id'];
$method = Request::method();
$body = Request::json();
$service = new ImportService();

$importId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET' && $importId > 0) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    Response::ok($service->preview($userId, $importId));
}

if ($method === 'POST' && $importId > 0 && $action === 'remap') {
    Csrf::requireValid(Request::bearerOrBodyCsrf($body));
    $map = $body['column_map'] ?? [];
    if (!is_array($map)) {
        Response::error('VALIDATION_ERROR', 'column_map must be an object.');
    }
    Response::ok($service->remap($userId, $importId, $map, (string) $user['currency']));
}

if ($method === 'POST' && $importId > 0 && $action === 'commit') {
    Csrf::requireValid(Request::bearerOrBodyCsrf($body));
    $rows = $body['rows'] ?? [];
    if (!is_array($rows)) {
        Response::error('VALIDATION_ERROR', 'rows must be an array.');
    }
    Response::ok($service->commit($userId, $importId, $rows, (string) $user['currency']));
}

if ($method === 'POST' && $importId === 0) {
    Csrf::requireValid(Request::bearerOrBodyCsrf($body) ?? ($_POST['_csrf'] ?? null));

    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
        Response::error('VALIDATION_ERROR', 'CSV file is required (field: file).');
    }
    $file = $_FILES['file'];
    if (($file['size'] ?? 0) > 5_000_000) {
        Response::error('VALIDATION_ERROR', 'File too large (max 5MB).');
    }
    $name = basename((string) ($file['name'] ?? 'import.csv'));
    $csv = file_get_contents($file['tmp_name']);
    if ($csv === false) {
        Response::error('VALIDATION_ERROR', 'Could not read uploaded file.');
    }
    $accountId = isset($_POST['account_id']) ? (int) $_POST['account_id'] : (isset($body['account_id']) ? (int) $body['account_id'] : 0);
    $result = $service->ingestCsv(
        $userId,
        $name,
        $csv,
        $accountId > 0 ? $accountId : null,
        (string) $user['currency']
    );
    Response::ok($result, 201);
}

Response::error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
