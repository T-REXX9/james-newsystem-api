<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class StockMovementRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{
     *   item: array<string, mixed>,
     *   logs: array<int, array<string, mixed>>,
     *   meta: array<string, mixed>
     * }
     */
    public function listLogs(
        int $mainId,
        string $itemId,
        string $warehouse = '',
        string $transactionType = '',
        string $dateFrom = '',
        string $dateTo = '',
        string $search = '',
        int $page = 1,
        int $perPage = 200
    ): array {
        $item = $this->getItem($mainId, $itemId);
        if ($item === null) {
            return [
                'item' => [],
                'logs' => [],
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'total_pages' => 0,
                ],
            ];
        }

        $perPage = min(1000, max(1, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $baseParams = [
            'main_id' => $mainId,
            'item_id' => $itemId,
        ];
        $where = [
            'itm.lmain_id = :main_id',
            'inv.linvent_id = :item_id',
        ];

        if ($warehouse !== '' && strtolower($warehouse) !== 'all') {
            $where[] = 'inv.lwarehouse = :warehouse';
            $baseParams['warehouse'] = $warehouse;
        }
        if ($transactionType !== '' && strtolower($transactionType) !== 'all') {
            $where[] = 'inv.ltransaction_type = :transaction_type';
            $baseParams['transaction_type'] = $transactionType;
        }
        if ($dateFrom !== '') {
            $where[] = 'inv.ldateadded >= :date_from';
            $baseParams['date_from'] = $this->normalizeDateStart($dateFrom);
        }
        if ($dateTo !== '') {
            $where[] = 'inv.ldateadded <= :date_to';
            $baseParams['date_to'] = $this->normalizeDateEnd($dateTo);
        }
        if ($search !== '') {
            $where[] = "CONCAT_WS(' ', inv.lrefno, inv.lnote, COALESCE(p.lcompany,''), COALESCE(s.lname,''), COALESCE(dr.lcustomer_name,''), COALESCE(il.lcustomer_name,'')) LIKE :search";
            $baseParams['search'] = '%' . $search . '%';
        }

        $whereSql = implode(' AND ', $where);

        $countSql = <<<SQL
SELECT COUNT(*) AS total
FROM tblinventory_logs inv
INNER JOIN tblinventory_item itm ON itm.lsession = inv.linvent_id
LEFT JOIN tblpatient p ON p.lsessionid = inv.lcustomer_id
LEFT JOIN tblsupplier s ON s.lrefno = inv.lsupplier_id
LEFT JOIN tbldelivery_receipt dr ON dr.lrefno = inv.lrefno
LEFT JOIN tblinvoice_list il ON il.lrefno = inv.lrefno
WHERE {$whereSql}
SQL;
        $countStmt = $this->db->pdo()->prepare($countSql);
        foreach ($baseParams as $key => $value) {
            $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = <<<SQL
SELECT
    inv.lid AS id,
    CAST(inv.linvent_id AS CHAR) AS item_id,
    inv.ldateadded AS date,
    COALESCE(NULLIF(inv.ltransaction_type, ''), 'Unknown') AS transaction_type,
    COALESCE(inv.lrefno, '') AS reference_no,
    COALESCE(
        NULLIF(
            CASE
                WHEN inv.ltransaction_type = 'Invoice' THEN il.lcustomer_name
                WHEN inv.ltransaction_type = 'Order Slip' THEN dr.lcustomer_name
                WHEN inv.ltransaction_type = 'Credit Memo' THEN cm.clname
                ELSE ''
            END,
            ''
        ),
        NULLIF(p.lcompany, ''),
        NULLIF(s.lname, ''),
        NULLIF(inv.lnote, ''),
        '—'
    ) AS partner,
    COALESCE(NULLIF(inv.lwarehouse, ''), 'WH1') AS warehouse_id,
    CAST(COALESCE(inv.lin, 0) AS SIGNED) AS qty_in,
    CAST(COALESCE(inv.lout, 0) AS SIGNED) AS qty_out,
    CASE
        WHEN inv.lstatus_logs = '+' THEN '+'
        WHEN inv.lstatus_logs = '-' THEN '-'
        WHEN COALESCE(inv.lin, 0) >= COALESCE(inv.lout, 0) THEN '+'
        ELSE '-'
    END AS status_indicator,
    CAST(COALESCE(inv.lprice, 0) AS DECIMAL(15,2)) AS unit_price,
    COALESCE(inv.lprocess_by, '') AS processed_by,
    COALESCE(inv.lnote, '') AS notes,
    CAST(COALESCE(SUM(COALESCE(inv.lin, 0) - COALESCE(inv.lout, 0))
      OVER (ORDER BY inv.ldateadded ASC, inv.lid ASC), 0) AS SIGNED) AS balance,
    COALESCE(po.lpurchaseno, '') AS rr_no,
    COALESCE(tr.ltransfer_no, '') AS tr_no,
    COALESCE(dr.linvoice_no, '') AS or_no,
    COALESCE(il.linvoice_no, '') AS inv_no,
    COALESCE(cm.lcredit_no, '') AS cm_no,
    COALESCE(sa.ladjustment_number, '') AS adj_no
FROM tblinventory_logs inv
INNER JOIN tblinventory_item itm ON itm.lsession = inv.linvent_id
LEFT JOIN tblpatient p ON p.lsessionid = inv.lcustomer_id
LEFT JOIN tblsupplier s ON s.lrefno = inv.lsupplier_id
LEFT JOIN tblpurchase_order po ON po.lrefno = inv.lrefno
LEFT JOIN tblbranchinventory_transferlist tr ON tr.lrefno = inv.lrefno
LEFT JOIN tbldelivery_receipt dr ON dr.lrefno = inv.lrefno
LEFT JOIN tblinvoice_list il ON il.lrefno = inv.lrefno
LEFT JOIN tblcredit_memo cm ON cm.lrefno = inv.lrefno
LEFT JOIN tblstock_adjustment sa ON sa.lrefno = inv.lrefno
WHERE {$whereSql}
ORDER BY inv.ldateadded ASC, inv.lid ASC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($baseParams as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'item' => $item,
            'logs' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
            ],
        ];
    }

    public function getLog(int $mainId, int $logId): ?array
    {
        $sql = <<<SQL
SELECT
    inv.lid AS id,
    CAST(inv.linvent_id AS CHAR) AS item_id,
    inv.ldateadded AS date,
    COALESCE(NULLIF(inv.ltransaction_type, ''), 'Unknown') AS transaction_type,
    COALESCE(inv.lrefno, '') AS reference_no,
    COALESCE(inv.lwarehouse, '') AS warehouse_id,
    CAST(COALESCE(inv.lin, 0) AS SIGNED) AS qty_in,
    CAST(COALESCE(inv.lout, 0) AS SIGNED) AS qty_out,
    CASE
        WHEN inv.lstatus_logs = '+' THEN '+'
        WHEN inv.lstatus_logs = '-' THEN '-'
        WHEN COALESCE(inv.lin, 0) >= COALESCE(inv.lout, 0) THEN '+'
        ELSE '-'
    END AS status_indicator,
    CAST(COALESCE(inv.lprice, 0) AS DECIMAL(15,2)) AS unit_price,
    COALESCE(inv.lprocess_by, '') AS processed_by,
    COALESCE(inv.lnote, '') AS notes
FROM tblinventory_logs inv
INNER JOIN tblinventory_item itm ON itm.lsession = inv.linvent_id
WHERE itm.lmain_id = :main_id
  AND inv.lid = :log_id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('log_id', $logId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function createLog(int $mainId, string $userId, array $data): array
    {
        $itemId = trim((string) ($data['item_id'] ?? ''));
        $item = $this->getItem($mainId, $itemId);
        if ($item === null) {
            throw new \RuntimeException('Item not found for main_id');
        }

        $status = (string) ($data['status_indicator'] ?? '+');
        $qtyIn = (int) ($data['qty_in'] ?? 0);
        $qtyOut = (int) ($data['qty_out'] ?? 0);
        if ($status === '+' && $qtyIn <= 0) {
            $qtyIn = max(1, (int) ($data['qty'] ?? 1));
            $qtyOut = 0;
        }
        if ($status === '-' && $qtyOut <= 0) {
            $qtyOut = max(1, (int) ($data['qty'] ?? 1));
            $qtyIn = 0;
        }

        $date = $this->normalizeDateTime((string) ($data['date'] ?? 'now'));
        $warehouse = trim((string) ($data['warehouse_id'] ?? 'WH1'));
        $sql = <<<SQL
INSERT INTO tblinventory_logs (
    linvent_id, lin, lout, ltotal, ldateadded, lprocess_by, lstatus_logs, lnote,
    linventory_id, ltransaction_item_id, lprice, lrefno, llocation, lcustomer_id,
    lsupplier_id, lwarehouse, ltransaction_type, litemcode, lpartno
) VALUES (
    :item_id, :lin, :lout, :ltotal, :ldateadded, :process_by, :status_logs, :note,
    :inventory_id, :transaction_item_id, :price, :refno, :location, :customer_id,
    :supplier_id, :warehouse, :transaction_type, :item_code, :part_no
)
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('item_id', $itemId, PDO::PARAM_STR);
        $stmt->bindValue('lin', $qtyIn, PDO::PARAM_INT);
        $stmt->bindValue('lout', $qtyOut, PDO::PARAM_INT);
        $stmt->bindValue('ltotal', $qtyIn - $qtyOut, PDO::PARAM_INT);
        $stmt->bindValue('ldateadded', $date, PDO::PARAM_STR);
        $stmt->bindValue('process_by', $userId, PDO::PARAM_STR);
        $stmt->bindValue('status_logs', $status, PDO::PARAM_STR);
        $stmt->bindValue('note', (string) ($data['notes'] ?? $data['partner'] ?? ''), PDO::PARAM_STR);
        $stmt->bindValue('inventory_id', $itemId, PDO::PARAM_STR);
        $stmt->bindValue('transaction_item_id', (string) ($data['transaction_type'] ?? 'Manual'), PDO::PARAM_STR);
        $stmt->bindValue('price', (float) ($data['unit_price'] ?? 0.0));
        $stmt->bindValue('refno', (string) ($data['reference_no'] ?? ''), PDO::PARAM_STR);
        $stmt->bindValue('location', $warehouse, PDO::PARAM_STR);
        $stmt->bindValue('customer_id', (string) ($data['customer_id'] ?? ''), PDO::PARAM_STR);
        $stmt->bindValue('supplier_id', (string) ($data['supplier_id'] ?? ''), PDO::PARAM_STR);
        $stmt->bindValue('warehouse', $warehouse, PDO::PARAM_STR);
        $stmt->bindValue('transaction_type', (string) ($data['transaction_type'] ?? 'Manual'), PDO::PARAM_STR);
        $stmt->bindValue('item_code', (string) ($item['item_code'] ?? ''), PDO::PARAM_STR);
        $stmt->bindValue('part_no', (string) ($item['part_no'] ?? ''), PDO::PARAM_STR);
        $stmt->execute();

        $id = (int) $this->db->pdo()->lastInsertId();
        $created = $this->getLog($mainId, $id);
        return $created ?? [];
    }

    public function updateLog(int $mainId, int $logId, array $data): ?array
    {
        $existing = $this->getLog($mainId, $logId);
        if ($existing === null) {
            return null;
        }

        $itemId = trim((string) ($data['item_id'] ?? $existing['item_id']));
        $item = $this->getItem($mainId, $itemId);
        if ($item === null) {
            throw new \RuntimeException('Item not found for main_id');
        }

        $status = (string) ($data['status_indicator'] ?? $existing['status_indicator']);
        $qtyIn = (int) ($data['qty_in'] ?? $existing['qty_in']);
        $qtyOut = (int) ($data['qty_out'] ?? $existing['qty_out']);
        if ($status === '+') {
            $qtyOut = 0;
        } else {
            $qtyIn = 0;
        }

        $date = $this->normalizeDateTime((string) ($data['date'] ?? $existing['date']));
        $warehouse = trim((string) ($data['warehouse_id'] ?? $existing['warehouse_id'] ?? 'WH1'));

        $sql = <<<SQL
UPDATE tblinventory_logs
SET
    linvent_id = :item_id,
    lin = :lin,
    lout = :lout,
    ltotal = :ltotal,
    ldateadded = :ldateadded,
    lprocess_by = :process_by,
    lstatus_logs = :status_logs,
    lnote = :note,
    linventory_id = :inventory_id,
    ltransaction_item_id = :transaction_item_id,
    lprice = :price,
    lrefno = :refno,
    llocation = :location,
    lcustomer_id = :customer_id,
    lsupplier_id = :supplier_id,
    lwarehouse = :warehouse,
    ltransaction_type = :transaction_type,
    litemcode = :item_code,
    lpartno = :part_no
WHERE lid = :log_id
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('item_id', $itemId, PDO::PARAM_STR);
        $stmt->bindValue('lin', $qtyIn, PDO::PARAM_INT);
        $stmt->bindValue('lout', $qtyOut, PDO::PARAM_INT);
        $stmt->bindValue('ltotal', $qtyIn - $qtyOut, PDO::PARAM_INT);
        $stmt->bindValue('ldateadded', $date, PDO::PARAM_STR);
        $stmt->bindValue('process_by', (string) ($data['processed_by'] ?? $existing['processed_by'] ?? ''), PDO::PARAM_STR);
        $stmt->bindValue('status_logs', $status, PDO::PARAM_STR);
        $stmt->bindValue('note', (string) ($data['notes'] ?? $existing['notes'] ?? ''), PDO::PARAM_STR);
        $stmt->bindValue('inventory_id', $itemId, PDO::PARAM_STR);
        $stmt->bindValue('transaction_item_id', (string) ($data['transaction_type'] ?? $existing['transaction_type'] ?? 'Manual'), PDO::PARAM_STR);
        $stmt->bindValue('price', (float) ($data['unit_price'] ?? $existing['unit_price'] ?? 0));
        $stmt->bindValue('refno', (string) ($data['reference_no'] ?? $existing['reference_no'] ?? ''), PDO::PARAM_STR);
        $stmt->bindValue('location', $warehouse, PDO::PARAM_STR);
        $stmt->bindValue('customer_id', (string) ($data['customer_id'] ?? ''), PDO::PARAM_STR);
        $stmt->bindValue('supplier_id', (string) ($data['supplier_id'] ?? ''), PDO::PARAM_STR);
        $stmt->bindValue('warehouse', $warehouse, PDO::PARAM_STR);
        $stmt->bindValue('transaction_type', (string) ($data['transaction_type'] ?? $existing['transaction_type'] ?? 'Manual'), PDO::PARAM_STR);
        $stmt->bindValue('item_code', (string) ($item['item_code'] ?? ''), PDO::PARAM_STR);
        $stmt->bindValue('part_no', (string) ($item['part_no'] ?? ''), PDO::PARAM_STR);
        $stmt->bindValue('log_id', $logId, PDO::PARAM_INT);
        $stmt->execute();

        return $this->getLog($mainId, $logId);
    }

    public function deleteLog(int $mainId, int $logId): bool
    {
        $sql = <<<SQL
DELETE inv
FROM tblinventory_logs inv
INNER JOIN tblinventory_item itm ON itm.lsession = inv.linvent_id
WHERE inv.lid = :log_id
  AND itm.lmain_id = :main_id
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('log_id', $logId, PDO::PARAM_INT);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    private function getItem(int $mainId, string $itemId): ?array
    {
        $sql = <<<SQL
SELECT
    CAST(itm.lsession AS CHAR) AS id,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.ldescription, '') AS description,
    COALESCE(brnd.lname, itm.lbrand, '') AS brand
FROM tblinventory_item itm
LEFT JOIN tblbrand brnd ON brnd.lid = itm.lbrand
WHERE itm.lmain_id = :main_id
  AND itm.lsession = :item_id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('item_id', $itemId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function normalizeDateStart(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date . ' 00:00:00';
        }
        return $date;
    }

    private function normalizeDateEnd(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date . ' 23:59:59';
        }
        return $date;
    }

    private function normalizeDateTime(string $value): string
    {
        if (strtolower($value) === 'now' || trim($value) === '') {
            return date('Y-m-d H:i:s');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value . ' 00:00:00';
        }
        return $value;
    }
}

