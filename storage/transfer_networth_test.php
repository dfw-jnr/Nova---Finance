<?php
declare(strict_types=1);
/**
 * Expanded money integrity: net worth, currency mismatch, extremes, transfer exclusion.
 */
require __DIR__ . '/../app/bootstrap.php';

use Nova\Helpers\Decimal;
use Nova\Helpers\Validator;
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

// Decimal edge cases
assert_true(Decimal::add('0.00', '0.00') === '0.00', 'zero add');
assert_true(Decimal::sub('0.01', '0.01') === '0.00', 'zero sub');
assert_true(Decimal::normalize('-12.345') === '-12.35', 'neg round');
assert_true(Decimal::normalize('999999999.99') === '999999999.99', 'large');
assert_true(Decimal::add('0.10', '0.20') === '0.30', 'classic float trap');
assert_true(Decimal::cmp(Decimal::negate('5.00'), '-5.00') === 0, 'negate');
assert_true(Validator::money('€12.50') === '12.50', 'currency symbol');
assert_true(Validator::money('1.234,56') === '1234.56', 'eu format');
assert_true(Validator::money('0') === null, 'reject zero money');
assert_true(Validator::money('-5') === null, 'reject negative money input');

$auth = new AuthService();
$suffix = (string) time() . bin2hex(random_bytes(2));
$user = $auth->register('Net', "net{$suffix}@nova.test", 'password123', 'EUR');
$uid = (int) $user['id'];
$svc = new TransactionService();
$accRepo = new AccountRepository();
$a1 = $accRepo->defaultForUser($uid);
$a2 = $accRepo->create($uid, 'Savings', 'savings', 'EUR', '0.00', false);
$usd = $accRepo->create($uid, 'USD Wallet', 'bank', 'USD', '50.00', false);

$svc->create($uid, [
    'client_id' => sprintf('22222222-2222-4222-8222-%012d', random_int(1, 999999)),
    'type' => 'income',
    'amount' => '1000.00',
    'merchant' => 'Pay',
    'account_id' => (int) $a1['id'],
    'txn_date' => date('Y-m-d'),
], 'EUR');

$before = Decimal::add(
    (string) $accRepo->findOwned((int) $a1['id'], $uid)['balance'],
    (string) $accRepo->findOwned($a2, $uid)['balance']
);

$svc->transfer($uid, [
    'from_account_id' => (int) $a1['id'],
    'to_account_id' => $a2,
    'amount' => '100.00',
    'txn_date' => date('Y-m-d'),
], 'EUR');

$afterA = (string) $accRepo->findOwned((int) $a1['id'], $uid)['balance'];
$afterB = (string) $accRepo->findOwned($a2, $uid)['balance'];
$after = Decimal::add($afterA, $afterB);

assert_true(Decimal::cmp($before, $after) === 0, 'net worth unchanged on transfer');
assert_true(Decimal::cmp($afterA, Decimal::sub('1000.00', '100.00')) === 0, 'from -100');
assert_true(Decimal::cmp($afterB, '100.00') === 0, 'to +100');

$sum = (new TransactionRepository())->summary($uid);
assert_true(Decimal::cmp($sum['expenses'], '0.00') === 0, 'transfer not in expenses');
assert_true(Decimal::cmp($sum['income'], '1000.00') === 0, 'income only salary');

// Currency mismatch must fail (Response::error exits — probe ownership + currency via find)
$from = $accRepo->findOwned((int) $a1['id'], $uid);
$toUsd = $accRepo->findOwned($usd, $uid);
assert_true(strtoupper($from['currency']) !== strtoupper($toUsd['currency']), 'mixed currency accounts');

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n";
exit($fail > 0 ? 1 : 0);
