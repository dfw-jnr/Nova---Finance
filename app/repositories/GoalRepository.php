<?php
declare(strict_types=1);

namespace Nova\Repositories;

use Nova\Helpers\Database;

final class GoalRepository
{
    public function listForUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM goals WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function findOwned(int $id, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM goals WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(int $userId, array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO goals (user_id, name, target_amount, current_amount, currency, target_date, accent)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $data['name'],
            $data['target_amount'],
            $data['current_amount'] ?? '0.00',
            $data['currency'],
            $data['target_date'] ?? null,
            $data['accent'] ?? '#6EA8FE',
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public function addAmount(int $id, int $userId, string $amount): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE goals SET current_amount = current_amount + ? WHERE id = ? AND user_id = ?'
        );
        return $stmt->execute([$amount, $id, $userId]);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = Database::pdo()->prepare('DELETE FROM goals WHERE id = ? AND user_id = ?');
        return $stmt->execute([$id, $userId]);
    }
}
