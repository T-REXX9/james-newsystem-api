<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\Exceptions\HttpException;
use DateTimeImmutable;
use PDO;

final class AccountsReceivableRepository
{
    private const TIMING_LOG_LABEL = 'AccountsReceivableRepository';

    public function __construct(private readonly Database $db)
    {
    }

    public function getReport(
        int $mainId,
        string $customerId,
        string $debtType,
        string $dateType,
        ?string $dateFrom,
        ?string $dateTo
    ): array {
        $requestStartedAt = microtime(true);
        $stageTimings = [];

        [$normalizedDebtType, $customers] = $this->timeStage(
            $stageTimings,
            'resolve_customers_ms',
            fn (): array => $this->resolveCustomers($mainId, $customerId, $debtType)
        );
        [$normalizedDateType, $fromDate, $toDate] = $this->resolveDateRange($dateType, $dateFrom, $dateTo);
        $reportsByCustomer = $this->buildCustomerReports($customers, $fromDate, $toDate, $stageTimings);

        $items = [];
        $grandTotalBalance = 0.0;

        foreach ($customers as $customer) {
            $sessionId = (string) ($customer['lsessionid'] ?? '');
            $rows = $reportsByCustomer[$sessionId]['rows'] ?? [];
            $customerBalance = array_reduce(
                $rows,
                static fn (float $carry, array $row): float => $carry + (float) ($row['balance'] ?? 0),
                0.0
            );

            if ($customerBalance <= 0) {
                continue;
            }

            $items[] = [
                'session_id' => (string) ($customer['lsessionid'] ?? ''),
                'customer_code' => (string) ($customer['lpatient_code'] ?? ''),
                'company' => (string) ($customer['lcompany'] ?? ''),
                'rows' => $rows,
                'customer_balance' => $customerBalance,
            ];

            $grandTotalBalance += $customerBalance;
        }

        $response = [
            'customers' => $items,
            'grand_total_balance' => $grandTotalBalance,
            'date_type' => $normalizedDateType,
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'debt_type' => $normalizedDebtType,
        ];

        $stageTimings['total_ms'] = $this->elapsedMilliseconds($requestStartedAt);
        $this->logTimings($stageTimings, [
            'customer_scope' => trim($customerId) !== '' ? 'single' : 'multi',
            'requested_customers' => count($customers),
            'returned_customers' => count($items),
            'returned_rows' => array_sum(array_map(
                static fn (array $customer): int => count($customer['rows'] ?? []),
                $items
            )),
            'date_type' => $normalizedDateType,
            'debt_type' => $normalizedDebtType,
        ]);

        return $response;
    }

    /**
     * @return array{0:string,1:list<array<string,mixed>>}
     */
    private function resolveCustomers(int $mainId, string $customerId, string $debtType): array
    {
        $normalizedDebtType = match (strtolower(trim($debtType))) {
            'good' => 'Good',
            'bad' => 'Bad',
            default => 'All',
        };

        if (trim($customerId) !== '') {
            $stmt = $this->db->pdo()->prepare(
                'SELECT lsessionid, lpatient_code, lcompany
                 FROM tblpatient
                 WHERE lmain_id = :main_id AND lsessionid = :session_id
                 ORDER BY lid DESC
                 LIMIT 1'
            );
            $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
            $stmt->bindValue('session_id', trim($customerId), PDO::PARAM_STR);
            $stmt->execute();
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$customer) {
                return [$normalizedDebtType, []];
            }

            return [$normalizedDebtType, [$customer]];
        }

        $sql = <<<SQL
SELECT lsessionid, lpatient_code, lcompany
FROM tblpatient
WHERE lmain_id = :main_id
  AND lstatus = 1
SQL;
        if ($normalizedDebtType !== 'All') {
            $sql .= ' AND ldebt_type = :debt_type';
        }
        $sql .= ' ORDER BY lcompany ASC, lid DESC LIMIT 200';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        if ($normalizedDebtType !== 'All') {
            $stmt->bindValue('debt_type', $normalizedDebtType, PDO::PARAM_STR);
        }
        $stmt->execute();

        return [$normalizedDebtType, $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    /**
     * @param list<array<string,mixed>> $customers
     * @return array<string,array{rows:list<array<string,mixed>>}>
     */
    private function buildCustomerReports(array $customers, ?string $fromDate, ?string $toDate, array &$stageTimings): array
    {
        $customerIds = array_values(array_filter(array_map(
            static fn (array $customer): string => (string) ($customer['lsessionid'] ?? ''),
            $customers
        )));
        if ($customerIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($customerIds as $index => $customerId) {
            $key = 'customer_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $customerId;
        }

        $dateWhere = '';
        if ($fromDate !== null && $toDate !== null) {
            $dateWhere = ' AND l.ldatetime >= :date_from_start AND l.ldatetime < :date_to_end';
            $params['date_from_start'] = $fromDate . ' 00:00:00';
            $params['date_to_end'] = date('Y-m-d 00:00:00', strtotime($toDate . ' +1 day'));
        }
        $customerWhere = implode(', ', $placeholders);

        $reports = [];
        foreach ($customerIds as $customerId) {
            $reports[$customerId] = ['rows' => []];
        }

        $rowsSql = <<<SQL
SELECT
    l.lid,
    l.lcustomerid AS customer_id,
    l.lrefno AS refno,
    l.lmesssage AS reference,
    l.ldebit AS amount,
    l.ldatetime AS ldatetime
FROM tblledger l
WHERE l.lcustomerid IN ({$customerWhere})
  AND l.ltype = 'Debit'
  AND (l.lref_name IS NULL OR l.lref_name <> 'Debit Memo')
{$dateWhere}
ORDER BY l.lcustomerid ASC, l.ldatetime ASC, l.lid ASC
SQL;

        $rawRows = $this->timeStage(
            $stageTimings,
            'debit_rows_fetch_ms',
            fn (): array => $this->fetchAllAssoc($rowsSql, $params)
        );
        $termsByRefno = $this->timeStage(
            $stageTimings,
            'terms_lookup_ms',
            fn (): array => $this->buildTermsMap($rawRows)
        );

        $aggregateSql = <<<SQL
SELECT
    l.lcustomerid AS customer_id,
    SUM(CASE WHEN l.ltype = 'Credit' THEN l.lcredit ELSE 0 END) AS credit_sum,
    SUM(CASE WHEN l.ltype = 'Debit' AND l.lref_name = 'Debit Memo' THEN l.ldebit ELSE 0 END) AS freight_sum
FROM tblledger l
WHERE l.lcustomerid IN ({$customerWhere})
  AND (
    l.ltype = 'Credit'
    OR (l.ltype = 'Debit' AND l.lref_name = 'Debit Memo')
  )
{$dateWhere}
GROUP BY l.lcustomerid
SQL;

        $aggregateRows = $this->timeStage(
            $stageTimings,
            'credit_and_freight_fetch_ms',
            fn (): array => $this->fetchAllAssoc($aggregateSql, $params)
        );
        $creditSums = [];
        $freightSums = [];
        foreach ($aggregateRows as $row) {
            $customerId = (string) ($row['customer_id'] ?? '');
            if ($customerId === '') {
                continue;
            }

            $creditSums[$customerId] = (float) ($row['credit_sum'] ?? 0);
            $freightSums[$customerId] = (float) ($row['freight_sum'] ?? 0);
        }

        $creditPools = [];
        foreach ($customerIds as $customerId) {
            $creditPools[$customerId] = max(
                0.0,
                (float) ($creditSums[$customerId] ?? 0) - (float) ($freightSums[$customerId] ?? 0)
            );
        }

        foreach ($rawRows as $row) {
            $customerId = (string) ($row['customer_id'] ?? '');
            if ($customerId === '') {
                continue;
            }

            $amount = (float) ($row['amount'] ?? 0);
            $creditPool = (float) ($creditPools[$customerId] ?? 0);
            $amountPaid = min($creditPool, $amount);
            $balance = max(0.0, $amount - $amountPaid);
            $creditPools[$customerId] = max(0.0, $creditPool - $amountPaid);

            if ($balance <= 0) {
                continue;
            }

            $reports[$customerId]['rows'][] = [
                'terms' => (string) ($termsByRefno[(string) ($row['refno'] ?? '')] ?? ''),
                'date' => $this->normalizeDate((string) ($row['ldatetime'] ?? '')),
                'reference' => (string) ($row['reference'] ?? ''),
                'amount' => $amount,
                'amount_paid' => $amountPaid,
                'balance' => $balance,
            ];
        }

        return $reports;
    }

    /**
     * @param list<array<string,mixed>> $rawRows
     * @return array<string,string>
     */
    private function buildTermsMap(array $rawRows): array
    {
        $neededRefnos = [];
        foreach ($rawRows as $row) {
            $refno = trim((string) ($row['refno'] ?? ''));
            if ($refno !== '') {
                $neededRefnos[$refno] = true;
            }
        }

        if ($neededRefnos === []) {
            return [];
        }

        $refnos = array_keys($neededRefnos);
        $invoicePlaceholders = [];
        $deliveryPlaceholders = [];
        $params = [];
        foreach ($refnos as $index => $refno) {
            $invoiceKey = 'invoice_refno_' . $index;
            $deliveryKey = 'delivery_refno_' . $index;
            $invoicePlaceholders[] = ':' . $invoiceKey;
            $deliveryPlaceholders[] = ':' . $deliveryKey;
            $params[$invoiceKey] = $refno;
            $params[$deliveryKey] = $refno;
        }

        $sql = sprintf(
            "SELECT lid, invoice_refno, '' AS ldr_refno, lterms
             FROM tbltransaction
             WHERE invoice_refno IN (%s)
             UNION ALL
             SELECT lid, '' AS invoice_refno, ldr_refno, lterms
             FROM tbltransaction
             WHERE ldr_refno IN (%s)",
            implode(', ', $invoicePlaceholders),
            implode(', ', $deliveryPlaceholders)
        );

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        $termsByRefno = [];
        $latestTermIdsByRefno = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $lid = (int) ($row['lid'] ?? 0);
            $invoiceRefno = trim((string) ($row['invoice_refno'] ?? ''));
            if (
                $invoiceRefno !== ''
                && isset($neededRefnos[$invoiceRefno])
                && $lid >= (int) ($latestTermIdsByRefno[$invoiceRefno] ?? 0)
            ) {
                $termsByRefno[$invoiceRefno] = (string) ($row['lterms'] ?? '');
                $latestTermIdsByRefno[$invoiceRefno] = $lid;
            }

            $ldrRefno = trim((string) ($row['ldr_refno'] ?? ''));
            if (
                $ldrRefno !== ''
                && isset($neededRefnos[$ldrRefno])
                && $lid >= (int) ($latestTermIdsByRefno[$ldrRefno] ?? 0)
            ) {
                $termsByRefno[$ldrRefno] = (string) ($row['lterms'] ?? '');
                $latestTermIdsByRefno[$ldrRefno] = $lid;
            }
        }

        return $termsByRefno;
    }

    /**
     * @param array<string,scalar|null> $params
     * @return list<array<string,mixed>>
     */
    private function fetchAllAssoc(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value === null ? null : (string) $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @template T
     * @param array<string,float> $stageTimings
     * @param callable():T $callback
     * @return T
     */
    private function timeStage(array &$stageTimings, string $label, callable $callback): mixed
    {
        $startedAt = microtime(true);
        $result = $callback();
        $stageTimings[$label] = $this->elapsedMilliseconds($startedAt);

        return $result;
    }

    private function elapsedMilliseconds(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 3);
    }

    /**
     * @param array<string,float> $stageTimings
     * @param array<string,int|string> $context
     */
    private function logTimings(array $stageTimings, array $context): void
    {
        if (!$this->shouldLogTimings()) {
            return;
        }

        $parts = [];
        foreach ($stageTimings as $label => $value) {
            $parts[] = $label . '=' . number_format($value, 3, '.', '') . 'ms';
        }
        foreach ($context as $label => $value) {
            $parts[] = $label . '=' . $value;
        }

        error_log(self::TIMING_LOG_LABEL . ' ' . implode(' ', $parts));
    }

    private function shouldLogTimings(): bool
    {
        $debugValue = $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?? 'false';
        return filter_var($debugValue, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array{0:string,1:?string,2:?string}
     */
    private function resolveDateRange(string $dateType, ?string $dateFrom, ?string $dateTo): array
    {
        $type = strtolower(trim($dateType));
        if ($type === '') {
            $type = 'all';
        }

        $today = new DateTimeImmutable('today');
        return match ($type) {
            'today' => ['today', $today->format('Y-m-d'), $today->format('Y-m-d')],
            'week' => ['week', $today->modify('-1 week')->format('Y-m-d'), $today->format('Y-m-d')],
            'month' => ['month', $today->modify('-1 month')->format('Y-m-d'), $today->format('Y-m-d')],
            'year' => ['year', $today->modify('-1 year')->format('Y-m-d'), $today->format('Y-m-d')],
            'custom' => ['custom', $this->normalizeDate((string) $dateFrom), $this->normalizeDate((string) $dateTo)],
            default => ['all', null, null],
        };
    }

    private function normalizeDate(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '0000-00-00' || $trimmed === '0000-00-00 00:00:00') {
            return null;
        }

        $ts = strtotime($trimmed);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts);
    }
}
