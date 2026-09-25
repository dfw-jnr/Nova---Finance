<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Response;
use Nova\Services\AuthService;
use Nova\Services\ForecastService;

$auth = new AuthService();
$user = $auth->requireUser();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$horizon = isset($_GET['horizon']) ? (int) $_GET['horizon'] : 30;
if (!in_array($horizon, [30, 60, 90], true)) {
    $horizon = 30;
}

$data = (new ForecastService())->forecast((int) $user['id'], (string) $user['currency'], $horizon);
Response::ok($data);
