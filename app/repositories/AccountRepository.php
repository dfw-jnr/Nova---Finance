<?php
declare(strict_types=1);

namespace Nova\Repositories;

use Nova\Helpers\Database;

final class AccountRepository
{
    public function create(int $userId, string $name, string $type, string $currency, string $balance, bool $isDefault = false): int
    {
        if ($isDefault) {
            Database::pdo()->prepare('UPDATE accounts SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
        }
        $stmt = Database::pdo()->prepare(
            'INSERT INTO accounts (user_id, name, type, currency, balance, is_default) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $name, $type, $currency, $balance, $isDefault ? 1 : 0]);
        return (int) Database::pdo()->lastInsertId();
    }

    public function listForUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM accounts WHERE user_id = ? ORDER BY is_default DESC, name ASC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function findOwned(int $id, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM accounts WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function defaultForUser(int $userId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM accounts WHERE user_id = ? ORDER BY is_default DESC, id ASC LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function adjustBalance(int $accountId, string $delta): void
    {
        $stmt = Database::pdo()->prepare('UPDATE accounts SET balance = balance + ? WHERE id = ?');
        $stmt->execute([$delta, $accountId]);
    }
}
