<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class OldNewCustomersReportRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function report(int $mainId, string $status, string $search, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $cutoffDateTime = date('Y-m-d H:i:s', strtotime('-1 year'));
        $cutoffDate = date('Y-m-d', strtotime($cutoffDateTime));

        $params = [
            'main_id' => $mainId,
            'cutoff_old_count' => $cutoffDateTime,
            'cutoff_old_filter' => $cutoffDateTime,
            'cutoff_new_count' => $cutoffDateTime,
            'cutoff_new_filter' => $cutoffDateTime,
            'cutoff_case_type' => $cutoffDateTime,
            'cutoff_case_order' => $cutoffDateTime,
        ];

        $where = [
            '(CAST(COALESCE(p.lmain_id, 0) AS SIGNED) = :main_id)',
            'CAST(COALESCE(p.lstatus, 0) AS SIGNED) = 1',
            'COALESCE(p.lsince, "") <> ""',
        ];

        if ($status === 'old') {
            $where[] = 'p.lsince < :cutoff_old_filter';
        } elseif ($status === 'new') {
            $where[] = 'p.lsince > :cutoff_new_filter';
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $searchValue = '%' . $trimmedSearch . '%';
            $params['search_company'] = $searchValue;
            $params['search_code'] = $searchValue;
            $params['search_group'] = $searchValue;
            $params['search_fname'] = $searchValue;
            $params['search_lname'] = $searchValue;
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
    SUM(CASE WHEN p.lsince < :cutoff_old_count THEN 1 ELSE 0 END) AS old_count,
    SUM(CASE WHEN p.lsince > :cutoff_new_count THEN 1 ELSE 0 END) AS new_count,
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
    DATE(p.lsince) AS customer_since,
    CASE
        WHEN p.lsince < :cutoff_case_type THEN 'old'
        ELSE 'new'
    END AS customer_type
FROM tblpatient p
LEFT JOIN tblaccount acc ON CAST(acc.lid AS CHAR) = CAST(p.lsales_person AS CHAR)
WHERE {$whereSql}
ORDER BY
    CASE WHEN p.lsince < :cutoff_case_order THEN 0 ELSE 1 END ASC,
    p.lcompany ASC,
    p.lid ASC
LIMIT :limit OFFSET :offset
SQL;

        $rowsStmt = $this->db->pdo()->prepare($rowsSql);
        $this->bind($rowsStmt, $params);
        $rowsStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $rowsStmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $rowsStmt->execute();
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $total = (int) ($counts['total_count'] ?? 0);

        return [
            'items' => array_map(static fn(array $row): array => [
                'id' => (string) ($row['id'] ?? ''),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'customer_code' => (string) ($row['customer_code'] ?? ''),
                'customer_group' => (string) ($row['customer_group'] ?? ''),
                'sales_person' => trim((string) ($row['sales_person'] ?? '')),
                'customer_since' => (string) ($row['customer_since'] ?? ''),
                'customer_type' => (string) ($row['customer_type'] ?? 'new'),
            ], $rows),
            'summary' => [
                'old_count' => (int) ($counts['old_count'] ?? 0),
                'new_count' => (int) ($counts['new_count'] ?? 0),
                'total_count' => $total,
                'cutoff_years' => 1,
                'cutoff_date' => $cutoffDate,
            ],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
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
