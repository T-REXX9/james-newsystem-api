<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class InactiveActiveCustomersReportRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function report(
        int $mainId,
        string $status,
        string $search,
        int $cutoffMonths,
        int $page,
        int $perPage,
        ?int $yearFrom = null,
        ?int $yearTo = null
    ): array {
        $offset = ($page - 1) * $perPage;
        $cutoffDate = date('Y-m-d', strtotime('-' . $cutoffMonths . ' month'));
        $ledgerDateExclusiveEnd = date('Y-m-d', strtotime('+1 day'));
        // This report defines active/inactive strictly from customer ledger purchases.
        $ledgerPurchaseJoin = <<<SQL
LEFT JOIN (
    SELECT l.lmainid, l.lcustomerid, MAX(DATE(l.ldatetime)) AS last_purchase, MAX(l.created_at) AS last_purchase_at
    FROM tblledger l
    WHERE l.lmainid = :ledger_main_id
      AND l.ldatetime < :ledger_date_exclusive_end
      AND LOWER(TRIM(COALESCE(l.ltype, ''))) = 'debit'
      AND LOWER(TRIM(COALESCE(l.lref_name, ''))) IN ('invoice', 'order slip', 'order_slip')
    GROUP BY l.lmainid, l.lcustomerid
) ledger_purchase
    ON ledger_purchase.lmainid = p.lmain_id
    AND ledger_purchase.lcustomerid = p.lsessionid
SQL;

        $params = [
            'main_id' => $mainId,
            'ledger_main_id' => $mainId,
            'ledger_date_exclusive_end' => $ledgerDateExclusiveEnd,
            'cutoff_date' => $cutoffDate,
            'cutoff_date_active' => $cutoffDate,
            'cutoff_date_inactive' => $cutoffDate,
            'cutoff_date_case' => $cutoffDate,
        ];

        $where = [
            '(CAST(COALESCE(p.lmain_id, 0) AS SIGNED) = :main_id)',
            '(COALESCE(p.ldeleted, 0) = 0)',
            '(TRIM(COALESCE(p.lsessionid, \'\')) <> \'\')',
        ];

        if ($status === 'active') {
            $where[] = '(ledger_purchase.last_purchase >= :cutoff_date)';
        } elseif ($status === 'inactive') {
            $where[] = '(ledger_purchase.last_purchase < :cutoff_date OR ledger_purchase.last_purchase IS NULL)';
        }

        if ($yearFrom !== null) {
            $where[] = '(ledger_purchase.last_purchase >= :year_from_date)';
            $params['year_from_date'] = sprintf('%04d-01-01', $yearFrom);
        }
        if ($yearTo !== null) {
            $where[] = '(ledger_purchase.last_purchase < :year_to_date)';
            $params['year_to_date'] = sprintf('%04d-01-01', $yearTo + 1);
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $like = '%' . $trimmedSearch . '%';
            $params['search_company'] = $like;
            $params['search_code'] = $like;
            $params['search_group'] = $like;
            $params['search_fname'] = $like;
            $params['search_lname'] = $like;
            $where[] = '('
                . 'COALESCE(p.lcompany, "") LIKE :search_company '
                . 'OR COALESCE(p.lpatient_code, "") LIKE :search_code '
                . 'OR COALESCE(p.lgroup, "") LIKE :search_group '
                . 'OR COALESCE(acc.lfname, "") LIKE :search_fname '
                . 'OR COALESCE(acc.llname, "") LIKE :search_lname'
                . ')';
        }

        $whereSql = implode(' AND ', $where);

        $countSql = <<<SQL
SELECT
    SUM(CASE WHEN ledger_purchase.last_purchase >= :cutoff_date_active THEN 1 ELSE 0 END) AS active_count,
    SUM(CASE WHEN ledger_purchase.last_purchase < :cutoff_date_inactive OR ledger_purchase.last_purchase IS NULL THEN 1 ELSE 0 END) AS inactive_count,
    COUNT(*) AS total_count
FROM tblpatient p
LEFT JOIN tblaccount acc ON CAST(acc.lid AS CHAR) = CAST(p.lsales_person AS CHAR) AND COALESCE(acc.lstatus, 0) = 1
{$ledgerPurchaseJoin}
WHERE {$whereSql}
SQL;
        $countStmt = $this->db->pdo()->prepare($countSql);
        $this->bind($countStmt, $params);
        $countStmt->execute();
        $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $rowsSql = <<<SQL
SELECT
    COALESCE(p.lsessionid, '') AS id,
    COALESCE(p.lcompany, '') AS customer_name,
    COALESCE(p.lpatient_code, '') AS customer_code,
    COALESCE(p.lgroup, '') AS customer_group,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS sales_person,
    ledger_purchase.last_purchase AS last_purchase,
    ledger_purchase.last_purchase_at AS last_purchase_at,
    CASE
        WHEN ledger_purchase.last_purchase >= :cutoff_date_case THEN 'active'
        ELSE 'inactive'
    END AS customer_status
FROM tblpatient p
LEFT JOIN tblaccount acc ON CAST(acc.lid AS CHAR) = CAST(p.lsales_person AS CHAR) AND COALESCE(acc.lstatus, 0) = 1
{$ledgerPurchaseJoin}
WHERE {$whereSql}
ORDER BY ledger_purchase.last_purchase DESC, p.lid DESC
LIMIT :limit OFFSET :offset
SQL;

        $rowsStmt = $this->db->pdo()->prepare($rowsSql);
        $this->bind($rowsStmt, $params);
        $rowsStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $rowsStmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $rowsStmt->execute();
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => array_map(static fn(array $row): array => [
                'id' => (string) ($row['id'] ?? ''),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'customer_code' => (string) ($row['customer_code'] ?? ''),
                'customer_group' => (string) ($row['customer_group'] ?? ''),
                'sales_person' => trim((string) ($row['sales_person'] ?? '')),
                'last_purchase' => (string) ($row['last_purchase'] ?? ''),
                'last_purchase_at' => (string) ($row['last_purchase_at'] ?? ''),
                'customer_status' => (string) ($row['customer_status'] ?? 'inactive'),
            ], $rows),
            'summary' => [
                'active_count' => (int) ($counts['active_count'] ?? 0),
                'inactive_count' => (int) ($counts['inactive_count'] ?? 0),
                'total_count' => (int) ($counts['total_count'] ?? 0),
                'cutoff_months' => $cutoffMonths,
                'cutoff_date' => $cutoffDate,
            ],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int) ($counts['total_count'] ?? 0),
                'total_pages' => (int) ceil(((int) ($counts['total_count'] ?? 0)) / max(1, $perPage)),
                'status' => $status,
                'search' => $trimmedSearch,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bind(\PDOStatement $stmt, array $params): void
    {
        $sql = $stmt->queryString ?: '';
        foreach ($params as $key => $value) {
            $pattern = '/:' . preg_quote($key, '/') . '(?![A-Za-z0-9_])/';
            if (preg_match($pattern, $sql) !== 1) {
                continue;
            }
            if ($key === 'main_id') {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }
            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
    }
}
