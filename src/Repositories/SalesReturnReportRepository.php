<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class SalesReturnReportRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function options(int $mainId): array
    {
        $statusStmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT COALESCE(lstatus, "") AS status
             FROM tblcredit_memo
             WHERE lmainid = :main_id
               AND COALESCE(lstatus, "") <> ""
             ORDER BY status ASC'
        );
        $statusStmt->execute(['main_id' => $mainId]);
        $statuses = array_values(array_filter(array_map(
            static fn(array $row): string => trim((string) ($row['status'] ?? '')),
            $statusStmt->fetchAll(PDO::FETCH_ASSOC)
        )));

        return ['statuses' => $statuses];
    }

    /**
     * @param array<string, string> $filters
     */
    public function report(int $mainId, array $filters, int $page, int $perPage): array
    {
        [$whereSql, $params] = $this->buildFilters($mainId, $filters);

        $countSql = <<<SQL
SELECT COUNT(*) AS total_rows
FROM tblcredit_memo tr
INNER JOIN tblcredit_return_item i ON i.lrefno = tr.lrefno
WHERE {$whereSql}
SQL;
        $countStmt = $this->db->pdo()->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $totalRows = (int) ($countStmt->fetchColumn() ?: 0);

        $summarySql = <<<SQL
SELECT
    COALESCE(SUM(COALESCE(i.lqty, 0)), 0) AS total_qty,
    COALESCE(SUM(COALESCE(i.lprice, 0) * COALESCE(i.lqty, 0)), 0) AS total_amount
FROM tblcredit_memo tr
INNER JOIN tblcredit_return_item i ON i.lrefno = tr.lrefno
WHERE {$whereSql}
SQL;
        $summaryStmt = $this->db->pdo()->prepare($summarySql);
        foreach ($params as $key => $value) {
            $summaryStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $offset = max(0, ($page - 1) * $perPage);
        $rowsSql = <<<SQL
SELECT
    COALESCE(tr.lrefno, '') AS id,
    COALESCE(tr.lcredit_no, '') AS return_no,
    DATE(tr.ldate) AS return_date,
    COALESCE(tr.linvoice_no, '') AS transaction_no,
    TRIM(COALESCE(tr.clname, '')) AS customer,
    COALESCE(tr.lstatus, '') AS status,
    COALESCE(i.litemcode, '') AS item_code,
    COALESCE(i.lpartno, '') AS part_no,
    COALESCE(i.lbrand, '') AS brand,
    COALESCE(i.lprice, 0) AS price,
    COALESCE(i.lqty, 0) AS qty,
    COALESCE(i.lprice, 0) * COALESCE(i.lqty, 0) AS total,
    COALESCE(tr.ldatetime, tr.ldate) AS sort_datetime
FROM tblcredit_memo tr
INNER JOIN tblcredit_return_item i ON i.lrefno = tr.lrefno
WHERE {$whereSql}
ORDER BY sort_datetime DESC, tr.lid DESC, i.lid DESC
LIMIT :limit OFFSET :offset
SQL;

        $rowsStmt = $this->db->pdo()->prepare($rowsSql);
        foreach ($params as $key => $value) {
            $rowsStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $rowsStmt->bindValue('limit', max(1, min(200, $perPage)), PDO::PARAM_INT);
        $rowsStmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $rowsStmt->execute();
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => array_map(static fn(array $row): array => [
                'id' => (string) ($row['id'] ?? ''),
                'return_no' => (string) ($row['return_no'] ?? ''),
                'return_date' => (string) ($row['return_date'] ?? ''),
                'transaction_no' => (string) ($row['transaction_no'] ?? ''),
                'customer' => (string) ($row['customer'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'item_code' => (string) ($row['item_code'] ?? ''),
                'part_no' => (string) ($row['part_no'] ?? ''),
                'brand' => (string) ($row['brand'] ?? ''),
                'price' => (float) ($row['price'] ?? 0),
                'qty' => (float) ($row['qty'] ?? 0),
                'total' => (float) ($row['total'] ?? 0),
            ], $rows),
            'summary' => [
                'total_qty' => (float) ($summary['total_qty'] ?? 0),
                'total_amount' => (float) ($summary['total_amount'] ?? 0),
            ],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalRows,
                'total_pages' => $perPage > 0 ? (int) ceil($totalRows / $perPage) : 0,
            ],
        ];
    }

    /**
     * @param array<string, string> $filters
     * @return array{0:string,1:array<string,string>}
     */
    private function buildFilters(int $mainId, array $filters): array
    {
        $where = ['tr.lmainid = :main_id'];
        $params = ['main_id' => (string) $mainId];

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateFrom !== '' && $dateTo !== '') {
            $where[] = 'DATE(tr.ldatetime) >= :date_from AND DATE(tr.ldatetime) <= :date_to';
            $params['date_from'] = $dateFrom;
            $params['date_to'] = $dateTo;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && strtolower($status) !== 'all') {
            $where[] = 'tr.lstatus = :status';
            $params['status'] = $status;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '('
                . 'tr.lcredit_no LIKE :search '
                . 'OR tr.linvoice_no LIKE :search '
                . 'OR tr.clname LIKE :search '
                . 'OR i.litemcode LIKE :search '
                . 'OR i.lpartno LIKE :search '
                . 'OR i.lbrand LIKE :search'
                . ')';
            $params['search'] = '%' . $search . '%';
        }

        return [implode(' AND ', $where), $params];
    }
}
