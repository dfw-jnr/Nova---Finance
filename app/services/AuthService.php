<?php
declare(strict_types=1);

namespace Nova\Services;

use Nova\Helpers\Database;
use Nova\Helpers\Response;
use Nova\Repositories\UserRepository;
use Nova\Repositories\AccountRepository;
use Nova\Repositories\SettingsRepository;

final class AuthService
{
    public function __construct(
        private UserRepository $users = new UserRepository(),
        private AccountRepository $accounts = new AccountRepository(),
        private SettingsRepository $settings = new SettingsRepository(),
    ) {}

    public function register(string $name, string $email, string $password, string $currency = 'EUR'): array
    {
        if ($this->users->findByEmail($email)) {
            Response::error('EMAIL_EXISTS', 'An account with this email already exists.', 409);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $userId = $this->users->create($name, $email, $hash, $currency);
            $this->accounts->create($userId, 'Main', 'bank', $currency, '0.00', true);
            $this->settings->set($userId, 'theme', 'system');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->loginSession($userId);
        $user = $this->users->findById($userId);
        if ($user) {
            unset($user['password_hash']);
        }
        return $user;
    }

    public function login(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Response::error('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
        }

        $this->loginSession((int) $user['id']);
        unset($user['password_hash']);
        return $user;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $user = $this->users->findById((int) $_SESSION['user_id']);
        if ($user) {
            unset($user['password_hash']);
        }
        return $user;
    }

    public function requireUser(): array
    {
        $user = $this->user();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required.', 401);
        }
        return $user;
    }

    public function changePassword(int $userId, string $current, string $new): void
    {
        $user = $this->users->findById($userId);
        if (!$user || !password_verify($current, $user['password_hash'])) {
            Response::error('INVALID_CREDENTIALS', 'Current password is incorrect.', 401);
        }
        if (strlen($new) < 8) {
            Response::error('VALIDATION_ERROR', 'New password must be at least 8 characters.');
        }
        $this->users->updatePassword($userId, password_hash($new, PASSWORD_DEFAULT));
    }

    private function loginSession(int $userId): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $userId;
        $_SESSION['login_at'] = time();
    }
}
