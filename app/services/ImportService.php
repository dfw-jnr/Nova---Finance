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



        $headers = array_shift($rows);

        $map = $this->detectColumns($headers);

        if ($map['date'] === null || $map['amount'] === null) {

            Response::error('VALIDATION_ERROR', 'Could not detect date and amount columns.');

        }



        $importId = $this->imports->create($userId, $filename);

        $index = 0;

        foreach ($rows as $cells) {

            if ($this->rowEmpty($cells)) {

                continue;

            }

            $parsed = $this->parseRow($cells, $map, $userCurrency);

            if (!$parsed) {

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

                'raw' => array_combine($headers, array_pad($cells, count($headers), '')),

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

            $outRows[] = array_merge($r, [

                'suggested_action' => $suggested,

                'duplicate_score' => !empty($r['duplicate_of']) ? 1.0 : 0.0,

            ]);

        }

        return [

            'import' => $imp,

            'rows' => $outRows,

            'column_map' => null,

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

                $this->imports->updateRowStatus($rowId, $importId, 'kept');

                $kept++;

                continue;

            }

            if ($action !== 'import') {

                continue;

            }



            $accountId = (int) ($row['account_id'] ?? 0);

            $amount = Validator::money($row['amount'] ?? null);

            $merchant = Validator::str((string) ($row['merchant'] ?? ''), 1, 160);

            $txnDate = (string) ($row['txn_date'] ?? '');

            if (!$amount || !$merchant || !Validator::date($txnDate)) {

                $this->imports->updateRowStatus($rowId, $importId, 'error');

                continue;

            }



            $this->transactionService->create($userId, [

                'type' => ($row['type'] ?? 'expense') === 'income' ? 'income' : 'expense',

                'amount' => $amount,

                'merchant' => $merchant,

                'account_id' => $accountId,

                'txn_date' => $txnDate,

                'external_id' => (string) ($row['external_id'] ?? $this->rowHash($accountId, [

                    'amount' => $amount,

                    'txn_date' => $txnDate,

                    'merchant' => $merchant,

                    'type' => $row['type'] ?? 'expense',

                ])),

            ], $userCurrency);

            $this->imports->updateRowStatus($rowId, $importId, 'imported');

            $imported++;

        }



        $this->imports->setStatus($importId, $userId, 'committed');



        return [

            'import_id' => $importId,

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



        $type = 'expense';

        $neg = str_starts_with($amountRaw, '-') || str_starts_with($amountRaw, '(');

        // Strip currency symbols / letters; keep digits, separators, sign
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

}

