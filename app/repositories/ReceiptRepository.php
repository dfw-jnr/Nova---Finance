<?php
declare(strict_types=1);

namespace Nova\Repositories;

use Nova\Helpers\Database;

final class ReceiptRepository
{
    public function upsert(int $userId, int $transactionId, string $mime, string $binary, ?string $ocrText = null): void
    {
        $pdo = Database::pdo();
        if (Database::isSqlite()) {
            $pdo->prepare('DELETE FROM receipts WHERE transaction_id = ? AND user_id = ?')
                ->execute([$transactionId, $userId]);
            $stmt = $pdo->prepare(
                'INSERT INTO receipts (user_id, transaction_id, mime, data_blob, ocr_text) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
            $stmt->bindValue(2, $transactionId, \PDO::PARAM_INT);
            $stmt->bindValue(3, $mime);
            $stmt->bindValue(4, $binary, \PDO::PARAM_LOB);
            $stmt->bindValue(5, $ocrText);
            $stmt->execute();
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO receipts (user_id, transaction_id, mime, data_blob, ocr_text)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE mime = VALUES(mime), data_blob = VALUES(data_blob), ocr_text = VALUES(ocr_text)'
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $transactionId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $mime);
        $stmt->bindValue(4, $binary, \PDO::PARAM_LOB);
        $stmt->bindValue(5, $ocrText);
        $stmt->execute();
    }

    public function findForTransaction(int $transactionId, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, user_id, transaction_id, mime, data_blob, ocr_text, created_at
             FROM receipts WHERE transaction_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$transactionId, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function hasForTransaction(int $transactionId, int $userId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM receipts WHERE transaction_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$transactionId, $userId]);
        return (bool) $stmt->fetchColumn();
    }
}
