<?php
declare(strict_types=1);

namespace Nova\Repositories;

use Nova\Helpers\Database;

final class ImportRepository
{
    public function create(int $userId, string $filename, ?array $columnMap = null, ?array $headers = null): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO imports (user_id, filename, status, column_map_json, headers_json) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $filename,
            'pending',
            $columnMap !== null ? json_encode($columnMap, JSON_UNESCAPED_UNICODE) : null,
            $headers !== null ? json_encode(array_values($headers), JSON_UNESCAPED_UNICODE) : null,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public function findOwned(int $id, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM imports WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function setStatus(int $id, int $userId, string $status): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE imports SET status = ? WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$status, $id, $userId]);
    }

    public function updateMeta(int $id, int $userId, array $columnMap, array $headers): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE imports SET column_map_json = ?, headers_json = ? WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([
            json_encode($columnMap, JSON_UNESCAPED_UNICODE),
            json_encode(array_values($headers), JSON_UNESCAPED_UNICODE),
            $id,
            $userId,
        ]);
    }

    public function insertRow(int $importId, int $rowIndex, array $fields): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO import_rows
            (import_id, row_index, raw_json, merchant, amount, txn_date, type, external_id, account_id, status, duplicate_of)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $raw = json_encode($fields['raw'] ?? [], JSON_UNESCAPED_UNICODE);
        $stmt->execute([
            $importId,
            $rowIndex,
            $raw,
            $fields['merchant'] ?? null,
            $fields['amount'] ?? null,
            $fields['txn_date'] ?? null,
            $fields['type'] ?? null,
            $fields['external_id'] ?? null,
            $fields['account_id'] ?? null,
            $fields['status'] ?? 'pending',
            $fields['duplicate_of'] ?? null,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public function listRows(int $importId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM import_rows WHERE import_id = ? ORDER BY row_index ASC'
        );
        $stmt->execute([$importId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $decoded = json_decode((string) ($row['raw_json'] ?? '[]'), true);
            $row['raw'] = is_array($decoded) ? $decoded : [];
        }
        unset($row);
        return $rows;
    }

    public function findRow(int $rowId, int $importId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM import_rows WHERE id = ? AND import_id = ? LIMIT 1'
        );
        $stmt->execute([$rowId, $importId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $decoded = json_decode((string) ($row['raw_json'] ?? '[]'), true);
        $row['raw'] = is_array($decoded) ? $decoded : [];
        return $row;
    }

    public function updateRowStatus(int $rowId, int $importId, string $status): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE import_rows SET status = ? WHERE id = ? AND import_id = ?'
        );
        $stmt->execute([$status, $rowId, $importId]);
    }

    public function updateRowParsed(int $rowId, int $importId, array $fields): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE import_rows SET merchant = ?, amount = ?, txn_date = ?, type = ?, external_id = ?, duplicate_of = ?
             WHERE id = ? AND import_id = ?'
        );
        $stmt->execute([
            $fields['merchant'] ?? null,
            $fields['amount'] ?? null,
            $fields['txn_date'] ?? null,
            $fields['type'] ?? null,
            $fields['external_id'] ?? null,
            $fields['duplicate_of'] ?? null,
            $rowId,
            $importId,
        ]);
    }
}
