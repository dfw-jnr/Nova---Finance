<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Response;
use Nova\Repositories\CategoryRepository;
use Nova\Services\AuthService;

$auth = new AuthService();
$user = $auth->requireUser();
$repo = new CategoryRepository();
Response::ok($repo->listForUser((int) $user['id']));
