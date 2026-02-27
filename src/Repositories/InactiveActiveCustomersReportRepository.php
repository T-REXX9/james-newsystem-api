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
        int $perPage
    ): array {
        $offset = ($page - 1) * $perPage;
        $cutoffDate = date('Y-m-d', strtotime('-' . $cutoffMonths . ' month'));

        $params = [
            'main_id' => $mainId,
            'cutoff_date' => $cutoffDate,
            'cutoff_date_active' => $cutoffDate,
            'cutoff_date_inactive' => $cutoffDate,
            'cutoff_date_case' => $cutoffDate,
        ];

        $where = [
            '(CAST(COALESCE(p.lmain_id, 0) AS SIGNED) = :main_id)',
            'COALESCE(p.llast_transaction, "") <> ""',
        ];

        if ($status === 'active') {
            $where[] = 'DATE(p.llast_transaction) >= :cutoff_date';
        } elseif ($status === 'inactive') {
            $where[] = 'DATE(p.llast_transaction) <= :cutoff_date';
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $params['search'] = '%' . $trimmedSearch . '%';
            $where[] = '('
                . 'COALESCE(p.lcompany, "") LIKE :search '
                . 'OR COALESCE(p.lpatient_code, "") LIKE :search '
                . 'OR COALESCE(p.lgroup, "") LIKE :search '
                . 'OR COALESCE(acc.lfname, "") LIKE :search '
                . 'OR COALESCE(acc.llname, "") LIKE :search'
                . ')';
        }

        $whereSql = implode(' AND ', $where);

        $countSql = <<<SQL
SELECT
    SUM(CASE WHEN DATE(p.llast_transaction) >= :cutoff_date_active THEN 1 ELSE 0 END) AS active_count,
    SUM(CASE WHEN DATE(p.llast_transaction) <= :cutoff_date_inactive THEN 1 ELSE 0 END) AS inactive_count,
    COUNT(*) AS total_count
FROM tblpatient p
LEFT JOIN tblaccount acc ON CAST(acc.lid AS CHAR) = CAST(p.lsales_person AS CHAR)
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
    DATE(p.llast_transaction) AS last_purchase,
    CASE
        WHEN DATE(p.llast_transaction) >= :cutoff_date_case THEN 'active'
        ELSE 'inactive'
    END AS customer_status
FROM tblpatient p
LEFT JOIN tblaccount acc ON CAST(acc.lid AS CHAR) = CAST(p.lsales_person AS CHAR)
WHERE {$whereSql}
ORDER BY p.llast_transaction DESC, p.lid DESC
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
