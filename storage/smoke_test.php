<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use Nova\Helpers\Csrf;
use Nova\Helpers\Database;
use Nova\Services\AuthService;
use Nova\Services\TransactionService;

echo "driver=" . Database::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) . PHP_EOL;

$auth = new AuthService();
$email = 'demo' . time() . '@nova.test';
$user = $auth->register('Demo User', $email, 'password123', 'EUR');
echo "user_id=" . $user['id'] . PHP_EOL;

$svc = new TransactionService();
$accounts = (new Nova\Repositories\AccountRepository())->listForUser((int)$user['id']);
$accountId = (int)$accounts[0]['id'];
$cats = (new Nova\Repositories\CategoryRepository())->listForUser((int)$user['id']);
$salary = null;
$food = null;
foreach ($cats as $c) {
    if ($c['name'] === 'Salary') $salary = (int)$c['id'];
    if ($c['name'] === 'Food') $food = (int)$c['id'];
}

$client = '11111111-1111-4111-8111-111111111111';
$a = $svc->create((int)$user['id'], [
    'client_id' => $client,
    'type' => 'income',
    'amount' => '2450.00',
    'merchant' => 'Salary',
    'category_id' => $salary,
    'account_id' => $accountId,
    'txn_date' => date('Y-m-d'),
], 'EUR');
$b = $svc->create((int)$user['id'], [
    'client_id' => $client, // idempotent replay
    'type' => 'income',
    'amount' => '2450.00',
    'merchant' => 'Salary',
    'category_id' => $salary,
    'account_id' => $accountId,
    'txn_date' => date('Y-m-d'),
], 'EUR');
echo "idempotent=" . (($a['id'] === $b['id']) ? 'yes' : 'no') . PHP_EOL;

$svc->create((int)$user['id'], [
    'client_id' => '22222222-2222-4222-8222-222222222222',
    'type' => 'expense',
    'amount' => '10.99',
    'merchant' => 'Spotify',
    'category_id' => $food,
    'account_id' => $accountId,
    'txn_date' => date('Y-m-d'),
], 'EUR');

echo "csrf=" . substr(Csrf::token(), 0, 8) . PHP_EOL;
echo "OK\n";
