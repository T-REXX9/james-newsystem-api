<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class ReceivingStockRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{
     *   items: array<int, array<string, mixed>>,
     *   meta: array<string, mixed>
     * }
     */
    public function listReceivingStocks(
        int $mainId,
        int $month,
        int $year,
        string $status = 'all',
        string $search = '',
        int $page = 1,
        int $perPage = 100
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $params = [
            'main_id' => (string) $mainId,
            'month' => $month,
            'year' => $year,
            'limit' => $perPage,
            'offset' => $offset,
        ];
        $where = [
            'rr.lmain_id = :main_id',
            'MONTH(rr.ldate) = :month',
            'YEAR(rr.ldate) = :year',
        ];

        $normalizedStatus = strtolower(trim($status));
        if ($normalizedStatus !== '' && $normalizedStatus !== 'all') {
            $params['status'] = $normalizedStatus;
            $where[] = 'LOWER(COALESCE(rr.ltransaction_status, "")) = :status';
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $params['search_rr'] = '%' . $trimmedSearch . '%';
            $params['search_supplier_name'] = '%' . $trimmedSearch . '%';
            $params['search_supplier_code'] = '%' . $trimmedSearch . '%';
            $params['search_reference'] = '%' . $trimmedSearch . '%';
            $params['search_item'] = '%' . $trimmedSearch . '%';
            $where[] = <<<SQL
(
    rr.lpurchaseno LIKE :search_rr
    OR COALESCE(rr.lsupplier_name, '') LIKE :search_supplier_name
    OR COALESCE(rr.lsupplier_code, '') LIKE :search_supplier_code
    OR COALESCE(rr.lreference, '') LIKE :search_reference
    OR EXISTS (
        SELECT 1
        FROM tblpurchase_item itms
        WHERE itms.lrefno = rr.lrefno
          AND CONCAT_WS(' ', COALESCE(itms.lpartno, ''), COALESCE(itms.litem_code, ''), COALESCE(itms.ldesc, '')) LIKE :search_item
    )
)
SQL;
        }

        $whereSql = implode(' AND ', $where);

        $countSql = <<<SQL
SELECT COUNT(*) AS total
FROM tblpurchase_order rr
WHERE {$whereSql}
SQL;
        $countStmt = $this->db->pdo()->prepare($countSql);
        $this->bindParams($countStmt, $params, false);
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = <<<SQL
SELECT
    rr.lid AS id,
    COALESCE(rr.lrefno, '') AS refno,
    COALESCE(rr.lpurchaseno, '') AS rr_number,
    rr.ldate AS receive_date,
    rr.leta_date AS eta_date,
    COALESCE(rr.ltransaction_status, 'Pending') AS status,
    COALESCE(rr.lsupplier, '') AS supplier_id,
    COALESCE(rr.lsupplier_name, '') AS supplier_name,
    COALESCE(rr.lsupplier_code, '') AS supplier_code,
    COALESCE(rr.lpo_refno, '') AS po_refno,
    COALESCE(rr.lpo_number, '') AS po_number,
    COALESCE(rr.lreference, '') AS reference,
    COALESCE(rr.lterms, '') AS terms,
    COALESCE(rr.laddress, '') AS address,
    COALESCE(rr.lshipped_name, '') AS shipped_name,
    rr.ldate_recieved AS posted_date,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by,
    CAST(COALESCE(agg.item_count, 0) AS SIGNED) AS item_count,
    CAST(COALESCE(agg.total_qty, 0) AS SIGNED) AS total_qty,
    CAST(COALESCE(agg.total_cost, 0) AS DECIMAL(15,2)) AS total_cost
FROM tblpurchase_order rr
LEFT JOIN tblaccount acc
    ON acc.lid = rr.luser
LEFT JOIN (
    SELECT
        lrefno,
        COUNT(*) AS item_count,
        SUM(COALESCE(lqty, 0)) AS total_qty,
        SUM(COALESCE(lqty, 0) * CAST(COALESCE(NULLIF(lsup_price, ''), '0') AS DECIMAL(15,2))) AS total_cost
    FROM tblpurchase_item
    GROUP BY lrefno
) agg
    ON agg.lrefno = rr.lrefno
WHERE {$whereSql}
ORDER BY rr.lid DESC
LIMIT :limit OFFSET :offset
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $params, true);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
                'filters' => [
                    'month' => $month,
                    'year' => $year,
                    'status' => $normalizedStatus === '' ? 'all' : $normalizedStatus,
                    'search' => $trimmedSearch,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getReceivingStock(int $mainId, string $receivingRefno): ?array
    {
        $headerSql = <<<SQL
SELECT
    rr.lid AS id,
    COALESCE(rr.lrefno, '') AS refno,
    COALESCE(rr.lpurchaseno, '') AS rr_number,
    rr.ldate AS receive_date,
    rr.leta_date AS eta_date,
    rr.ltime AS receive_time,
    COALESCE(rr.ltransaction_status, 'Pending') AS status,
    COALESCE(rr.lsupplier, '') AS supplier_id,
    COALESCE(rr.lsupplier_name, '') AS supplier_name,
    COALESCE(rr.lsupplier_code, '') AS supplier_code,
    COALESCE(rr.lpo_refno, '') AS po_refno,
    COALESCE(rr.lpo_number, '') AS po_number,
    COALESCE(rr.lreference, '') AS reference,
    COALESCE(rr.lterms, '') AS terms,
    COALESCE(rr.laddress, '') AS address,
    COALESCE(rr.lshipped_name, '') AS shipped_name,
    rr.ldate_recieved AS posted_date,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by
FROM tblpurchase_order rr
LEFT JOIN tblaccount acc
    ON acc.lid = rr.luser
WHERE rr.lmain_id = :main_id
  AND rr.lrefno = :refno
LIMIT 1
SQL;
        $headerStmt = $this->db->pdo()->prepare($headerSql);
        $headerStmt->bindValue('main_id', (string) $mainId, PDO::PARAM_STR);
        $headerStmt->bindValue('refno', $receivingRefno, PDO::PARAM_STR);
        $headerStmt->execute();
        $header = $headerStmt->fetch(PDO::FETCH_ASSOC);
        if ($header === false) {
            return null;
        }

        $itemsSql = <<<SQL
SELECT
    itm.lid AS id,
    COALESCE(itm.lrefno, '') AS receiving_refno,
    CAST(COALESCE(itm.litemid, 0) AS SIGNED) AS product_id,
    COALESCE(itm.litem_refno, '') AS product_session,
    COALESCE(itm.litem_code, '') AS item_code,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.lopn_number, '') AS original_part_no,
    COALESCE(itm.ldesc, '') AS description,
    COALESCE(itm.lbrand, inv.lbrand, '') AS brand,
    CAST(COALESCE(itm.lqty, 0) AS SIGNED) AS qty,
    COALESCE(itm.lunit, '') AS unit,
    CAST(COALESCE(NULLIF(itm.lunit_qty, ''), '0') AS DECIMAL(15,2)) AS unit_qty,
    CAST(COALESCE(NULLIF(itm.lsup_price, ''), '0') AS DECIMAL(15,2)) AS unit_cost,
    CAST(COALESCE(itm.lqty, 0) * CAST(COALESCE(NULLIF(itm.lsup_price, ''), '0') AS DECIMAL(15,2)) AS DECIMAL(15,2)) AS line_total,
    COALESCE(itm.llocation, '') AS location_id,
    COALESCE((SELECT loc.lname FROM tblitem_location loc WHERE loc.lid = itm.llocation LIMIT 1), '') AS location_name,
    COALESCE(itm.lwarehouse, '') AS warehouse_id,
    COALESCE((SELECT br.lname FROM tblbranch br WHERE br.lid = itm.lwarehouse LIMIT 1), '') AS warehouse_name,
    COALESCE(itm.lpo_itemid, '') AS po_item_id
FROM tblpurchase_item itm
LEFT JOIN tblinventory_item inv
    ON inv.lid = itm.litemid
WHERE itm.lrefno = :refno
ORDER BY itm.lid ASC
SQL;
        $itemsStmt = $this->db->pdo()->prepare($itemsSql);
        $itemsStmt->bindValue('refno', $receivingRefno, PDO::PARAM_STR);
        $itemsStmt->execute();
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'item_count' => count($items),
            'total_qty' => 0,
            'total_cost' => 0.0,
        ];
        foreach ($items as $item) {
            $summary['total_qty'] += (int) ($item['qty'] ?? 0);
            $summary['total_cost'] += (float) ($item['line_total'] ?? 0);
        }

        return [
            'record' => $header,
            'items' => $items,
            'summary' => $summary,
        ];
    }

    public function createReceivingStock(int $mainId, int $userId, array $payload): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $refno = (string) ($payload['refno'] ?? $this->generateRefno());
            $rrNumber = trim((string) ($payload['rr_number'] ?? ''));
            if ($rrNumber === '') {
                $rrNumber = $this->nextReceivingNumber($pdo);
            }

            $supplier = $this->resolveSupplier((string) ($payload['supplier_id'] ?? ''));
            $receiveDate = $this->normalizeDate((string) ($payload['receive_date'] ?? date('Y-m-d')));
            $etaDate = $this->normalizeDateNullable((string) ($payload['eta_date'] ?? ''));
            $status = trim((string) ($payload['status'] ?? 'Pending'));
            if ($status === '') {
                $status = 'Pending';
            }

            $insert = $pdo->prepare(
                'INSERT INTO tblpurchase_order
                (lpurchaseno, ldate, ltime, lmain_id, luser, lrefno, ltransaction_status, lsupplier, lsupplier_name, lsupplier_code, lshipped_name, lreference, lterms, laddress, lpo_refno, lpo_number, ldate_recieved, leta_date)
                VALUES
                (:rr_number, :receive_date, CURRENT_TIME(), :main_id, :user_id, :refno, :status, :supplier_id, :supplier_name, :supplier_code, :shipped_name, :reference, :terms, :address, :po_refno, :po_number, :posted_date, :eta_date)'
            );
            $insert->execute([
                'rr_number' => $rrNumber,
                'receive_date' => $receiveDate,
                'main_id' => (string) $mainId,
                'user_id' => (string) $userId,
                'refno' => $refno,
                'status' => $status,
                'supplier_id' => $supplier['id'],
                'supplier_name' => $supplier['name'],
                'supplier_code' => $supplier['code'],
                'shipped_name' => (string) ($payload['shipped_name'] ?? ''),
                'reference' => (string) ($payload['reference'] ?? ''),
                'terms' => (string) ($payload['terms'] ?? ''),
                'address' => (string) ($payload['address'] ?? ''),
                'po_refno' => (string) ($payload['po_refno'] ?? ''),
                'po_number' => (string) ($payload['po_number'] ?? ''),
                'posted_date' => $receiveDate,
                'eta_date' => $etaDate,
            ]);

            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $this->upsertItemForReceiving($pdo, $mainId, $userId, $refno, $item, false);
            }

            $pdo->commit();

            // Some environments have non-transactional tblnumber_generator (e.g. MyISAM + GTID).
            // Keep RR creation successful and write running number separately on best effort.
            try {
                $this->recordNumberGenerator($pdo, 'Receiving', $rrNumber);
            } catch (\Throwable) {
            }

            return $this->getReceivingStock($mainId, $refno) ?? [];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateReceivingStock(int $mainId, string $receivingRefno, array $payload): ?array
    {
        $existing = $this->getReceivingStock($mainId, $receivingRefno);
        if ($existing === null) {
            return null;
        }

        $record = $existing['record'];
        $supplierId = (string) ($payload['supplier_id'] ?? ($record['supplier_id'] ?? ''));
        $supplier = $this->resolveSupplier($supplierId);

        $sql = <<<SQL
UPDATE tblpurchase_order
SET
    ldate = :receive_date,
    ltransaction_status = :status,
    lsupplier = :supplier_id,
    lsupplier_name = :supplier_name,
    lsupplier_code = :supplier_code,
    lshipped_name = :shipped_name,
    lreference = :reference,
    lterms = :terms,
    laddress = :address,
    lpo_refno = :po_refno,
    lpo_number = :po_number,
    leta_date = :eta_date
WHERE lmain_id = :main_id
  AND lrefno = :refno
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'receive_date' => $this->normalizeDate((string) ($payload['receive_date'] ?? ($record['receive_date'] ?? date('Y-m-d')))),
            'status' => (string) ($payload['status'] ?? ($record['status'] ?? 'Pending')),
            'supplier_id' => $supplier['id'],
            'supplier_name' => $supplier['name'],
            'supplier_code' => $supplier['code'],
            'shipped_name' => (string) ($payload['shipped_name'] ?? ($record['shipped_name'] ?? '')),
            'reference' => (string) ($payload['reference'] ?? ($record['reference'] ?? '')),
            'terms' => (string) ($payload['terms'] ?? ($record['terms'] ?? '')),
            'address' => (string) ($payload['address'] ?? ($record['address'] ?? '')),
            'po_refno' => (string) ($payload['po_refno'] ?? ($record['po_refno'] ?? '')),
            'po_number' => (string) ($payload['po_number'] ?? ($record['po_number'] ?? '')),
            'eta_date' => $this->normalizeDateNullable((string) ($payload['eta_date'] ?? ($record['eta_date'] ?? ''))),
            'main_id' => (string) $mainId,
            'refno' => $receivingRefno,
        ]);

        return $this->getReceivingStock($mainId, $receivingRefno);
    }

    public function deleteReceivingStock(int $mainId, string $receivingRefno): bool
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $check = $pdo->prepare('SELECT lrefno FROM tblpurchase_order WHERE lmain_id = :main_id AND lrefno = :refno LIMIT 1');
            $check->execute([
                'main_id' => (string) $mainId,
                'refno' => $receivingRefno,
            ]);
            if ($check->fetch(PDO::FETCH_ASSOC) === false) {
                $pdo->rollBack();
                return false;
            }

            $deleteHeader = $pdo->prepare('DELETE FROM tblpurchase_order WHERE lmain_id = :main_id AND lrefno = :refno');
            $deleteHeader->execute([
                'main_id' => (string) $mainId,
                'refno' => $receivingRefno,
            ]);

            $deleteItems = $pdo->prepare('DELETE FROM tblpurchase_item WHERE lrefno = :refno');
            $deleteItems->execute(['refno' => $receivingRefno]);

            $deleteLogs = $pdo->prepare('DELETE FROM tblinventory_logs WHERE lrefno = :refno');
            $deleteLogs->execute(['refno' => $receivingRefno]);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function addReceivingStockItem(int $mainId, int $userId, string $receivingRefno, array $payload): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $exists = $this->getReceivingStock($mainId, $receivingRefno);
            if ($exists === null) {
                throw new RuntimeException('Receiving stock record not found');
            }

            $itemRow = $this->upsertItemForReceiving($pdo, $mainId, $userId, $receivingRefno, $payload, true);
            $pdo->commit();
            return $itemRow;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateReceivingStockItem(int $mainId, int $itemId, array $payload): ?array
    {
        $existing = $this->getReceivingStockItem($mainId, $itemId);
        if ($existing === null) {
            return null;
        }

        $qty = isset($payload['qty']) ? (int) $payload['qty'] : (int) ($existing['qty'] ?? 0);
        if ($qty < 0) {
            throw new RuntimeException('qty must be zero or greater');
        }
        $cost = isset($payload['unit_cost']) ? (float) $payload['unit_cost'] : (float) ($existing['unit_cost'] ?? 0);

        $sql = <<<SQL
UPDATE tblpurchase_item
SET
    lqty = :qty,
    lsup_price = :unit_cost,
    lunit = :unit,
    lunit_qty = :unit_qty,
    llocation = :location_id,
    lwarehouse = :warehouse_id
WHERE lid = :item_id
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'qty' => $qty,
            'unit_cost' => $this->toMoneyString($cost),
            'unit' => (string) ($payload['unit'] ?? ($existing['unit'] ?? '')),
            'unit_qty' => (string) ($payload['unit_qty'] ?? ($existing['unit_qty'] ?? '0')),
            'location_id' => (string) ($payload['location_id'] ?? ($existing['location_id'] ?? '')),
            'warehouse_id' => (string) ($payload['warehouse_id'] ?? ($existing['warehouse_id'] ?? '')),
            'item_id' => $itemId,
        ]);

        return $this->getReceivingStockItem($mainId, $itemId);
    }

    public function deleteReceivingStockItem(int $mainId, int $itemId): bool
    {
        $item = $this->getReceivingStockItem($mainId, $itemId);
        if ($item === null) {
            return false;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $delItem = $pdo->prepare('DELETE FROM tblpurchase_item WHERE lid = :item_id');
            $delItem->execute(['item_id' => $itemId]);

            $delLog = $pdo->prepare('DELETE FROM tblinventory_logs WHERE lpurchase_item_id = :item_id');
            $delLog->execute(['item_id' => $itemId]);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function finalizeReceivingStock(int $mainId, string $receivingRefno, string $status = 'Delivered'): ?array
    {
        $record = $this->getReceivingStock($mainId, $receivingRefno);
        if ($record === null) {
            return null;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare(
                'UPDATE tblpurchase_order
                 SET ltransaction_status = :status, ldate_recieved = CURDATE()
                 WHERE lmain_id = :main_id AND lrefno = :refno'
            );
            $update->execute([
                'status' => $status === '' ? 'Delivered' : $status,
                'main_id' => (string) $mainId,
                'refno' => $receivingRefno,
            ]);

            $clearLogs = $pdo->prepare('DELETE FROM tblinventory_logs WHERE lrefno = :refno AND ltransaction_type = "Receiving"');
            $clearLogs->execute(['refno' => $receivingRefno]);

            $details = $this->getReceivingStock($mainId, $receivingRefno);
            if ($details === null) {
                throw new RuntimeException('Receiving stock record not found after finalize update');
            }

            $header = $details['record'];
            $supplierName = (string) ($header['supplier_name'] ?? '');
            $supplierId = (string) ($header['supplier_id'] ?? '');
            $rrNumber = (string) ($header['rr_number'] ?? '');
            $insertLog = $pdo->prepare(
                'INSERT INTO tblinventory_logs
                (linvent_id, lin, lout, ltotal, ldateadded, lprocess_by, lnote, lsupplier_id, linventory_id, lstatus_logs, ltransaction_item_id, lpurchase_item_id, lrefno, llocation, lwarehouse, ltransaction_type, litemcode, lpartno)
                VALUES
                (:invent_id, :lin, 0, :ltotal, NOW(), :process_by, :note, :supplier_id, :inventory_id, "+", "Purchase Order", :purchase_item_id, :refno, :location_id, :warehouse_id, "Receiving", :item_code, :part_no)'
            );

            foreach ($details['items'] as $item) {
                $qty = (int) ($item['qty'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                $insertLog->execute([
                    'invent_id' => (string) ($item['product_session'] ?? ''),
                    'lin' => $qty,
                    'ltotal' => $qty,
                    'process_by' => $rrNumber,
                    'note' => $supplierName,
                    'supplier_id' => $supplierId,
                    'inventory_id' => (string) ($item['product_id'] ?? ''),
                    'purchase_item_id' => (int) ($item['id'] ?? 0),
                    'refno' => $receivingRefno,
                    'location_id' => (string) ($item['location_name'] ?: $item['location_id'] ?: 'Main'),
                    'warehouse_id' => (string) ($item['warehouse_name'] ?: $item['warehouse_id'] ?: 'Main'),
                    'item_code' => (string) ($item['item_code'] ?? ''),
                    'part_no' => (string) ($item['part_no'] ?? ''),
                ]);
            }

            $pdo->commit();
            return $this->getReceivingStock($mainId, $receivingRefno);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getReceivingStockItem(int $mainId, int $itemId): ?array
    {
        $sql = <<<SQL
SELECT
    itm.lid AS id,
    COALESCE(itm.lrefno, '') AS receiving_refno,
    CAST(COALESCE(itm.litemid, 0) AS SIGNED) AS product_id,
    COALESCE(itm.litem_refno, '') AS product_session,
    COALESCE(itm.litem_code, '') AS item_code,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.ldesc, '') AS description,
    CAST(COALESCE(itm.lqty, 0) AS SIGNED) AS qty,
    COALESCE(itm.lunit, '') AS unit,
    CAST(COALESCE(NULLIF(itm.lunit_qty, ''), '0') AS DECIMAL(15,2)) AS unit_qty,
    CAST(COALESCE(NULLIF(itm.lsup_price, ''), '0') AS DECIMAL(15,2)) AS unit_cost,
    COALESCE(itm.llocation, '') AS location_id,
    COALESCE(itm.lwarehouse, '') AS warehouse_id
FROM tblpurchase_item itm
INNER JOIN tblpurchase_order rr
    ON rr.lrefno = itm.lrefno
WHERE rr.lmain_id = :main_id
  AND itm.lid = :item_id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', (string) $mainId, PDO::PARAM_STR);
        $stmt->bindValue('item_id', $itemId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function upsertItemForReceiving(
        PDO $pdo,
        int $mainId,
        int $userId,
        string $receivingRefno,
        array $payload,
        bool $allowIncrement
    ): array {
        $item = $this->resolveInventoryItem($mainId, $payload);
        $qty = (int) ($payload['qty'] ?? 0);
        if ($qty <= 0) {
            throw new RuntimeException('qty must be greater than 0');
        }

        $unitCost = isset($payload['unit_cost']) ? (float) $payload['unit_cost'] : (float) ($item['lcost'] ?? 0);
        $unit = (string) ($payload['unit'] ?? ($item['inv_lunit'] ?? ''));
        $unitQty = (string) ($payload['unit_qty'] ?? '0');

        $existingStmt = $pdo->prepare(
            'SELECT lid, lqty FROM tblpurchase_item WHERE lrefno = :refno AND litem_refno = :item_refno LIMIT 1'
        );
        $existingStmt->execute([
            'refno' => $receivingRefno,
            'item_refno' => (string) $item['lsession'],
        ]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing !== false && $allowIncrement) {
            $nextQty = ((int) ($existing['lqty'] ?? 0)) + $qty;
            $update = $pdo->prepare(
                'UPDATE tblpurchase_item
                 SET lqty = :qty, lsup_price = :unit_cost, lunit = :unit, lunit_qty = :unit_qty
                 WHERE lid = :id'
            );
            $update->execute([
                'qty' => $nextQty,
                'unit_cost' => $this->toMoneyString($unitCost),
                'unit' => $unit,
                'unit_qty' => $unitQty,
                'id' => (int) $existing['lid'],
            ]);
            return $this->getReceivingStockItem($mainId, (int) $existing['lid']) ?? [];
        }

        $insert = $pdo->prepare(
            'INSERT INTO tblpurchase_item
            (lrefno, litemid, ldesc, lqty, luser, lpartno, litem_code, litem_refno, lsup_price, lunit, lunit_qty, llocation, lwarehouse, lbrand, lupdated)
            VALUES
            (:refno, :item_id, :description, :qty, :user_id, :part_no, :item_code, :item_refno, :unit_cost, :unit, :unit_qty, :location_id, :warehouse_id, :brand, 1)'
        );
        $insert->execute([
            'refno' => $receivingRefno,
            'item_id' => (string) $item['lid'],
            'description' => (string) ($payload['description'] ?? ($item['ldescription'] ?? '')),
            'qty' => $qty,
            'user_id' => (string) $userId,
            'part_no' => (string) ($payload['part_no'] ?? ($item['lpartno'] ?? '')),
            'item_code' => (string) ($payload['item_code'] ?? ($item['litemcode'] ?? '')),
            'item_refno' => (string) $item['lsession'],
            'unit_cost' => $this->toMoneyString($unitCost),
            'unit' => $unit,
            'unit_qty' => $unitQty,
            'location_id' => (string) ($payload['location_id'] ?? 'Main'),
            'warehouse_id' => (string) ($payload['warehouse_id'] ?? 'Main'),
            'brand' => (string) ($payload['brand'] ?? ($item['lbrand'] ?? '')),
        ]);

        $itemId = (int) $pdo->lastInsertId();
        return $this->getReceivingStockItem($mainId, $itemId) ?? [];
    }

    /**
     * @return array{id: string, code: string, name: string}
     */
    private function resolveSupplier(string $supplierId): array
    {
        $trimmed = trim($supplierId);
        if ($trimmed === '') {
            return ['id' => '', 'code' => '', 'name' => ''];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT CAST(lid AS CHAR) AS id, COALESCE(lcode, "") AS code, COALESCE(lname, "") AS name
             FROM tblsupplier
             WHERE lid = :id
             LIMIT 1'
        );
        $stmt->bindValue('id', $trimmed, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Supplier not found');
        }
        return [
            'id' => (string) ($row['id'] ?? ''),
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveInventoryItem(int $mainId, array $payload): array
    {
        $productSession = trim((string) ($payload['product_session'] ?? ''));
        $productId = (int) ($payload['product_id'] ?? 0);

        if ($productSession === '' && $productId <= 0) {
            throw new RuntimeException('product_session or product_id is required');
        }

        if ($productSession !== '') {
            $stmt = $this->db->pdo()->prepare(
                'SELECT * FROM tblinventory_item WHERE lmain_id = :main_id AND lsession = :session LIMIT 1'
            );
            $stmt->execute([
                'main_id' => (string) $mainId,
                'session' => $productSession,
            ]);
        } else {
            $stmt = $this->db->pdo()->prepare(
                'SELECT * FROM tblinventory_item WHERE lmain_id = :main_id AND lid = :id LIMIT 1'
            );
            $stmt->execute([
                'main_id' => (string) $mainId,
                'id' => $productId,
            ]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Inventory item not found');
        }

        return $row;
    }

    private function nextReceivingNumber(PDO $pdo): string
    {
        $stmt = $pdo->query(
            "SELECT COALESCE(MAX(lmax_no), 0) AS max_no FROM tblnumber_generator WHERE ltransaction_type = 'Receiving'"
        );
        $maxNo = (int) (($stmt->fetch(PDO::FETCH_ASSOC)['max_no'] ?? 0));
        $next = $maxNo + 1;
        return 'RR-' . date('y') . str_pad((string) $next, 2, '0', STR_PAD_LEFT);
    }

    private function recordNumberGenerator(PDO $pdo, string $type, string $number): void
    {
        if (!preg_match('/(\d+)$/', $number, $matches)) {
            return;
        }
        $maxNo = (int) $matches[1];
        $stmt = $pdo->prepare(
            'INSERT INTO tblnumber_generator (ltransaction_type, lmax_no) VALUES (:type, :max_no)'
        );
        $stmt->execute([
            'type' => $type,
            'max_no' => $maxNo,
        ]);
    }

    private function generateRefno(): string
    {
        return date('YmdHis') . random_int(100000, 999999);
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException('date is required');
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new RuntimeException('invalid date format');
        }
        return date('Y-m-d', $ts);
    }

    private function normalizeDateNullable(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        return $this->normalizeDate($trimmed);
    }

    private function toMoneyString(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function bindParams(\PDOStatement $stmt, array $params, bool $bindLimitOffset): void
    {
        foreach ($params as $key => $value) {
            if (($key === 'limit' || $key === 'offset') && !$bindLimitOffset) {
                continue;
            }

            if (($key === 'limit' || $key === 'offset') && $bindLimitOffset) {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }

            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
                continue;
            }

            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
    }
}
