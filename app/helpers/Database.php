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
            if ($isNew || self::needsSchema('sqlite')) {
                $sql = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql');
                self::$pdo->exec($sql ?: '');
            }
            self::ensureReceiptsTable();
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            (int) $cfg['port'],
            $cfg['name'],
            $cfg['charset'] ?? 'utf8mb4'
        );

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if (!empty($cfg['ssl'])) {
            if (!empty($cfg['ssl_ca']) && is_file($cfg['ssl_ca'])) {
                $options[\PDO::MYSQL_ATTR_SSL_CA] = $cfg['ssl_ca'];
            }
            // Required by many managed MySQL hosts (Aiven, etc.)
            $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = !empty($cfg['ssl_verify']);
        }

        self::$pdo = new \PDO($dsn, (string) $cfg['user'], (string) $cfg['pass'], $options);

        if (self::needsSchema('mysql')) {
            self::migrateMysql();
        }
        self::ensureReceiptsTable();
        self::seedSystemCategories();

        return self::$pdo;
    }

    private static function needsSchema(string $driver): bool
    {
        if ($driver === 'sqlite') {
            $stmt = self::$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
            return !$stmt->fetch();
        }
        $stmt = self::$pdo->query("SHOW TABLES LIKE 'users'");
        return !$stmt->fetch();
    }

    private static function migrateMysql(): void
    {
        $path = dirname(__DIR__, 2) . '/database/schema.mysql.sql';
        $sql = file_get_contents($path);
        if ($sql === false || $sql === '') {
            throw new \RuntimeException('Missing database/schema.mysql.sql');
        }
        // Strip comments; run statement-by-statement (FK order already correct)
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement !== '') {
                self::$pdo->exec($statement);
            }
        }
    }

    private static function seedSystemCategories(): void
    {
        $count = (int) self::$pdo->query(
            "SELECT COUNT(*) FROM categories WHERE is_system = 1 AND user_id IS NULL"
        )->fetchColumn();
        if ($count > 0) {
            return;
        }

        $rows = [
            ['Food', 'expense', 'food', '#F5A524'],
            ['Groceries', 'expense', 'groceries', '#3DDC97'],
            ['Transport', 'expense', 'transport', '#5B8DEF'],
            ['Rent', 'expense', 'rent', '#94A3B8'],
            ['Utilities', 'expense', 'utilities', '#F472B6'],
            ['Shopping', 'expense', 'shopping', '#C084FC'],
            ['Entertainment', 'expense', 'entertainment', '#FB7185'],
            ['Education', 'expense', 'education', '#38BDF8'],
            ['Travel', 'expense', 'travel', '#2DD4BF'],
            ['Health', 'expense', 'health', '#34D399'],
            ['Subscriptions', 'expense', 'subscriptions', '#A78BFA'],
            ['Salary', 'income', 'salary', '#3DDC97'],
            ['Freelance', 'income', 'freelance', '#6EA8FE'],
            ['Other', 'both', 'other', '#94A3B8'],
        ];
        $stmt = self::$pdo->prepare(
            'INSERT INTO categories (user_id, name, type, icon, color, is_system) VALUES (NULL, ?, ?, ?, ?, 1)'
        );
        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }

    /** Additive migration so existing MySQL/SQLite DBs get receipts without wipe. */
    private static function ensureReceiptsTable(): void
    {
        $flag = dirname(__DIR__, 2) . '/storage/.receipts_ready';
        if (is_file($flag)) {
            return;
        }

        if (self::isSqlite()) {
            self::$pdo->exec(
                'CREATE TABLE IF NOT EXISTS receipts (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  user_id INTEGER NOT NULL,
                  transaction_id INTEGER NOT NULL UNIQUE,
                  mime TEXT NOT NULL DEFAULT \'image/jpeg\',
                  data_blob BLOB NOT NULL,
                  ocr_text TEXT NULL,
                  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                  FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
                )'
            );
            @file_put_contents($flag, '1');
            return;
        }

        $stmt = self::$pdo->query("SHOW TABLES LIKE 'receipts'");
        if ($stmt && $stmt->fetch()) {
            @file_put_contents($flag, '1');
            return;
        }
        self::$pdo->exec(
            'CREATE TABLE receipts (
              id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              user_id         BIGINT UNSIGNED NOT NULL,
              transaction_id  BIGINT UNSIGNED NOT NULL,
              mime            VARCHAR(64) NOT NULL DEFAULT \'image/jpeg\',
              data_blob       MEDIUMBLOB NOT NULL,
              ocr_text        TEXT NULL,
              created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_receipt_txn (transaction_id),
              KEY idx_receipts_user (user_id),
              CONSTRAINT fk_receipt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT fk_receipt_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        @file_put_contents($flag, '1');
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
