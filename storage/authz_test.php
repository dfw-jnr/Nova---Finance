<?php
declare(strict_types=1);
/**
 * Verify user A cannot read user B transactions.
 */
require __DIR__ . '/../app/bootstrap.php';

use Nova\Repositories\AccountRepository;
use Nova\Repositories\TransactionRepository;
use Nova\Services\AuthService;
use Nova\Services\TransactionService;

$auth = new AuthService();
$a = $auth->register('Alice', 'alice' . time() . '@nova.test', 'password123', 'EUR');
$b = $auth->register('Bob', 'bob' . time() . '@nova.test', 'password123', 'EUR');

$svc = new TransactionService();
$accA = (new AccountRepository())->defaultForUser((int)$a['id']);
$txn = $svc->create((int)$a['id'], [
    'client_id' => '33333333-3333-4333-8333-333333333333',
    'type' => 'expense',
    'amount' => '42.00',
    'merchant' => 'Secret',
    'account_id' => (int)$accA['id'],
    'txn_date' => date('Y-m-d'),
], 'EUR');

$repo = new TransactionRepository();
$stolen = $repo->findOwned((int)$txn['id'], (int)$b['id']);
echo $stolen ? "FAIL leak\n" : "PASS isolation\n";
