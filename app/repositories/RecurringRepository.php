<?php
declare(strict_types=1);

namespace Nova\Repositories;

use Nova\Helpers\Database;

final class RecurringRepository
{
    public function listForUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT r.*, c.name AS category_name, a.name AS account_name
             FROM recurring_transactions r
             LEFT JOIN categories c ON c.id = r.category_id
             LEFT JOIN accounts a ON a.id = r.account_id
             WHERE r.user_id = ?
             ORDER BY r.next_date ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function create(int $userId, array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO recurring_transactions
            (user_id, account_id, category_id, name, amount, currency, type, frequency, next_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $data['account_id'],
            $data['category_id'],
            $data['name'],
            $data['amount'],
            $data['currency'],
            $data['type'],
            $data['frequency'],
            $data['next_date'],
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public function dueForUser(int $userId): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM recurring_transactions
             WHERE user_id = ? AND is_active = 1 AND next_date <= ?
             ORDER BY next_date ASC'
        );
        $stmt->execute([$userId, $today]);
        return $stmt->fetchAll();
    }

    public function findOwned(int $id, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM recurring_transactions WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function markRun(int $id, int $userId, string $nextDate): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE recurring_transactions
             SET next_date = ?, last_run_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$nextDate, $id, $userId]);
    }

    public function deactivate(int $id, int $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE recurring_transactions SET is_active = 0, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);
    }
}
