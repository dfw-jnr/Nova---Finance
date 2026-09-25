<?php
declare(strict_types=1);
/**
 * Session version / logout-all invalidation.
 */
require __DIR__ . '/../app/bootstrap.php';

use Nova\Repositories\UserRepository;
use Nova\Services\AuthService;

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
$user = $auth->register('Sess', "s{$s}@nova.test", 'password123', 'EUR');
$uid = (int) $user['id'];

$v1 = (int) ($_SESSION['session_version'] ?? 0);
assert_true($v1 >= 1, 'session version set on register');
assert_true($auth->user() !== null, 'user authenticated');

$users = new UserRepository();
$newVer = $users->incrementSessionVersion($uid);
assert_true($newVer > $v1, 'version incremented');

// Stale session should fail requireUser path via user()
$stale = $auth->user();
assert_true($stale === null, 'stale session rejected by user()');

// Fresh login restores
$auth->login("s{$s}@nova.test", 'password123');
assert_true($auth->user() !== null, 're-login works');
assert_true((int) $_SESSION['session_version'] === $newVer, 'session version matches DB');

$auth->logoutAll($uid);
assert_true($auth->user() === null, 'logoutAll clears session');

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n";
exit($fail > 0 ? 1 : 0);
