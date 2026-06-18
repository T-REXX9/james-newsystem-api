<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class IncidentItemsReportRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, string|int> $filters
     */
    public function report(int $mainId, array $filters, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        [$whereSql, $params] = $this->buildWhere($mainId, $filters);
        $minCount = max(1, (int) ($filters['min_count'] ?? 1));

        $groupSql = $this->groupedSql($whereSql);
        $countSql = "SELECT COUNT(*) AS total FROM ({$groupSql} HAVING incident_count >= :min_count) grouped_count";
        $countStmt = $this->db->pdo()->prepare($countSql);
        $this->bind($countStmt, $params);
        $countStmt->bindValue('min_count', $minCount, PDO::PARAM_INT);
        $countStmt->execute();
        $total = (int) (($countStmt->fetch(PDO::FETCH_ASSOC) ?: [])['total'] ?? 0);

        $rowsSql = <<<SQL
SELECT *
FROM ({$groupSql} HAVING incident_count >= :min_count) grouped_rows
ORDER BY incident_count DESC, latest_incident_date DESC, supplier_name ASC, part_no ASC
LIMIT :limit OFFSET :offset
SQL;
        $rowsStmt = $this->db->pdo()->prepare($rowsSql);
        $this->bind($rowsStmt, $params);
        $rowsStmt->bindValue('min_count', $minCount, PDO::PARAM_INT);
        $rowsStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $rowsStmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $rowsStmt->execute();
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => array_map(fn(array $row): array => $this->mapRow($row), $rows),
            'summary' => $this->summary($whereSql, $params, $rows),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
                'search' => (string) ($filters['search'] ?? ''),
                'supplier' => (string) ($filters['supplier'] ?? ''),
                'match_source' => (string) ($filters['match_source'] ?? 'all'),
                'min_count' => $minCount,
            ],
        ];
    }

    /**
     * @param array<string, string|int> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildWhere(int $mainId, array $filters): array
    {
        $where = ['main_id = :main_id'];
        $params = ['main_id' => $mainId];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $params['search'] = '%' . $search . '%';
            $where[] = '('
                . 'COALESCE(supplier_name, "") LIKE :search '
                . 'OR COALESCE(item_code, "") LIKE :search '
                . 'OR COALESCE(part_no, "") LIKE :search '
                . 'OR COALESCE(description, "") LIKE :search '
                . 'OR COALESCE(issue_summary, "") LIKE :search'
                . ')';
        }

        $supplier = trim((string) ($filters['supplier'] ?? ''));
        if ($supplier !== '') {
            $params['supplier'] = '%' . $supplier . '%';
            $where[] = '(COALESCE(supplier_id, "") LIKE :supplier OR COALESCE(supplier_name, "") LIKE :supplier)';
        }

        $matchSource = trim((string) ($filters['match_source'] ?? 'all'));
        if ($matchSource !== '' && $matchSource !== 'all') {
            $params['match_source'] = $matchSource;
            $where[] = 'match_source = :match_source';
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $params['date_from'] = $dateFrom;
            $where[] = 'DATE(created_at) >= :date_from';
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $params['date_to'] = $dateTo;
            $where[] = 'DATE(created_at) <= :date_to';
        }

        return [implode(' AND ', $where), $params];
    }

    private function groupedSql(string $whereSql): string
    {
        return <<<SQL
SELECT
    COALESCE(NULLIF(supplier_id, ''), 'unassigned') AS supplier_id,
    COALESCE(NULLIF(supplier_name, ''), 'Unassigned Supplier') AS supplier_name,
    COALESCE(product_id, '') AS product_id,
    COALESCE(item_code, '') AS item_code,
    COALESCE(part_no, '') AS part_no,
    COALESCE(description, '') AS description,
    COUNT(*) AS incident_count,
    MAX(created_at) AS latest_incident_date,
    ROUND(AVG(COALESCE(confidence_score, 0)), 4) AS average_confidence,
    GROUP_CONCAT(DISTINCT match_source ORDER BY match_source SEPARATOR ', ') AS match_sources,
    GROUP_CONCAT(
        CONCAT(
            incident_report_id,
            '|',
            DATE_FORMAT(created_at, '%Y-%m-%d'),
            '|',
            REPLACE(REPLACE(LEFT(COALESCE(issue_summary, ''), 160), '\n', ' '), '|', '/')
        )
        ORDER BY created_at DESC
        SEPARATOR ';;'
    ) AS recent_incidents
FROM incident_report_items
WHERE {$whereSql}
GROUP BY
    COALESCE(NULLIF(supplier_id, ''), 'unassigned'),
    COALESCE(NULLIF(supplier_name, ''), 'Unassigned Supplier'),
    COALESCE(product_id, ''),
    COALESCE(item_code, ''),
    COALESCE(part_no, ''),
    COALESCE(description, '')
SQL;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<int, array<string, mixed>> $pagedRows
     */
    private function summary(string $whereSql, array $params, array $pagedRows): array
    {
        $sql = <<<SQL
SELECT
    COUNT(*) AS total_incident_items,
    COUNT(DISTINCT COALESCE(NULLIF(supplier_id, ''), 'unassigned')) AS affected_suppliers,
    COUNT(DISTINCT CONCAT(COALESCE(product_id, ''), '|', COALESCE(item_code, ''), '|', COALESCE(part_no, ''))) AS affected_items
FROM incident_report_items
WHERE {$whereSql}
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bind($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $top = $pagedRows[0] ?? [];

        return [
            'total_incident_items' => (int) ($row['total_incident_items'] ?? 0),
            'affected_suppliers' => (int) ($row['affected_suppliers'] ?? 0),
            'affected_items' => (int) ($row['affected_items'] ?? 0),
            'top_supplier_name' => (string) ($top['supplier_name'] ?? ''),
            'top_item_description' => (string) ($top['description'] ?? ''),
            'top_incident_count' => (int) ($top['incident_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): array
    {
        $recent = [];
        foreach (explode(';;', (string) ($row['recent_incidents'] ?? '')) as $entry) {
            if ($entry === '') {
                continue;
            }
            [$id, $date, $summary] = array_pad(explode('|', $entry, 3), 3, '');
            $recent[] = [
                'incident_report_id' => $id,
                'date' => $date,
                'summary' => $summary,
            ];
        }

        return [
            'supplier_id' => (string) ($row['supplier_id'] ?? ''),
            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
            'product_id' => (string) ($row['product_id'] ?? ''),
            'item_code' => (string) ($row['item_code'] ?? ''),
            'part_no' => (string) ($row['part_no'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'incident_count' => (int) ($row['incident_count'] ?? 0),
            'latest_incident_date' => (string) ($row['latest_incident_date'] ?? ''),
            'average_confidence' => (float) ($row['average_confidence'] ?? 0),
            'match_sources' => (string) ($row['match_sources'] ?? ''),
            'recent_incidents' => array_slice($recent, 0, 5),
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
            $type = $key === 'main_id' ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $type === PDO::PARAM_INT ? (int) $value : (string) $value, $type);
        }
    }
}
