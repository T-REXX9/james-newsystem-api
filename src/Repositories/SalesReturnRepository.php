<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class SalesReturnRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function list(
        int $mainId,
        string $search = '',
        string $status = '',
        string $month = '',
        string $year = '',
        int $page = 1,
        int $perPage = 50
    ): array {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['CAST(COALESCE(cm.lmainid, 0) AS SIGNED) = :main_id'];
        $params = ['main_id' => $mainId];

        $month = trim($month) !== '' ? trim($month) : date('m');
        $year = trim($year) !== '' ? trim($year) : date('Y');
        if (preg_match('/^\d{2}$/', $month) === 1 && preg_match('/^\d{4}$/', $year) === 1) {
            $params['month_filter'] = sprintf('%s-%s', $year, $month);
            $where[] = 'DATE_FORMAT(COALESCE(cm.ldate, cm.ldaterec, cm.ldatetime), "%Y-%m") = :month_filter';
        }

        $search = trim($search);
        if ($search !== '') {
            $params['search'] = '%' . $search . '%';
            $where[] = '(
                COALESCE(cm.lcredit_no, "") LIKE :search OR
                COALESCE(cm.linvoice_no, "") LIKE :search OR
                COALESCE(cm.clname, "") LIKE :search OR
                COALESCE(cm.lsalesman, "") LIKE :search OR
                COALESCE(cm.lremark, "") LIKE :search
            )';
        }

        $status = trim($status);
        if ($status !== '' && strtolower($status) !== 'all') {
            $params['status'] = $status;
            $where[] = 'COALESCE(cm.lstatus, "Pending") = :status';
        }

        $whereSql = implode(' AND ', $where);

        $countSql = <<<SQL
SELECT COUNT(*)
FROM tblcredit_memo cm
WHERE {$whereSql}
SQL;
        $countStmt = $this->db->pdo()->prepare($countSql);
        foreach ($params as $key => $value) {
            if ($key === 'main_id') {
                $countStmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }
            $countStmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = <<<SQL
SELECT
    MAX(cm.lid) AS sort_id,
    COALESCE(cm.lrefno, '') AS lrefno,
    COALESCE(cm.lcredit_no, '') AS lcredit_no,
    COALESCE(cm.linvoice_no, '') AS linvoice_no,
    COALESCE(cm.linvoice_refno, '') AS linvoice_refno,
    COALESCE(cm.ldate, '') AS ldate,
    COALESCE(cm.lstatus, 'Pending') AS lstatus,
    COALESCE(cm.ltype, '') AS ltype,
    COALESCE(cm.lcustomer, '') AS lcustomer,
    TRIM(
        COALESCE(NULLIF(cm.clname, ''), NULLIF(pt.lcompany, ''), 'Unknown Customer')
    ) AS customer_name,
    COALESCE(cm.lsalesman, '') AS sales_person,
    COALESCE(cm.ltrackno, '') AS tracking_no,
    COALESCE(cm.lshipvia, '') AS ship_via,
    COALESCE(cm.lremark, '') AS lremark,
    CAST(COALESCE(SUM(COALESCE(itm.lqty, 0)), 0) AS DECIMAL(15,2)) AS total_qty,
    CAST(COALESCE(SUM(COALESCE(itm.lqty, 0) * COALESCE(itm.lprice, 0)), 0) AS DECIMAL(15,2)) AS total_amount
FROM tblcredit_memo cm
LEFT JOIN tblpatient pt ON CAST(pt.lsessionid AS CHAR) = CAST(cm.lcustomer AS CHAR)
LEFT JOIN tblcredit_return_item itm ON itm.lrefno = cm.lrefno
WHERE {$whereSql}
GROUP BY
    cm.lrefno, cm.lcredit_no, cm.linvoice_no, cm.linvoice_refno, cm.ldate, cm.lstatus,
    cm.ltype, cm.lcustomer, cm.clname, pt.lcompany, cm.lsalesman, cm.ltrackno, cm.lshipvia, cm.lremark
ORDER BY sort_id DESC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === 'main_id') {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }
            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
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
                'total_pages' => max(1, (int) ceil($total / max(1, $perPage))),
                'filters' => [
                    'search' => $search,
                    'status' => $status,
                    'month' => $month,
                    'year' => $year,
                ],
            ],
        ];
    }

    public function show(int $mainId, string $refno): ?array
    {
        $sql = <<<SQL
SELECT
    COALESCE(cm.lrefno, '') AS lrefno,
    COALESCE(cm.lcredit_no, '') AS lcredit_no,
    COALESCE(cm.linvoice_no, '') AS linvoice_no,
    COALESCE(cm.linvoice_refno, '') AS linvoice_refno,
    COALESCE(cm.ldate, '') AS ldate,
    COALESCE(cm.lstatus, 'Pending') AS lstatus,
    COALESCE(cm.ltype, '') AS ltype,
    COALESCE(cm.lcustomer, '') AS lcustomer,
    TRIM(
        COALESCE(NULLIF(cm.clname, ''), NULLIF(pt.lcompany, ''), 'Unknown Customer')
    ) AS customer_name,
    COALESCE(cm.lsalesman, '') AS sales_person,
    COALESCE(cm.ltrackno, '') AS tracking_no,
    COALESCE(cm.lshipvia, '') AS ship_via,
    COALESCE(cm.lremark, '') AS lremark,
    COALESCE(cm.lmy_remarks, '') AS internal_remarks,
    CAST(COALESCE(SUM(COALESCE(itm.lqty, 0)), 0) AS DECIMAL(15,2)) AS total_qty,
    CAST(COALESCE(SUM(COALESCE(itm.lqty, 0) * COALESCE(itm.lprice, 0)), 0) AS DECIMAL(15,2)) AS total_amount
FROM tblcredit_memo cm
LEFT JOIN tblpatient pt ON CAST(pt.lsessionid AS CHAR) = CAST(cm.lcustomer AS CHAR)
LEFT JOIN tblcredit_return_item itm ON itm.lrefno = cm.lrefno
WHERE CAST(COALESCE(cm.lmainid, 0) AS SIGNED) = :main_id
  AND cm.lrefno = :refno
GROUP BY
    cm.lrefno, cm.lcredit_no, cm.linvoice_no, cm.linvoice_refno, cm.ldate, cm.lstatus, cm.ltype,
    cm.lcustomer, cm.clname, pt.lcompany, cm.lsalesman, cm.ltrackno, cm.lshipvia, cm.lremark, cm.lmy_remarks
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function items(int $mainId, string $refno): array
    {
        $sql = <<<SQL
SELECT
    COALESCE(itm.lid, 0) AS id,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.lbrand, '') AS brand,
    COALESCE(itm.llocation, '') AS location,
    COALESCE(itm.ldesc, '') AS description,
    CAST(COALESCE(itm.lqty, 0) AS DECIMAL(15,2)) AS qty,
    CAST(COALESCE(itm.lprice, 0) AS DECIMAL(15,2)) AS unit_price,
    CAST(COALESCE(itm.lqty, 0) * COALESCE(itm.lprice, 0) AS DECIMAL(15,2)) AS amount,
    COALESCE(itm.lremark, '') AS remark
FROM tblcredit_return_item itm
INNER JOIN tblcredit_memo cm ON cm.lrefno = itm.lrefno
WHERE CAST(COALESCE(cm.lmainid, 0) AS SIGNED) = :main_id
  AND itm.lrefno = :refno
ORDER BY itm.lid DESC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
