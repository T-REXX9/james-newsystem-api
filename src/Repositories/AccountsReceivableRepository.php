<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\Exceptions\HttpException;
use DateTimeImmutable;
use PDO;

final class AccountsReceivableRepository
{
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
        [$normalizedDebtType, $customers] = $this->resolveCustomers($mainId, $customerId, $debtType);
        [$normalizedDateType, $fromDate, $toDate] = $this->resolveDateRange($dateType, $dateFrom, $dateTo);
        $reportsByCustomer = $this->buildCustomerReports($customers, $fromDate, $toDate);

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

        return [
            'customers' => $items,
            'grand_total_balance' => $grandTotalBalance,
            'date_type' => $normalizedDateType,
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'debt_type' => $normalizedDebtType,
        ];
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
                'SELECT * FROM tblpatient WHERE lmain_id = :main_id AND lsessionid = :session_id ORDER BY lid DESC LIMIT 1'
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
SELECT *
FROM tblpatient
WHERE lmain_id = :main_id
  AND COALESCE(lstatus, 0) = 1
SQL;
        if ($normalizedDebtType !== 'All') {
            $sql .= ' AND COALESCE(ldebt_type, \'\') = :debt_type';
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
    private function buildCustomerReports(array $customers, ?string $fromDate, ?string $toDate): array
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
    COALESCE(l.lrefno, '') AS refno,
    COALESCE(l.lmesssage, '') AS reference,
    COALESCE(l.ldebit, 0) AS amount,
    COALESCE(l.ldatetime, '') AS ldatetime
FROM tblledger l
WHERE l.lcustomerid IN ({$customerWhere})
  AND l.ltype = 'Debit'
  AND COALESCE(l.lref_name, '') <> 'Debit Memo'
{$dateWhere}
ORDER BY l.lcustomerid ASC, l.ldatetime ASC, l.lid ASC
SQL;

        $rowsStmt = $this->db->pdo()->prepare($rowsSql);
        foreach ($params as $key => $value) {
            $rowsStmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $rowsStmt->execute();
        $rawRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
        $termsByRefno = $this->buildTermsMap($rawRows);

        $creditSql = <<<SQL
SELECT l.lcustomerid AS customer_id, COALESCE(SUM(l.lcredit), 0) AS credit_sum
FROM tblledger l
WHERE l.lcustomerid IN ({$customerWhere})
  AND l.ltype = 'Credit'
{$dateWhere}
GROUP BY l.lcustomerid
SQL;
        $creditStmt = $this->db->pdo()->prepare($creditSql);
        foreach ($params as $key => $value) {
            $creditStmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $creditStmt->execute();
        $creditSums = [];
        foreach ($creditStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $creditSums[(string) ($row['customer_id'] ?? '')] = (float) ($row['credit_sum'] ?? 0);
        }

        $freightSql = <<<SQL
SELECT l.lcustomerid AS customer_id, COALESCE(SUM(l.ldebit), 0) AS freight_sum
FROM tblledger l
WHERE l.lcustomerid IN ({$customerWhere})
  AND l.ltype = 'Debit'
  AND l.lref_name = 'Debit Memo'
{$dateWhere}
GROUP BY l.lcustomerid
SQL;
        $freightStmt = $this->db->pdo()->prepare($freightSql);
        foreach ($params as $key => $value) {
            $freightStmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $freightStmt->execute();
        $freightSums = [];
        foreach ($freightStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $freightSums[(string) ($row['customer_id'] ?? '')] = (float) ($row['freight_sum'] ?? 0);
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
            "SELECT lid, COALESCE(invoice_refno, '') AS invoice_refno, COALESCE(ldr_refno, '') AS ldr_refno, COALESCE(lterms, '') AS lterms
             FROM tbltransaction
             WHERE invoice_refno IN (%s) OR ldr_refno IN (%s)
             ORDER BY lid DESC",
            implode(', ', $invoicePlaceholders),
            implode(', ', $deliveryPlaceholders)
        );

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        $termsByRefno = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $invoiceRefno = trim((string) ($row['invoice_refno'] ?? ''));
            if ($invoiceRefno !== '' && isset($neededRefnos[$invoiceRefno]) && !isset($termsByRefno[$invoiceRefno])) {
                $termsByRefno[$invoiceRefno] = (string) ($row['lterms'] ?? '');
            }

            $ldrRefno = trim((string) ($row['ldr_refno'] ?? ''));
            if ($ldrRefno !== '' && isset($neededRefnos[$ldrRefno]) && !isset($termsByRefno[$ldrRefno])) {
                $termsByRefno[$ldrRefno] = (string) ($row['lterms'] ?? '');
            }

            if (count($termsByRefno) === count($neededRefnos)) {
                break;
            }
        }

        return $termsByRefno;
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
