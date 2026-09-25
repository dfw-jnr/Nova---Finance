<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Response;
use Nova\Services\AuthService;
use Nova\Services\CalendarService;

$auth = new AuthService();
$user = $auth->requireUser();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');

$data = (new CalendarService())->month((int) $user['id'], $year, $month);
Response::ok($data);
