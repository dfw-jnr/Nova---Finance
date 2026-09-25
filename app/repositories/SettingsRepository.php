<?php
declare(strict_types=1);

namespace Nova\Repositories;

use Nova\Helpers\Database;

final class SettingsRepository
{
    public function get(int $userId, string $key, ?string $default = null): ?string
    {
        $stmt = Database::pdo()->prepare(
            'SELECT setting_value FROM settings WHERE user_id = ? AND setting_key = ? LIMIT 1'
        );
        $stmt->execute([$userId, $key]);
        $row = $stmt->fetch();
        return $row ? (string) $row['setting_value'] : $default;
    }

    public function set(int $userId, string $key, string $value): void
    {
        if (Database::isSqlite()) {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO settings (user_id, setting_key, setting_value) VALUES (?, ?, ?)
                 ON CONFLICT(user_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value'
            );
            $stmt->execute([$userId, $key, $value]);
            return;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO settings (user_id, setting_key, setting_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([$userId, $key, $value]);
    }

    public function all(int $userId): array
    {
        $stmt = Database::pdo()->prepare('SELECT setting_key, setting_value FROM settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
        return $out;
    }
}
