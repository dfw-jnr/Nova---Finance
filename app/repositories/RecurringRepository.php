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
}
