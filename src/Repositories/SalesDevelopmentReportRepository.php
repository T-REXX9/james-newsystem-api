<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class SalesDevelopmentReportRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function report(
        int $mainId,
        string $dateFrom,
        string $dateTo,
        string $category,
        int $page,
        int $perPage
    ): array {
        $offset = ($page - 1) * $perPage;

        [$whereSql, $params] = $this->buildFilters($mainId, $dateFrom, $dateTo, $category);

        $countSql = <<<SQL
SELECT COUNT(*)
FROM tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
WHERE {$whereSql}
SQL;

        $countStmt = $this->db->pdo()->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = <<<SQL
SELECT
    CAST(i.lid AS CHAR) AS id,
    COALESCE(tr.lrefno, '') AS inquiry_id,
    COALESCE(tr.linqno, '') AS inquiry_no,
    COALESCE(tr.ldate, '') AS sales_date,
    COALESCE(tr.lcustomerid, '') AS customer_id,
    TRIM(COALESCE(tr.lcompany, '')) AS customer_company,
    COALESCE(
        NULLIF(TRIM(COALESCE(tr.lsalesperson, '')), ''),
        TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, '')))
    ) AS sales_person,
    COALESCE(i.lpartno, '') AS part_no,
    COALESCE(i.litem_code, '') AS item_code,
    COALESCE(i.ldesc, '') AS description,
    CAST(COALESCE(i.lqty, 0) AS DECIMAL(15,2)) AS qty,
    CAST(COALESCE(i.lprice, 0) AS DECIMAL(15,2)) AS unit_price,
    CAST(COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0) AS DECIMAL(15,2)) AS amount,
    COALESCE(i.lremark, '') AS remark
FROM tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
LEFT JOIN tblaccount acc ON acc.lid = tr.luser
WHERE {$whereSql}
ORDER BY tr.ldate DESC, i.lid DESC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'category' => $category,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(
        int $mainId,
        string $dateFrom,
        string $dateTo,
        string $category,
        int $page,
        int $perPage
    ): array {
        $offset = ($page - 1) * $perPage;

        [$whereSql, $params] = $this->buildFilters($mainId, $dateFrom, $dateTo, $category);

        $countSql = <<<SQL
SELECT COUNT(*)
FROM (
    SELECT i.lpartno, i.litem_code, i.ldesc
    FROM tblinquiry_item i
    INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
    WHERE {$whereSql}
    GROUP BY i.lpartno, i.litem_code, i.ldesc
) x
SQL;

        $countStmt = $this->db->pdo()->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = <<<SQL
SELECT
    COALESCE(i.lpartno, '') AS part_no,
    COALESCE(i.litem_code, '') AS item_code,
    COALESCE(i.ldesc, '') AS description,
    CAST(COALESCE(SUM(COALESCE(i.lqty, 0)), 0) AS DECIMAL(15,2)) AS total_quantity,
    COUNT(*) AS inquiry_count,
    COUNT(DISTINCT COALESCE(tr.lcustomerid, '')) AS customer_count,
    CAST(COALESCE(AVG(COALESCE(i.lprice, 0)), 0) AS DECIMAL(15,2)) AS average_price
FROM tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
WHERE {$whereSql}
GROUP BY i.lpartno, i.litem_code, i.ldesc
ORDER BY total_quantity DESC, inquiry_count DESC, part_no ASC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'category' => $category,
            ],
        ];
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function buildFilters(int $mainId, string $dateFrom, string $dateTo, string $category): array
    {
        $where = [
            'tr.lmain_id = :main_id',
            'tr.ldate >= :date_from',
            'tr.ldate <= :date_to',
        ];

        $params = [
            'main_id' => (string) $mainId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];

        if ($category === 'no_stock') {
            $where[] = 'COALESCE(i.lremark, "") = "OutStock"';
        } else {
            $where[] = 'COALESCE(i.lremark, "") = "OnStock"';
            $where[] = '(i.lsorefno IS NULL OR TRIM(COALESCE(i.lsorefno, "")) = "")';
        }

        return [implode(' AND ', $where), $params];
    }
}
