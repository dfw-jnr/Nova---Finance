<?php
declare(strict_types=1);

namespace Nova\Services;

use Nova\Helpers\Decimal;
use Nova\Helpers\Response;
use Nova\Helpers\Validator;
use Nova\Repositories\AccountRepository;
use Nova\Repositories\ImportRepository;
use Nova\Repositories\TransactionRepository;

final class ImportService
{
    public function __construct(
        private ImportRepository $imports = new ImportRepository(),
        private TransactionRepository $txns = new TransactionRepository(),
        private AccountRepository $accounts = new AccountRepository(),
        private TransactionService $transactionService = new TransactionService(),
    ) {}

    public function ingestCsv(int $userId, string $filename, string $csvBody, ?int $accountId, string $userCurrency): array
    {
        $account = $accountId ? $this->accounts->findOwned($accountId, $userId) : $this->accounts->defaultForUser($userId);
        if (!$account) {
            Response::error('VALIDATION_ERROR', 'No account available for import.');
        }
        $accountId = (int) $account['id'];

        $rows = $this->parseCsv($csvBody);
        if ($rows === []) {
            Response::error('VALIDATION_ERROR', 'CSV is empty or unreadable.');
        }

        $headers = array_map(static fn ($h) => trim((string) $h), array_shift($rows) ?: []);
        $map = $this->detectColumns($headers);
        if ($map['date'] === null || $map['amount'] === null) {
            Response::error('VALIDATION_ERROR', 'Could not detect date and amount columns. Map them manually after upload.');
        }

        $namedMap = $this->indexMapToNames($headers, $map);
        $importId = $this->imports->create($userId, $filename, $namedMap, $headers);

        $index = 0;
        foreach ($rows as $cells) {
            if ($this->rowEmpty($cells)) {
                continue;
            }
            $raw = $this->combineRaw($headers, $cells);
            $parsed = $this->parseRow($cells, $map, $userCurrency);
            if (!$parsed) {
                $this->imports->insertRow($importId, $index, [
                    'raw' => $raw,
                    'merchant' => null,
                    'amount' => null,
                    'txn_date' => null,
                    'type' => null,
                    'external_id' => null,
                    'account_id' => $accountId,
                    'status' => 'pending',
                    'duplicate_of' => null,
                ]);
                $index++;
                continue;
            }

            $externalId = $this->rowHash($accountId, $parsed);
            $dup = $this->txns->findLikelyDuplicate(
                $userId,
                $accountId,
                $parsed['amount'],
                $parsed['txn_date'],
                $parsed['merchant']
            );

            $this->imports->insertRow($importId, $index, [
                'raw' => $raw,
                'merchant' => $parsed['merchant'],
                'amount' => $parsed['amount'],
                'txn_date' => $parsed['txn_date'],
                'type' => $parsed['type'],
                'external_id' => $externalId,
                'account_id' => $accountId,
                'status' => 'pending',
                'duplicate_of' => $dup ? (int) $dup['id'] : null,
            ]);
            $index++;
        }

        return $this->preview($userId, $importId);
    }

    /**
     * Re-parse pending rows with a user-supplied column map (header names).
     *
     * @param array{date?:string,amount?:string,description?:?string} $namedMap
     */
    public function remap(int $userId, int $importId, array $namedMap, string $userCurrency): array
    {
        $imp = $this->imports->findOwned($importId, $userId);
        if (!$imp) {
            Response::error('NOT_FOUND', 'Import not found.', 404);
        }
        if (($imp['status'] ?? '') === 'committed') {
            Response::error('VALIDATION_ERROR', 'Import already committed.');
        }

        $headers = $this->decodeJsonList($imp['headers_json'] ?? null);
        if ($headers === []) {
            // Infer headers from first row raw keys
            $rows = $this->imports->listRows($importId);
            $headers = array_keys($rows[0]['raw'] ?? []);
        }
        if ($headers === []) {
            Response::error('VALIDATION_ERROR', 'No headers available to remap.');
        }

        $dateName = trim((string) ($namedMap['date'] ?? ''));
        $amountName = trim((string) ($namedMap['amount'] ?? ''));
        $descName = isset($namedMap['description']) ? trim((string) $namedMap['description']) : '';
        if ($dateName === '' || $amountName === '') {
            Response::error('VALIDATION_ERROR', 'Date and Amount columns are required.');
        }

        $map = [
            'date' => $this->headerIndex($headers, $dateName),
            'amount' => $this->headerIndex($headers, $amountName),
            'description' => $descName !== '' ? $this->headerIndex($headers, $descName) : null,
        ];
        if ($map['date'] === null || $map['amount'] === null) {
            Response::error('VALIDATION_ERROR', 'Selected column names were not found in the file.');
        }

        $storeMap = [
            'date' => $dateName,
            'amount' => $amountName,
            'description' => $descName !== '' ? $descName : null,
        ];
        $this->imports->updateMeta($importId, $userId, $storeMap, $headers);

        foreach ($this->imports->listRows($importId) as $row) {
            if (($row['status'] ?? '') !== 'pending') {
                continue;
            }
            $raw = $row['raw'] ?? [];
            $cells = [];
            foreach ($headers as $h) {
                $cells[] = (string) ($raw[$h] ?? '');
            }
            $accountId = (int) ($row['account_id'] ?? 0);
            $parsed = $this->parseRow($cells, $map, $userCurrency);
            if (!$parsed) {
                $this->imports->updateRowParsed((int) $row['id'], $importId, [
                    'merchant' => null,
                    'amount' => null,
                    'txn_date' => null,
                    'type' => null,
                    'external_id' => null,
                    'duplicate_of' => null,
                ]);
                continue;
            }
            $externalId = $this->rowHash($accountId, $parsed);
            $dup = $accountId > 0
                ? $this->txns->findLikelyDuplicate($userId, $accountId, $parsed['amount'], $parsed['txn_date'], $parsed['merchant'])
                : null;
            $this->imports->updateRowParsed((int) $row['id'], $importId, [
                'merchant' => $parsed['merchant'],
                'amount' => $parsed['amount'],
                'txn_date' => $parsed['txn_date'],
                'type' => $parsed['type'],
                'external_id' => $externalId,
                'duplicate_of' => $dup ? (int) $dup['id'] : null,
            ]);
        }

        return $this->preview($userId, $importId);
    }

    public function preview(int $userId, int $importId): array
    {
        $imp = $this->imports->findOwned($importId, $userId);
        if (!$imp) {
            Response::error('NOT_FOUND', 'Import not found.', 404);
        }
        $rows = $this->imports->listRows($importId);
        $outRows = [];
        foreach ($rows as $r) {
            $suggested = 'import';
            if (!empty($r['duplicate_of'])) {
                $suggested = 'keep';
            }
            if (empty($r['txn_date']) || empty($r['amount'])) {
                $suggested = 'skip';
            }
            $outRows[] = array_merge($r, [
                'suggested_action' => $suggested,
                'duplicate_score' => !empty($r['duplicate_of']) ? 1.0 : 0.0,
            ]);
        }

        $headers = $this->decodeJsonList($imp['headers_json'] ?? null);
        if ($headers === [] && $outRows !== []) {
            $headers = array_keys($outRows[0]['raw'] ?? []);
        }
        $columnMap = $this->decodeJsonMap($imp['column_map_json'] ?? null);
        if ($columnMap === [] && $headers !== []) {
            $idx = $this->detectColumns($headers);
            $columnMap = $this->indexMapToNames($headers, $idx);
        }

        return [
            'import' => $imp,
            'rows' => $outRows,
            'headers' => $headers,
            'column_map' => $columnMap,
        ];
    }

    public function commit(int $userId, int $importId, array $rowActions, string $userCurrency): array
    {
        $imp = $this->imports->findOwned($importId, $userId);
        if (!$imp) {
            Response::error('NOT_FOUND', 'Import not found.', 404);
        }

        $imported = 0;
        $skipped = 0;
        $kept = 0;

        foreach ($rowActions as $actionRow) {
            $rowId = (int) ($actionRow['id'] ?? 0);
            $action = (string) ($actionRow['action'] ?? 'skip');
            if ($rowId <= 0) {
                continue;
            }
            $row = $this->imports->findRow($rowId, $importId);
            if (!$row) {
                continue;
            }

            if ($action === 'skip') {
                $this->imports->updateRowStatus($rowId, $importId, 'skipped');
                $skipped++;
                continue;
            }
            if ($action === 'keep') {
                $this->imports->updateRowStatus($rowId, $importId, 'duplicate');
                $kept++;
                continue;
            }
            if ($action !== 'import') {
                continue;
            }
            if (empty($row['amount']) || empty($row['txn_date'])) {
                $this->imports->updateRowStatus($rowId, $importId, 'skipped');
                $skipped++;
                continue;
            }

            $accountId = (int) ($row['account_id'] ?? 0);
            $this->transactionService->create($userId, [
                'type' => ($row['type'] ?? 'expense') === 'income' ? 'income' : 'expense',
                'amount' => $row['amount'],
                'merchant' => $row['merchant'] ?: 'Imported',
                'account_id' => $accountId,
                'txn_date' => $row['txn_date'],
                'external_id' => $row['external_id'] ?: null,
                'client_id' => $this->uuidFromExternal((string) ($row['external_id'] ?? ('row-' . $rowId))),
                'notes' => 'CSV import',
            ], $userCurrency);
            $this->imports->updateRowStatus($rowId, $importId, 'imported');
            $imported++;
        }

        $this->imports->setStatus($importId, $userId, 'committed');

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'kept' => $kept,
        ];
    }

    /** @return list<array<int, string>> */
    private function parseCsv(string $body): array
    {
        $body = preg_replace("/^\xEF\xBB\xBF/", '', $body) ?? $body;
        $lines = preg_split('/\r\n|\n|\r/', trim($body)) ?: [];
        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $out[] = str_getcsv($line);
        }
        return $out;
    }

    /** @param list<string> $headers */
    private function detectColumns(array $headers): array
    {
        $date = null;
        $amount = null;
        $desc = null;
        foreach ($headers as $i => $h) {
            $k = mb_strtolower(trim((string) $h));
            if ($date === null && preg_match('/date|posted|when|time/', $k)) {
                $date = $i;
            }
            if ($amount === null && preg_match('/amount|value|sum|debit|credit|total/', $k)) {
                $amount = $i;
            }
            if ($desc === null && preg_match('/desc|merchant|payee|name|memo|detail/', $k)) {
                $desc = $i;
            }
        }
        if ($date === null) {
            $date = 0;
        }
        if ($amount === null) {
            $amount = count($headers) > 1 ? 1 : 0;
        }
        if ($desc === null) {
            $desc = count($headers) > 2 ? 2 : null;
        }
        return ['date' => $date, 'amount' => $amount, 'description' => $desc];
    }

    /** @param list<string> $headers @param array{date:?int,amount:?int,description:?int} $map */
    private function indexMapToNames(array $headers, array $map): array
    {
        return [
            'date' => isset($map['date']) && isset($headers[$map['date']]) ? $headers[$map['date']] : null,
            'amount' => isset($map['amount']) && isset($headers[$map['amount']]) ? $headers[$map['amount']] : null,
            'description' => isset($map['description']) && $map['description'] !== null && isset($headers[$map['description']])
                ? $headers[$map['description']]
                : null,
        ];
    }

    /** @param list<string> $headers */
    private function headerIndex(array $headers, string $name): ?int
    {
        foreach ($headers as $i => $h) {
            if (strcasecmp((string) $h, $name) === 0) {
                return (int) $i;
            }
        }
        return null;
    }

    /** @param list<string> $headers @param list<string> $cells */
    private function combineRaw(array $headers, array $cells): array
    {
        $raw = [];
        foreach ($headers as $i => $h) {
            $raw[$h !== '' ? $h : ('col_' . $i)] = (string) ($cells[$i] ?? '');
        }
        return $raw;
    }

    /** @param list<string> $cells */
    private function parseRow(array $cells, array $map, string $userCurrency): ?array
    {
        $dateRaw = trim((string) ($cells[$map['date']] ?? ''));
        $amountRaw = trim((string) ($cells[$map['amount']] ?? ''));
        $desc = $map['description'] !== null
            ? trim((string) ($cells[$map['description']] ?? ''))
            : '';

        $txnDate = $this->parseDate($dateRaw);
        if (!$txnDate) {
            return null;
        }

        $neg = str_starts_with($amountRaw, '-') || str_starts_with($amountRaw, '(');
        $amountRaw = preg_replace('/[^\d,.\-()]/u', '', $amountRaw) ?? $amountRaw;
        $amountRaw = str_replace(['(', ')', '+', '-'], '', $amountRaw);
        $amount = Validator::money($amountRaw);
        if (!$amount || Decimal::cmp($amount, Decimal::zero()) === 0) {
            return null;
        }

        if ($neg) {
            $type = 'expense';
        } elseif (preg_match('/income|deposit|salary|payroll|refund/i', $desc)) {
            $type = 'income';
        } else {
            $type = 'expense';
        }

        $merchant = $desc !== '' ? $desc : 'Imported transaction';
        if (mb_strlen($merchant) > 160) {
            $merchant = mb_substr($merchant, 0, 160);
        }

        return [
            'txn_date' => $txnDate,
            'amount' => $amount,
            'merchant' => $merchant,
            'type' => $type,
            'currency' => $userCurrency,
        ];
    }

    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            return substr($raw, 0, 10);
        }
        $formats = ['d/m/Y', 'm/d/Y', 'd.m.Y', 'Y/m/d', 'd-m-Y', 'm-d-Y'];
        foreach ($formats as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $raw);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }
        $ts = strtotime($raw);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
        return null;
    }

    /** @param list<string> $cells */
    private function rowEmpty(array $cells): bool
    {
        foreach ($cells as $c) {
            if (trim((string) $c) !== '') {
                return false;
            }
        }
        return true;
    }

    private function rowHash(int $accountId, array $parsed): string
    {
        $payload = implode('|', [
            $accountId,
            $parsed['txn_date'] ?? '',
            $parsed['amount'] ?? '',
            mb_strtolower(trim((string) ($parsed['merchant'] ?? ''))),
            $parsed['type'] ?? 'expense',
        ]);
        return substr(hash('sha256', $payload), 0, 32);
    }

    private function uuidFromExternal(string $seed): string
    {
        $h = md5('nova-import:' . $seed);
        return sprintf(
            '%08s-%04s-4%03s-a%03s-%012s',
            substr($h, 0, 8),
            substr($h, 8, 4),
            substr($h, 13, 3),
            substr($h, 17, 3),
            substr($h, 20, 12)
        );
    }

    /** @return list<string> */
    private function decodeJsonList(?string $json): array
    {
        if (!$json) {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? array_values(array_map('strval', $data)) : [];
    }

    /** @return array<string, mixed> */
    private function decodeJsonMap(?string $json): array
    {
        if (!$json) {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
