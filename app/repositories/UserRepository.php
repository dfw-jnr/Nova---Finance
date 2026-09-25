<?php
declare(strict_types=1);

namespace Nova\Repositories;

use Nova\Helpers\Database;

final class UserRepository
{
    public function create(string $name, string $email, string $hash, string $currency): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO users (name, email, password_hash, currency) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, strtolower($email), $hash, $currency]);
        return (int) Database::pdo()->lastInsertId();
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower($email)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updatePassword(int $id, string $hash): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$hash, $id]);
    }

    public function incrementSessionVersion(int $id): int
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE users SET session_version = COALESCE(session_version, 1) + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$id]);
        $user = $this->findById($id);
        return (int) ($user['session_version'] ?? 1);
    }

    public function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
    }
}
