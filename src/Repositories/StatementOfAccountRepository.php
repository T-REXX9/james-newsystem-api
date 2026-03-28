<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class StatementOfAccountRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function listCustomers(int $mainId, string $search = '', int $limit = 100, ?string $userType = null): array
    {
        $params = [];
        if (trim((string) $userType) !== '1') {
            $sql = <<<SQL
SELECT
    p.lsessionid AS session_id,
    COALESCE(NULLIF(TRIM(p.lpatient_code), ''), '') AS customer_code,
    COALESCE(NULLIF(TRIM(p.lcompany), ''), '') AS company,
    COALESCE(NULLIF(TRIM(p.lstatus), ''), '') AS status
FROM tblpatient p
WHERE p.lmain_id = :main_id
  AND COALESCE(p.lstatus, 0) = 1
SQL;
            $params['main_id'] = $mainId;
        } else {
            $sql = <<<SQL
SELECT
    p.lsessionid AS session_id,
    COALESCE(NULLIF(TRIM(p.lpatient_code), ''), '') AS customer_code,
    COALESCE(NULLIF(TRIM(p.lcompany), ''), '') AS company,
    COALESCE(NULLIF(TRIM(p.lstatus), ''), '') AS status
FROM tblpatient p
WHERE COALESCE(p.lstatus, 0) = 1
SQL;
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $sql .= ' AND ('
                . 'p.lcompany LIKE :search_company '
                . 'OR p.lpatient_code LIKE :search_code '
                . 'OR p.lsessionid LIKE :search_session'
                . ')';
            $like = '%' . $trimmedSearch . '%';
            $params['search_company'] = $like;
            $params['search_code'] = $like;
            $params['search_session'] = $like;
        }

        $sql .= ' ORDER BY p.lcompany ASC, p.lid DESC LIMIT :limit';
        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === 'main_id') {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }
            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStatementOfAccount(
        string $customerId,
        string $reportType,
        string $dateType,
        ?string $dateFrom,
        ?string $dateTo
    ): array {
        $customer = $this->findCustomerBySession($customerId);
        if ($customer === null) {
            throw new RuntimeException('Customer not found');
        }

        [$normalizedDateType, $fromDate, $toDate] = $this->resolveDateRange($dateType, $dateFrom, $dateTo);
        $normalizedReportType = strtolower(trim($reportType)) === 'summary' ? 'summary' : 'detailed';

        return $normalizedReportType === 'summary'
            ? $this->buildSummaryReport($customer, $normalizedDateType, $fromDate, $toDate)
            : $this->buildDetailedReport($customer, $normalizedDateType, $fromDate, $toDate);
    }

    private function buildDetailedReport(array $customer, string $dateType, ?string $fromDate, ?string $toDate): array
    {
        $customerId = (string) ($customer['lsessionid'] ?? '');

        $dateWhere = '';
        $params = ['customer_id' => $customerId];
        if ($fromDate !== null && $toDate !== null) {
            $dateWhere = ' AND DATE(l.ldatetime) >= :date_from AND DATE(l.ldatetime) <= :date_to';
            $params['date_from'] = $fromDate;
            $params['date_to'] = $toDate;
        }

        $rowsSql = <<<SQL
SELECT
    l.lid,
    l.lrefno,
    COALESCE(l.lmesssage, '') AS reference,
    COALESCE(l.ldebit, 0) AS amount,
    COALESCE(l.ldatetime, '') AS ldatetime,
    COALESCE(l.lremarks, '') AS remarks,
    COALESCE(
        (
            SELECT t.lterms
            FROM tbltransaction t
            WHERE t.invoice_refno = l.lrefno OR t.ldr_refno = l.lrefno
            ORDER BY t.lid DESC
            LIMIT 1
        ),
        ''
    ) AS lterms
FROM tblledger l
WHERE l.lcustomerid = :customer_id
  AND l.ltype = 'Debit'
  AND COALESCE(l.lref_name, '') <> 'Debit Memo'
{$dateWhere}
ORDER BY l.ldatetime ASC, l.lid ASC
SQL;

        $rowsStmt = $this->db->pdo()->prepare($rowsSql);
        foreach ($params as $key => $value) {
            $rowsStmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $rowsStmt->execute();
        $rawRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $creditSql = <<<SQL
SELECT COALESCE(SUM(l.lcredit), 0)
FROM tblledger l
WHERE l.lcustomerid = :customer_id
  AND l.ltype = 'Credit'
{$dateWhere}
SQL;
        $creditStmt = $this->db->pdo()->prepare($creditSql);
        foreach ($params as $key => $value) {
            $creditStmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $creditStmt->execute();
        $creditSum = (float) ($creditStmt->fetchColumn() ?: 0);

        $freightSql = <<<SQL
SELECT COALESCE(SUM(l.ldebit), 0)
FROM tblledger l
WHERE l.lcustomerid = :customer_id
  AND l.ltype = 'Debit'
  AND l.lref_name = 'Debit Memo'
{$dateWhere}
SQL;
        $freightStmt = $this->db->pdo()->prepare($freightSql);
        foreach ($params as $key => $value) {
            $freightStmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $freightStmt->execute();
        $freightSum = (float) ($freightStmt->fetchColumn() ?: 0);

        // Old-system SOA logic: usable credits are total credits minus freight debits.
        $creditPool = max(0.0, $creditSum - $freightSum);
        $totalAmount = 0.0;
        $totalAmountPaid = 0.0;
        $totalBalance = 0.0;
        $rows = [];

        foreach ($rawRows as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            $amountPaid = min($creditPool, $amount);
            $balance = max(0.0, $amount - $amountPaid);
            $creditPool = max(0.0, $creditPool - $amountPaid);

            $totalAmount += $amount;
            $totalAmountPaid += $amountPaid;
            $totalBalance += $balance;

            $rows[] = [
                'id' => (int) ($row['lid'] ?? 0),
                'terms' => (string) ($row['lterms'] ?? ''),
                'date' => $this->normalizeDate((string) ($row['ldatetime'] ?? '')),
                'datetime' => (string) ($row['ldatetime'] ?? ''),
                'reference' => (string) ($row['reference'] ?? ''),
                'amount' => $amount,
                'amount_paid' => $amountPaid,
                'balance' => $balance,
                'remarks' => (string) ($row['remarks'] ?? ''),
            ];
        }

        return [
            'customer' => $this->mapCustomer($customer),
            'report_type' => 'detailed',
            'date_type' => $dateType,
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'rows' => $rows,
            'totals' => [
                'amount' => $totalAmount,
                'amount_paid' => $totalAmountPaid,
                'balance' => $totalBalance,
                'row_count' => count($rows),
            ],
            'meta' => [
                'credit_pool' => max(0.0, $creditSum - $freightSum),
                'credit_sum' => $creditSum,
                'freight_sum' => $freightSum,
            ],
        ];
    }

    private function buildSummaryReport(array $customer, string $dateType, ?string $fromDate, ?string $toDate): array
    {
        $customerId = (string) ($customer['lsessionid'] ?? '');

        $dateWhere = '';
        $params = ['customer_id' => $customerId];
        if ($fromDate !== null && $toDate !== null) {
            $dateWhere = ' AND DATE(l.ldatetime) >= :date_from AND DATE(l.ldatetime) <= :date_to';
            $params['date_from'] = $fromDate;
            $params['date_to'] = $toDate;
        }

        $summarySql = <<<SQL
SELECT
    YEAR(l.ldatetime) AS year,
    MONTH(l.ldatetime) AS month,
    SUM(COALESCE(l.ldebit, 0)) AS total_debit,
    SUM(COALESCE(l.lcredit, 0)) AS total_credit
FROM tblledger l
WHERE l.lcustomerid = :customer_id
{$dateWhere}
GROUP BY YEAR(l.ldatetime), MONTH(l.ldatetime)
ORDER BY YEAR(l.ldatetime) ASC, MONTH(l.ldatetime) ASC
SQL;

        $summaryStmt = $this->db->pdo()->prepare($summarySql);
        foreach ($params as $key => $value) {
            $summaryStmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $summaryStmt->execute();
        $groups = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

        $runningBalance = 0.0;
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $summaryRows = [];

        foreach ($groups as $group) {
            $year = (int) ($group['year'] ?? 0);
            $month = (int) ($group['month'] ?? 0);
            $debit = (float) ($group['total_debit'] ?? 0);
            $credit = (float) ($group['total_credit'] ?? 0);

            $runningBalance += $debit - $credit;
            $totalDebit += $debit;
            $totalCredit += $credit;

            $summaryRows[] = [
                'year' => $year,
                'month' => $month,
                'month_name' => date('F', mktime(0, 0, 0, max(1, $month), 1)),
                'total_debit' => $debit,
                'total_credit' => $credit,
                'balance' => $runningBalance,
            ];
        }

        return [
            'customer' => $this->mapCustomer($customer),
            'report_type' => 'summary',
            'date_type' => $dateType,
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'summary_rows' => $summaryRows,
            'totals' => [
                'amount' => $totalDebit,
                'amount_paid' => $totalCredit,
                'balance' => $runningBalance,
                'row_count' => count($summaryRows),
            ],
        ];
    }

    private function findCustomerBySession(string $sessionId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM tblpatient WHERE lsessionid = :session_id ORDER BY lid DESC LIMIT 1'
        );
        $stmt->execute(['session_id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function mapCustomer(array $customer): array
    {
        return [
            'session_id' => (string) ($customer['lsessionid'] ?? ''),
            'customer_code' => (string) ($customer['lpatient_code'] ?? ''),
            'company' => (string) ($customer['lcompany'] ?? ''),
            'terms' => (string) ($customer['lterms'] ?? ''),
            'credit_limit' => (float) ($customer['lcredit'] ?? 0),
        ];
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
