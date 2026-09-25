<?php
declare(strict_types=1);

namespace Nova\Repositories;

use Nova\Helpers\Database;

final class CategoryRepository
{
    public function listForUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM categories WHERE user_id IS NULL OR user_id = ? ORDER BY is_system DESC, name ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function findAccessible(int $id, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM categories WHERE id = ? AND (user_id IS NULL OR user_id = ?) LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByName(string $name): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM categories WHERE name = ? AND user_id IS NULL LIMIT 1'
        );
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
