<?php
declare(strict_types=1);

namespace Nova\Helpers;

final class Database
{
    private static ?\PDO $pdo = null;

    public static function connect(array $cfg): \PDO
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }

        $driver = $cfg['driver'] ?? 'mysql';

        if ($driver === 'sqlite') {
            $path = $cfg['sqlite_path'] ?? (dirname(__DIR__, 2) . '/storage/nova.sqlite');
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            $isNew = !is_file($path);
            self::$pdo = new \PDO('sqlite:' . $path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            if ($isNew || self::needsSqliteSchema()) {
                $sql = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql');
                self::$pdo->exec($sql ?: '');
            }
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            (int) $cfg['port'],
            $cfg['name'],
            $cfg['charset']
        );

        self::$pdo = new \PDO($dsn, $cfg['user'], $cfg['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$pdo;
    }

    private static function needsSqliteSchema(): bool
    {
        $stmt = self::$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        return !$stmt->fetch();
    }

    public static function pdo(): \PDO
    {
        if (!self::$pdo) {
            throw new \RuntimeException('Database not connected');
        }
        return self::$pdo;
    }

    public static function isSqlite(): bool
    {
        return self::$pdo && self::$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }
}
