<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Response;
use Nova\Services\AuthService;
use Nova\Services\InsightsService;

$auth = new AuthService();
$user = $auth->requireUser();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$insights = (new InsightsService())->list((int) $user['id']);
Response::ok(['insights' => $insights]);
