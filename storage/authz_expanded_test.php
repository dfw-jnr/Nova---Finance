<?php
declare(strict_types=1);
/**
 * Authorization: cross-user isolation for txns, accounts, goals, budgets, imports.
 */
require __DIR__ . '/../app/bootstrap.php';

use Nova\Repositories\AccountRepository;
use Nova\Repositories\BudgetRepository;
use Nova\Repositories\CategoryRepository;
use Nova\Repositories\GoalRepository;
use Nova\Repositories\ImportRepository;
use Nova\Repositories\TransactionRepository;
use Nova\Services\AuthService;
use Nova\Services\ImportService;
use Nova\Services\TransactionService;

$fail = 0;
function assert_true(bool $cond, string $msg): void
{
    global $fail;
    echo ($cond ? "PASS" : "FAIL") . " $msg\n";
    if (!$cond) {
        $fail++;
    }
}

$auth = new AuthService();
$s = (string) time() . bin2hex(random_bytes(2));
$a = $auth->register('Alice', "a{$s}@nova.test", 'password123', 'EUR');
$b = $auth->register('Bob', "b{$s}@nova.test", 'password123', 'EUR');
$aid = (int) $a['id'];
$bid = (int) $b['id'];

$svc = new TransactionService();
$accA = (new AccountRepository())->defaultForUser($aid);
$txn = $svc->create($aid, [
    'client_id' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', random_int(1, 999999)),
    'type' => 'expense',
    'amount' => '10.00',
    'merchant' => 'Secret',
    'account_id' => (int) $accA['id'],
    'txn_date' => date('Y-m-d'),
], 'EUR');

$txRepo = new TransactionRepository();
assert_true($txRepo->findOwned((int) $txn['id'], $bid) === null, 'txn isolation');

$accRepo = new AccountRepository();
assert_true($accRepo->findOwned((int) $accA['id'], $bid) === null, 'account isolation');

$goals = new GoalRepository();
$gid = $goals->create($aid, [
    'name' => 'Trip',
    'target_amount' => '100.00',
    'current_amount' => '0.00',
    'currency' => 'EUR',
    'target_date' => null,
    'accent' => '#6EA8FE',
]);
assert_true($goals->findOwned($gid, $bid) === null, 'goal isolation');

$cats = (new CategoryRepository())->listForUser($aid);
$catId = (int) ($cats[0]['id'] ?? 0);
$budgets = new BudgetRepository();
$budId = $budgets->create($aid, 'Food', (int) date('Y'), (int) date('n'), 'EUR', $catId, '50.00');
$listB = $budgets->listForUser($bid, (int) date('Y'), (int) date('n'));
$leaked = false;
foreach ($listB as $row) {
    if ((int) ($row['id'] ?? 0) === $budId) {
        $leaked = true;
    }
}
assert_true(!$leaked, 'budget isolation');

$csv = "Date,Amount,Description\n2026-01-15,12.00,Coffee\n";
$imp = (new ImportService())->ingestCsv($aid, 't.csv', $csv, (int) $accA['id'], 'EUR');
$importId = (int) ($imp['import']['id'] ?? 0);
$impRepo = new ImportRepository();
assert_true($impRepo->findOwned($importId, $bid) === null, 'import isolation');

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n";
exit($fail > 0 ? 1 : 0);
