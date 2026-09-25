<?php
declare(strict_types=1);
/**
 * Money math + transfer authz smoke tests (CLI).
 */
require __DIR__ . '/../app/bootstrap.php';

use Nova\Helpers\Decimal;
use Nova\Repositories\AccountRepository;
use Nova\Repositories\TransactionRepository;
use Nova\Services\AuthService;
use Nova\Services\TransactionService;

$fail = 0;
function assert_true(bool $cond, string $msg): void
{
    global $fail;
    if ($cond) {
        echo "PASS $msg\n";
    } else {
        echo "FAIL $msg\n";
        $fail++;
    }
}

assert_true(Decimal::add('10.10', '0.20') === '10.30', 'add cents');
assert_true(Decimal::sub('10.00', '0.01') === '9.99', 'sub');
assert_true(Decimal::cmp('1.00', '1.00') === 0, 'cmp eq');
assert_true(Decimal::normalize('1.999') === '2.00', 'round');

$auth = new AuthService();
$suffix = (string) time() . bin2hex(random_bytes(2));
$a = $auth->register('Alice', "alice{$suffix}@nova.test", 'password123', 'EUR');
$b = $auth->register('Bob', "bob{$suffix}@nova.test", 'password123', 'EUR');
$svc = new TransactionService();
$accRepo = new AccountRepository();
$accA1 = $accRepo->defaultForUser((int) $a['id']);
$accA2 = $accRepo->create((int) $a['id'], 'Savings', 'savings', 'EUR', '0.00', false);
$accB = $accRepo->defaultForUser((int) $b['id']);

// Seed Alice bank
$svc->create((int) $a['id'], [
    'client_id' => sprintf('11111111-1111-4111-8111-%012d', random_int(1, 999999)),
    'type' => 'income',
    'amount' => '100.00',
    'merchant' => 'Salary',
    'account_id' => (int) $accA1['id'],
    'txn_date' => date('Y-m-d'),
], 'EUR');

$xfer = $svc->transfer((int) $a['id'], [
    'from_account_id' => (int) $accA1['id'],
    'to_account_id' => $accA2,
    'amount' => '25.00',
    'txn_date' => date('Y-m-d'),
], 'EUR');
assert_true(($xfer['type'] ?? '') === 'transfer', 'transfer created');
assert_true(count($xfer['legs'] ?? []) === 2, 'two legs');

$sum = (new TransactionRepository())->summary((int) $a['id']);
assert_true($sum['expenses'] === '0.00', 'transfer not expense');
assert_true(Decimal::cmp($sum['income'], '100.00') === 0, 'income still 100');

$a1 = $accRepo->findOwned((int) $accA1['id'], (int) $a['id']);
$a2 = $accRepo->findOwned($accA2, (int) $a['id']);
assert_true(Decimal::cmp((string) $a1['balance'], '75.00') === 0, 'from balance 75');
assert_true(Decimal::cmp((string) $a2['balance'], '25.00') === 0, 'to balance 25');

// Authz: Bob must not own Alice's accounts (transfer would 403 via Response::error/exit)
assert_true($accRepo->findOwned((int) $accA1['id'], (int) $b['id']) === null, 'bob cannot own alice from');
assert_true($accRepo->findOwned($accA2, (int) $b['id']) === null, 'bob cannot own alice to');
assert_true($accRepo->findOwned((int) $accB['id'], (int) $b['id']) !== null, 'bob owns own account');

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n";
exit($fail > 0 ? 1 : 0);
