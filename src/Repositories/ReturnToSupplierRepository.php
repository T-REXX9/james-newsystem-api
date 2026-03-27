<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class ReturnToSupplierRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listReturns(
        int $mainId,
        ?int $month,
        ?int $year,
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
            'limit' => $perPage,
            'offset' => $offset,
        ];

        $where = ['rs.lmainid = :main_id'];

        if ($month !== null) {
            $where[] = 'MONTH(COALESCE(rs.ldate, rs.ldaterec, rs.ldatetime)) = :month';
            $params['month'] = $month;
        }

        if ($year !== null) {
            $where[] = 'YEAR(COALESCE(rs.ldate, rs.ldaterec, rs.ldatetime)) = :year';
            $params['year'] = $year;
        }

        $normalizedStatus = strtolower(trim($status));
        if ($normalizedStatus !== '' && $normalizedStatus !== 'all') {
            $where[] = 'LOWER(COALESCE(rs.lstatus, "Pending")) = :status';
            $params['status'] = $normalizedStatus;
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $params['search_credit_no'] = '%' . $trimmedSearch . '%';
            $params['search_refno'] = '%' . $trimmedSearch . '%';
            $params['search_supplier'] = '%' . $trimmedSearch . '%';
            $params['search_remarks'] = '%' . $trimmedSearch . '%';
            $params['search_item'] = '%' . $trimmedSearch . '%';
            $where[] = <<<SQL
(
    rs.lcredit_no LIKE :search_credit_no
    OR rs.lrefno LIKE :search_refno
    OR COALESCE(sup.lname, '') LIKE :search_supplier
    OR COALESCE(rs.lremark, '') LIKE :search_remarks
    OR EXISTS (
        SELECT 1
        FROM tblreturn_supplier_item rsi
        WHERE rsi.lrefno = rs.lrefno
          AND CONCAT_WS(' ', COALESCE(rsi.litemcode, ''), COALESCE(rsi.lpartno, ''), COALESCE(rsi.ldesc, '')) LIKE :search_item
    )
)
SQL;
        }

        $whereSql = implode(' AND ', $where);

        $countSql = <<<SQL
SELECT COUNT(*)
FROM tblreturn_supplier rs
LEFT JOIN tblsupplier sup
  ON sup.lrefno = rs.lcustomer
WHERE {$whereSql}
SQL;

        $countParams = $params;
        unset($countParams['limit'], $countParams['offset']);

        $countStmt = $this->db->pdo()->prepare($countSql);
        $this->bindParams($countStmt, $countParams, false);
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = <<<SQL
SELECT
    rs.lid AS id,
    COALESCE(rs.lrefno, '') AS refno,
    COALESCE(rs.lcredit_no, '') AS return_no,
    COALESCE(rs.ltype, 'Purchase') AS return_type,
    COALESCE(rs.ldate, rs.ldaterec, DATE(rs.ldatetime)) AS return_date,
    COALESCE(rs.ltransaction_refno, '') AS rr_refno,
    COALESCE(rs.lmy_refno, '') AS rr_no,
    COALESCE(rs.lcustomer, '') AS supplier_refno,
    COALESCE(rs.lsupp_id, '') AS supplier_id,
    COALESCE(sup.lname, '') AS supplier_name,
    COALESCE(rs.lpo_no, '') AS po_no,
    COALESCE(rs.lstatus, 'Pending') AS status,
    COALESCE(rs.lremark, '') AS remarks,
    COALESCE(rs.lwarehouse, 'WH1') AS warehouse,
    COALESCE(rs.luserid, '') AS created_by_id,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by,
    rs.ldatetime AS created_at,
    CAST(COALESCE(items.item_count, 0) AS SIGNED) AS item_count,
    CAST(COALESCE(items.total_qty, 0) AS DECIMAL(15,2)) AS total_qty,
    CAST(COALESCE(items.grand_total, 0) AS DECIMAL(15,2)) AS grand_total
FROM tblreturn_supplier rs
LEFT JOIN tblsupplier sup
  ON sup.lrefno = rs.lcustomer
LEFT JOIN tblaccount acc
  ON acc.lid = rs.luserid
LEFT JOIN (
    SELECT
        lrefno,
        COUNT(*) AS item_count,
        SUM(COALESCE(lqty, 0)) AS total_qty,
        SUM(COALESCE(lqty, 0) * COALESCE(lprice, 0)) AS grand_total
    FROM tblreturn_supplier_item
    GROUP BY lrefno
) items
  ON items.lrefno = rs.lrefno
WHERE {$whereSql}
ORDER BY rs.lid DESC
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

    public function getReturn(int $mainId, string $returnRefno): ?array
    {
        $headerSql = <<<SQL
SELECT
    rs.lid AS id,
    COALESCE(rs.lrefno, '') AS refno,
    COALESCE(rs.lcredit_no, '') AS return_no,
    COALESCE(rs.ltype, 'Purchase') AS return_type,
    COALESCE(rs.ldate, rs.ldaterec, DATE(rs.ldatetime)) AS return_date,
    COALESCE(rs.ltransaction_refno, '') AS rr_refno,
    COALESCE(rs.lmy_refno, '') AS rr_no,
    COALESCE(rs.lcustomer, '') AS supplier_refno,
    COALESCE(rs.lsupp_id, '') AS supplier_id,
    COALESCE(sup.lname, '') AS supplier_name,
    COALESCE(rs.lpo_no, '') AS po_no,
    COALESCE(rs.lstatus, 'Pending') AS status,
    COALESCE(rs.lremark, '') AS remarks,
    COALESCE(rs.lwarehouse, 'WH1') AS warehouse,
    COALESCE(rs.luserid, '') AS created_by_id,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by,
    rs.ldatetime AS created_at
FROM tblreturn_supplier rs
LEFT JOIN tblsupplier sup
  ON sup.lrefno = rs.lcustomer
LEFT JOIN tblaccount acc
  ON acc.lid = rs.luserid
WHERE rs.lmainid = :main_id
  AND rs.lrefno = :refno
LIMIT 1
SQL;

        $headerStmt = $this->db->pdo()->prepare($headerSql);
        $headerStmt->bindValue('main_id', (string) $mainId, PDO::PARAM_STR);
        $headerStmt->bindValue('refno', $returnRefno, PDO::PARAM_STR);
        $headerStmt->execute();
        $record = $headerStmt->fetch(PDO::FETCH_ASSOC);
        if ($record === false) {
            return null;
        }

        $items = $this->getReturnItemsByRefno($returnRefno);

        $summary = [
            'item_count' => count($items),
            'total_qty' => 0,
            'grand_total' => 0.0,
        ];

        foreach ($items as $item) {
            $summary['total_qty'] += (float) ($item['qty_returned'] ?? 0);
            $summary['grand_total'] += (float) ($item['total_amount'] ?? 0);
        }

        return [
            'record' => $record,
            'items' => $items,
            'summary' => $summary,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getReturnItems(int $mainId, string $returnRefno): array
    {
        $header = $this->getReturnHeader($mainId, $returnRefno);
        if ($header === null) {
            throw new RuntimeException('Return to supplier record not found');
        }

        return $this->getReturnItemsByRefno($returnRefno);
    }

    public function createReturn(int $mainId, int $userId, array $payload): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $supplierRefno = trim((string) ($payload['supplier_refno'] ?? $payload['supplier_id'] ?? ''));
            if ($supplierRefno === '') {
                throw new RuntimeException('supplier_refno is required');
            }

            $supplier = $this->resolveSupplier($supplierRefno);
            if ($supplier === null) {
                throw new RuntimeException('Supplier not found');
            }

            $refno = trim((string) ($payload['refno'] ?? ''));
            if ($refno === '') {
                $refno = $this->generateRefno();
            }

            $returnNo = trim((string) ($payload['return_no'] ?? ''));
            if ($returnNo === '') {
                $returnNo = $this->nextReturnNumber($pdo);
            }

            $returnDate = $this->normalizeDate((string) ($payload['return_date'] ?? date('Y-m-d')));
            $status = trim((string) ($payload['status'] ?? 'Pending'));
            if ($status === '') {
                $status = 'Pending';
            }

            $insert = $pdo->prepare(
                'INSERT INTO tblreturn_supplier (
                    lmainid,
                    lrefno,
                    luserid,
                    lcredit_no,
                    lcustomer,
                    ldate,
                    ldaterec,
                    lstatus,
                    lremark,
                    lwarehouse,
                    lpo_no,
                    ltransaction_refno,
                    lmy_refno,
                    ltype,
                    lsupp_id
                ) VALUES (
                    :main_id,
                    :refno,
                    :user_id,
                    :credit_no,
                    :supplier_refno,
                    :return_date,
                    :return_date_record,
                    :status,
                    :remarks,
                    :warehouse,
                    :po_no,
                    :rr_refno,
                    :rr_no,
                    :return_type,
                    :supplier_id
                )'
            );

            $insert->execute([
                'main_id' => (string) $mainId,
                'refno' => $refno,
                'user_id' => (string) $userId,
                'credit_no' => $returnNo,
                'supplier_refno' => $supplierRefno,
                'return_date' => $returnDate,
                'return_date_record' => $returnDate,
                'status' => $status,
                'remarks' => trim((string) ($payload['remarks'] ?? '')),
                'warehouse' => trim((string) ($payload['warehouse'] ?? 'WH1')) ?: 'WH1',
                'po_no' => trim((string) ($payload['po_no'] ?? '')),
                'rr_refno' => trim((string) ($payload['rr_refno'] ?? $payload['rr_id'] ?? '')),
                'rr_no' => trim((string) ($payload['rr_no'] ?? '')),
                'return_type' => trim((string) ($payload['return_type'] ?? 'Purchase')),
                'supplier_id' => (string) ($supplier['lid'] ?? ''),
            ]);

            $items = $payload['items'] ?? [];
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $this->insertReturnItem($pdo, $refno, $userId, (int) ($supplier['lid'] ?? 0), $item);
                }
            }

            $pdo->commit();

            $record = $this->getReturn($mainId, $refno);
            if ($record === null) {
                throw new RuntimeException('Failed to create return to supplier');
            }

            return $record;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    public function updateReturn(int $mainId, string $returnRefno, array $payload): ?array
    {
        $header = $this->getReturnHeader($mainId, $returnRefno);
        if ($header === null) {
            return null;
        }

        if (strcasecmp((string) ($header['lstatus'] ?? ''), 'Posted') === 0) {
            throw new RuntimeException('Posted return cannot be updated');
        }

        $fields = [];
        $params = [
            'main_id' => (string) $mainId,
            'refno' => $returnRefno,
        ];

        if (array_key_exists('return_date', $payload)) {
            $fields[] = 'ldate = :return_date';
            $fields[] = 'ldaterec = :return_date';
            $params['return_date'] = $this->normalizeDate((string) $payload['return_date']);
        }

        if (array_key_exists('remarks', $payload)) {
            $fields[] = 'lremark = :remarks';
            $params['remarks'] = trim((string) $payload['remarks']);
        }

        if (array_key_exists('warehouse', $payload)) {
            $warehouse = trim((string) $payload['warehouse']);
            $fields[] = 'lwarehouse = :warehouse';
            $params['warehouse'] = $warehouse !== '' ? $warehouse : 'WH1';
        }

        if (array_key_exists('po_no', $payload)) {
            $fields[] = 'lpo_no = :po_no';
            $params['po_no'] = trim((string) $payload['po_no']);
        }

        if (array_key_exists('status', $payload)) {
            $fields[] = 'lstatus = :status';
            $params['status'] = trim((string) $payload['status']) ?: 'Pending';
        }

        if (empty($fields)) {
            $record = $this->getReturn($mainId, $returnRefno);
            if ($record === null) {
                throw new RuntimeException('Return to supplier record not found');
            }
            return $record;
        }

        $sql = 'UPDATE tblreturn_supplier SET ' . implode(', ', $fields) . ' WHERE lmainid = :main_id AND lrefno = :refno LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $params, false);
        $stmt->execute();

        $record = $this->getReturn($mainId, $returnRefno);
        if ($record === null) {
            throw new RuntimeException('Return to supplier record not found');
        }

        return $record;
    }

    public function deleteReturn(int $mainId, string $returnRefno): bool
    {
        $header = $this->getReturnHeader($mainId, $returnRefno);
        if ($header === null) {
            return false;
        }

        if (strcasecmp((string) ($header['lstatus'] ?? ''), 'Posted') === 0) {
            throw new RuntimeException('Posted return cannot be deleted');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $deleteItems = $pdo->prepare('DELETE FROM tblreturn_supplier_item WHERE lrefno = :refno');
            $deleteItems->execute(['refno' => $returnRefno]);

            $deleteLogs = $pdo->prepare('DELETE FROM tblinventory_logs WHERE lrefno = :refno AND ltransaction_type = "Return to Supplier"');
            $deleteLogs->execute(['refno' => $returnRefno]);

            $deleteHeader = $pdo->prepare('DELETE FROM tblreturn_supplier WHERE lmainid = :main_id AND lrefno = :refno LIMIT 1');
            $deleteHeader->execute([
                'main_id' => (string) $mainId,
                'refno' => $returnRefno,
            ]);

            $pdo->commit();
            return $deleteHeader->rowCount() > 0;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    public function addItem(int $mainId, int $userId, string $returnRefno, array $payload): array
    {
        $header = $this->getReturnHeader($mainId, $returnRefno);
        if ($header === null) {
            throw new RuntimeException('Return to supplier record not found');
        }

        if (strcasecmp((string) ($header['lstatus'] ?? ''), 'Posted') === 0) {
            throw new RuntimeException('Posted return cannot be edited');
        }

        $supplierId = (int) ($header['lsupp_id'] ?? 0);

        $insertedId = 0;
        $this->db->pdo()->beginTransaction();
        try {
            $insertedId = $this->insertReturnItem($this->db->pdo(), $returnRefno, $userId, $supplierId, $payload);
            $this->db->pdo()->commit();
        } catch (\Throwable $e) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return $this->mustGetReturnItem($insertedId);
    }

    public function updateItem(int $mainId, int $itemId, array $payload): ?array
    {
        $item = $this->getReturnItemById($itemId);
        if ($item === null) {
            return null;
        }

        $header = $this->getReturnHeader($mainId, (string) $item['lrefno']);
        if ($header === null) {
            return null;
        }

        if (strcasecmp((string) ($header['lstatus'] ?? ''), 'Posted') === 0) {
            throw new RuntimeException('Posted return item cannot be edited');
        }

        $fields = [];
        $params = ['item_id' => $itemId];

        if (array_key_exists('qty_returned', $payload) || array_key_exists('qty', $payload)) {
            $qty = (float) ($payload['qty_returned'] ?? $payload['qty'] ?? 0);
            if ($qty <= 0) {
                throw new RuntimeException('qty_returned must be greater than 0');
            }
            $fields[] = 'lqty = :qty';
            $params['qty'] = $qty;
        }

        if (array_key_exists('unit_cost', $payload) || array_key_exists('lprice', $payload)) {
            $fields[] = 'lprice = :price';
            $params['price'] = (float) ($payload['unit_cost'] ?? $payload['lprice'] ?? 0);
        }

        if (array_key_exists('remarks', $payload) || array_key_exists('lnote', $payload)) {
            $fields[] = 'lnote = :note';
            $params['note'] = trim((string) ($payload['remarks'] ?? $payload['lnote'] ?? ''));
        }

        if (array_key_exists('description', $payload) || array_key_exists('ldesc', $payload)) {
            $fields[] = 'ldesc = :description';
            $params['description'] = trim((string) ($payload['description'] ?? $payload['ldesc'] ?? ''));
        }

        if (empty($fields)) {
            return $this->mapReturnItem($item);
        }

        $sql = 'UPDATE tblreturn_supplier_item SET ' . implode(', ', $fields) . ' WHERE lid = :item_id LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $params, false);
        $stmt->execute();

        return $this->mustGetReturnItem($itemId);
    }

    public function deleteItem(int $mainId, int $itemId): bool
    {
        $item = $this->getReturnItemById($itemId);
        if ($item === null) {
            return false;
        }

        $header = $this->getReturnHeader($mainId, (string) $item['lrefno']);
        if ($header === null) {
            return false;
        }

        if (strcasecmp((string) ($header['lstatus'] ?? ''), 'Posted') === 0) {
            throw new RuntimeException('Posted return item cannot be deleted');
        }

        $stmt = $this->db->pdo()->prepare('DELETE FROM tblreturn_supplier_item WHERE lid = :item_id LIMIT 1');
        $stmt->bindValue('item_id', $itemId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function applyAction(int $mainId, string $returnRefno, string $action, array $payload): ?array
    {
        $record = $this->getReturn($mainId, $returnRefno);
        if ($record === null) {
            return null;
        }

        $header = $record['record'] ?? [];
        $currentStatus = strtolower((string) ($header['status'] ?? 'pending'));
        $normalizedAction = strtolower(trim($action));

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            if (in_array($normalizedAction, ['post', 'finalize', 'submitrecord'], true)) {
                if ($currentStatus !== 'posted') {
                    $this->updateStatus($pdo, $mainId, $returnRefno, 'Posted');
                    $this->insertInventoryLogs(
                        $pdo,
                        $returnRefno,
                        (string) ($header['warehouse'] ?? 'WH1'),
                        (string) ($header['return_date'] ?? date('Y-m-d')),
                        (string) ($header['return_no'] ?? ''),
                        (string) ($header['supplier_name'] ?? ''),
                        (string) ($header['remarks'] ?? '')
                    );
                }
            } elseif (in_array($normalizedAction, ['unpost'], true)) {
                $this->updateStatus($pdo, $mainId, $returnRefno, 'Pending');
                $deleteLogs = $pdo->prepare('DELETE FROM tblinventory_logs WHERE lrefno = :refno AND ltransaction_type = "Return to Supplier"');
                $deleteLogs->execute(['refno' => $returnRefno]);
            } elseif (in_array($normalizedAction, ['approve', 'approve-record', 'approverecord'], true)) {
                $this->updateStatus($pdo, $mainId, $returnRefno, 'Approved');
            } elseif (in_array($normalizedAction, ['cancel', 'void'], true)) {
                $this->updateStatus($pdo, $mainId, $returnRefno, 'Cancelled');
            } else {
                throw new RuntimeException('Unsupported action');
            }

            $pdo->commit();

            $updated = $this->getReturn($mainId, $returnRefno);
            if ($updated === null) {
                throw new RuntimeException('Return to supplier record not found');
            }

            return $updated;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function searchReceivingReports(int $mainId, string $query, int $limit = 10): array
    {
        $limit = min(100, max(1, $limit));
        $search = trim($query);

        $sql = <<<SQL
SELECT
    rr.lid AS id,
    COALESCE(rr.lrefno, '') AS refno,
    COALESCE(rr.lpurchaseno, '') AS rr_no,
    rr.ldate AS receive_date,
    COALESCE(rr.ltransaction_status, 'Pending') AS status,
    COALESCE(rr.lsupplier, '') AS supplier_id,
    COALESCE(sup.lrefno, '') AS supplier_refno,
    COALESCE(rr.lsupplier_name, '') AS supplier_name,
    COALESCE(rr.lpo_refno, '') AS po_refno,
    COALESCE(rr.lpo_number, '') AS po_no,
    CAST(COALESCE(agg.item_count, 0) AS SIGNED) AS item_count,
    CAST(COALESCE(agg.total_qty, 0) AS DECIMAL(15,2)) AS total_qty
FROM tblpurchase_order rr
LEFT JOIN tblsupplier sup
  ON sup.lid = rr.lsupplier
LEFT JOIN (
    SELECT lrefno, COUNT(*) AS item_count, SUM(COALESCE(lqty, 0)) AS total_qty
    FROM tblpurchase_item
    GROUP BY lrefno
) agg
  ON agg.lrefno = rr.lrefno
WHERE rr.lmain_id = :main_id
  AND LOWER(COALESCE(rr.ltransaction_status, '')) IN ('delivered', 'posted')
SQL;

        $params = [
            'main_id' => (string) $mainId,
        ];

        if ($search !== '') {
            $sql .= ' AND (rr.lpurchaseno LIKE :search_rr OR rr.lrefno LIKE :search_refno OR COALESCE(rr.lsupplier_name, "") LIKE :search_supplier)';
            $params['search_rr'] = '%' . $search . '%';
            $params['search_refno'] = '%' . $search . '%';
            $params['search_supplier'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY rr.lid DESC LIMIT :limit';

        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, array_merge($params, ['limit' => $limit]), true);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    public function getReceivingReportItemsForReturn(
        int $mainId,
        string $rrRefno,
        string $search = '',
        int $limit = 25
    ): array
    {
        $limit = min(100, max(1, $limit));
        $rrStmt = $this->db->pdo()->prepare(
            'SELECT lrefno FROM tblpurchase_order WHERE lmain_id = :main_id AND lrefno = :rr_refno LIMIT 1'
        );
        $rrStmt->bindValue('main_id', (string) $mainId, PDO::PARAM_STR);
        $rrStmt->bindValue('rr_refno', $rrRefno, PDO::PARAM_STR);
        $rrStmt->execute();
        $rrExists = $rrStmt->fetch(PDO::FETCH_ASSOC);
        if ($rrExists === false) {
            throw new RuntimeException('Receiving report not found');
        }

        $itemsSql = <<<SQL
SELECT
    itm.lid AS id,
    COALESCE(itm.litemid, '') AS item_id,
    COALESCE(itm.litem_refno, '') AS item_refno,
    COALESCE(itm.litem_code, '') AS item_code,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.ldesc, inv.ldescription, '') AS description,
    CAST(COALESCE(itm.lqty, 0) AS DECIMAL(15,2)) AS quantity_received,
    CAST(COALESCE(NULLIF(itm.lsup_price, ''), inv.lcost, 0) AS DECIMAL(15,2)) AS unit_cost,
    CAST(
        COALESCE(
            (
                SELECT SUM(COALESCE(rsi.lqty, 0))
                FROM tblreturn_supplier_item rsi
                INNER JOIN tblreturn_supplier rs
                  ON rs.lrefno = rsi.lrefno
                WHERE rs.lmainid = :main_id_sub
                  AND COALESCE(rs.ltransaction_refno, '') = :rr_refno_sub
                  AND COALESCE(rs.lstatus, 'Pending') <> 'Cancelled'
                  AND (
                    COALESCE(rsi.linv_refno, '') = COALESCE(itm.litem_refno, '')
                    OR COALESCE(rsi.litemcode, '') = COALESCE(itm.litem_code, '')
                  )
            ),
            0
        ) AS DECIMAL(15,2)
    ) AS qty_returned_already
FROM tblpurchase_item itm
LEFT JOIN tblinventory_item inv
  ON inv.lid = itm.litemid
WHERE itm.lrefno = :rr_refno
SQL;

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $itemsSql .= <<<SQL
 AND (
    COALESCE(itm.lpartno, '') LIKE :search_part_no
    OR COALESCE(itm.litem_code, '') LIKE :search_item_code
    OR COALESCE(itm.ldesc, inv.ldescription, '') LIKE :search_description
 )
SQL;
        }

        $itemsSql .= "\nORDER BY itm.lid ASC\nLIMIT :limit";

        $stmt = $this->db->pdo()->prepare($itemsSql);
        $stmt->bindValue('rr_refno', $rrRefno, PDO::PARAM_STR);
        $stmt->bindValue('main_id_sub', (string) $mainId, PDO::PARAM_STR);
        $stmt->bindValue('rr_refno_sub', $rrRefno, PDO::PARAM_STR);
        if ($trimmedSearch !== '') {
            $like = '%' . $trimmedSearch . '%';
            $stmt->bindValue('search_part_no', $like, PDO::PARAM_STR);
            $stmt->bindValue('search_item_code', $like, PDO::PARAM_STR);
            $stmt->bindValue('search_description', $like, PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function updateStatus(PDO $pdo, int $mainId, string $returnRefno, string $status): void
    {
        $stmt = $pdo->prepare(
            'UPDATE tblreturn_supplier SET lstatus = :status WHERE lmainid = :main_id AND lrefno = :refno LIMIT 1'
        );
        $stmt->execute([
            'status' => $status,
            'main_id' => (string) $mainId,
            'refno' => $returnRefno,
        ]);
    }

    private function insertInventoryLogs(
        PDO $pdo,
        string $refno,
        string $warehouse,
        string $returnDate,
        string $returnNo,
        string $supplierName,
        string $remarks
    ): void {
        $items = $this->getReturnItemsByRefno($refno);

        $insert = $pdo->prepare(
            'INSERT INTO tblinventory_logs (
                linvent_id,
                lin,
                lout,
                ltotal,
                ldateadded,
                lprocess_by,
                lstatus_logs,
                lnote,
                linventory_id,
                lprice,
                lrefno,
                llocation,
                lwarehouse,
                ltransaction_type
            ) VALUES (
                :linvent_id,
                0,
                :lout,
                :ltotal,
                :ldateadded,
                :lprocess_by,
                "-",
                :lnote,
                :linventory_id,
                :lprice,
                :lrefno,
                "",
                :lwarehouse,
                "Return to Supplier"
            )'
        );

        $existsStmt = $pdo->prepare(
            'SELECT lid FROM tblinventory_logs WHERE lrefno = :refno AND linvent_id = :linvent_id AND ltransaction_type = "Return to Supplier" LIMIT 1'
        );

        foreach ($items as $item) {
            $invRefno = (string) ($item['inv_refno'] ?? '');
            $qty = (float) ($item['qty_returned'] ?? 0);
            if ($invRefno === '' || $qty <= 0) {
                continue;
            }

            $existsStmt->execute([
                'refno' => $refno,
                'linvent_id' => $invRefno,
            ]);
            if ($existsStmt->fetch(PDO::FETCH_ASSOC) !== false) {
                continue;
            }

            $noteParts = array_filter([
                trim($supplierName),
                trim($remarks),
            ]);

            $insert->execute([
                'linvent_id' => $invRefno,
                'lout' => $qty,
                'ltotal' => $qty,
                'ldateadded' => $this->normalizeDateTime($returnDate),
                'lprocess_by' => 'RS ' . trim($returnNo),
                'lnote' => implode('-', $noteParts),
                'linventory_id' => (string) ($item['item_id'] ?? ''),
                'lprice' => (float) ($item['unit_cost'] ?? 0),
                'lrefno' => $refno,
                'lwarehouse' => $warehouse !== '' ? $warehouse : 'WH1',
            ]);
        }
    }

    private function insertReturnItem(PDO $pdo, string $returnRefno, int $userId, int $supplierId, array $payload): int
    {
        $invRefno = trim((string) ($payload['inv_refno'] ?? $payload['item_refno'] ?? $payload['linv_refno'] ?? ''));
        $itemCode = trim((string) ($payload['item_code'] ?? $payload['litemcode'] ?? ''));

        $item = $this->resolveInventoryItem($invRefno, $itemCode);
        if ($item === null) {
            throw new RuntimeException('Inventory item not found');
        }

        $qty = (float) ($payload['qty_returned'] ?? $payload['qty'] ?? $payload['lqty'] ?? 0);
        if ($qty <= 0) {
            throw new RuntimeException('qty_returned must be greater than 0');
        }

        $unitCost = (float) ($payload['unit_cost'] ?? $payload['lprice'] ?? 0);
        if ($unitCost <= 0) {
            $unitCost = $this->resolveSupplierCost($supplierId, (string) ($item['lsession'] ?? ''));
        }

        $description = trim((string) ($payload['description'] ?? $payload['ldesc'] ?? $item['ldescription'] ?? ''));
        $note = trim((string) ($payload['remarks'] ?? $payload['lnote'] ?? $payload['return_reason'] ?? ''));

        $insert = $pdo->prepare(
            'INSERT INTO tblreturn_supplier_item (
                lrefno,
                litemid,
                ldesc,
                linv_refno,
                lprice,
                lqty,
                luser,
                litemcode,
                lpartno,
                lbrand,
                llocation,
                lnote,
                litem_refno,
                ltype
            ) VALUES (
                :refno,
                :item_id,
                :description,
                :inv_refno,
                :unit_cost,
                :qty,
                :user_id,
                :item_code,
                :part_no,
                :brand,
                :location,
                :note,
                :item_refno,
                "Purchase"
            )'
        );

        $insert->execute([
            'refno' => $returnRefno,
            'item_id' => (string) ($item['lid'] ?? ''),
            'description' => $description,
            'inv_refno' => (string) ($item['lsession'] ?? ''),
            'unit_cost' => $unitCost,
            'qty' => $qty,
            'user_id' => (string) $userId,
            'item_code' => $itemCode !== '' ? $itemCode : (string) ($item['litemcode'] ?? ''),
            'part_no' => trim((string) ($payload['part_no'] ?? $payload['lpartno'] ?? $item['lpartno'] ?? '')),
            'brand' => (string) ($item['lbrand'] ?? ''),
            'location' => (string) ($item['llocation'] ?? ''),
            'note' => $note,
            'item_refno' => trim((string) ($payload['item_refno'] ?? '')),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function getReturnHeader(int $mainId, string $returnRefno): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM tblreturn_supplier WHERE lmainid = :main_id AND lrefno = :refno LIMIT 1'
        );
        $stmt->bindValue('main_id', (string) $mainId, PDO::PARAM_STR);
        $stmt->bindValue('refno', $returnRefno, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function getReturnItemById(int $itemId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM tblreturn_supplier_item WHERE lid = :item_id LIMIT 1');
        $stmt->bindValue('item_id', $itemId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function mustGetReturnItem(int $itemId): array
    {
        $item = $this->getReturnItemById($itemId);
        if ($item === null) {
            throw new RuntimeException('Return item not found');
        }

        return $this->mapReturnItem($item);
    }

    /** @return array<int, array<string, mixed>> */
    private function getReturnItemsByRefno(string $returnRefno): array
    {
        $itemsSql = <<<SQL
SELECT
    itm.lid AS id,
    COALESCE(itm.lrefno, '') AS return_refno,
    COALESCE(itm.litemid, '') AS item_id,
    COALESCE(itm.linv_refno, '') AS inv_refno,
    COALESCE(itm.litem_refno, '') AS rr_item_id,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.ldesc, '') AS description,
    CAST(COALESCE(itm.lqty, 0) AS DECIMAL(15,2)) AS qty_returned,
    CAST(COALESCE(itm.lprice, 0) AS DECIMAL(15,2)) AS unit_cost,
    CAST(COALESCE(itm.lqty, 0) * COALESCE(itm.lprice, 0) AS DECIMAL(15,2)) AS total_amount,
    COALESCE(itm.lnote, '') AS remarks,
    COALESCE(itm.lnote, 'Return') AS return_reason
FROM tblreturn_supplier_item itm
WHERE itm.lrefno = :refno
ORDER BY itm.lid ASC
SQL;

        $itemsStmt = $this->db->pdo()->prepare($itemsSql);
        $itemsStmt->bindValue('refno', $returnRefno, PDO::PARAM_STR);
        $itemsStmt->execute();

        return $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function resolveSupplier(string $supplierRefno): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM tblsupplier WHERE lrefno = :refno LIMIT 1');
        $stmt->bindValue('refno', $supplierRefno, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function resolveInventoryItem(string $invRefno, string $itemCode): ?array
    {
        if ($invRefno !== '') {
            $stmt = $this->db->pdo()->prepare('SELECT * FROM tblinventory_item WHERE lsession = :session LIMIT 1');
            $stmt->bindValue('session', $invRefno, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                return $row;
            }
        }

        if ($itemCode !== '') {
            $stmt = $this->db->pdo()->prepare('SELECT * FROM tblinventory_item WHERE litemcode = :item_code LIMIT 1');
            $stmt->bindValue('item_code', $itemCode, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                return $row;
            }
        }

        return null;
    }

    private function resolveSupplierCost(int $supplierId, string $itemSession): float
    {
        if ($supplierId > 0 && $itemSession !== '') {
            $stmt = $this->db->pdo()->prepare(
                'SELECT lcost FROM tblsupplier_cost WHERE lsupplier_id = :supplier_id AND litemsession = :item_session ORDER BY lid DESC LIMIT 1'
            );
            $stmt->execute([
                'supplier_id' => (string) $supplierId,
                'item_session' => $itemSession,
            ]);
            $cost = $stmt->fetchColumn();
            if ($cost !== false && $cost !== null) {
                return (float) $cost;
            }
        }

        $stmt = $this->db->pdo()->prepare('SELECT lcost FROM tblinventory_item WHERE lsession = :session LIMIT 1');
        $stmt->bindValue('session', $itemSession, PDO::PARAM_STR);
        $stmt->execute();
        $cost = $stmt->fetchColumn();

        return (float) ($cost ?: 0.0);
    }

    private function nextReturnNumber(PDO $pdo): string
    {
        $prefix = 'RS' . date('y') . '-';

        $stmt = $pdo->prepare('SELECT lcredit_no FROM tblreturn_supplier WHERE lcredit_no LIKE :prefix ORDER BY lid DESC LIMIT 1');
        $stmt->execute(['prefix' => $prefix . '%']);
        $latest = (string) ($stmt->fetchColumn() ?: '');

        $next = 1;
        if ($latest !== '' && preg_match('/^(?:RS\d{2}-)(\d+)$/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return $prefix . $next;
    }

    private function generateRefno(): string
    {
        return date('YmdHis') . random_int(100000, 999999);
    }

    private function normalizeDate(string $date): string
    {
        $trimmed = trim($date);
        if ($trimmed === '') {
            return date('Y-m-d');
        }

        $timestamp = strtotime($trimmed);
        if ($timestamp === false) {
            throw new RuntimeException('Invalid date value');
        }

        return date('Y-m-d', $timestamp);
    }

    private function normalizeDateTime(string $date): string
    {
        $timestamp = strtotime(trim($date));
        if ($timestamp === false) {
            $timestamp = time();
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /** @param array<string, mixed> $params */
    private function bindParams(\PDOStatement $stmt, array $params, bool $withLimit): void
    {
        foreach ($params as $key => $value) {
            if ($withLimit && in_array($key, ['limit', 'offset', 'month', 'year'], true)) {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }

            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
    }

    /** @param array<string, mixed> $item */
    private function mapReturnItem(array $item): array
    {
        return [
            'id' => (int) ($item['lid'] ?? 0),
            'return_refno' => (string) ($item['lrefno'] ?? ''),
            'item_id' => (string) ($item['litemid'] ?? ''),
            'inv_refno' => (string) ($item['linv_refno'] ?? ''),
            'rr_item_id' => (string) ($item['litem_refno'] ?? ''),
            'item_code' => (string) ($item['litemcode'] ?? ''),
            'part_no' => (string) ($item['lpartno'] ?? ''),
            'description' => (string) ($item['ldesc'] ?? ''),
            'qty_returned' => (float) ($item['lqty'] ?? 0),
            'unit_cost' => (float) ($item['lprice'] ?? 0),
            'total_amount' => (float) (($item['lqty'] ?? 0) * ($item['lprice'] ?? 0)),
            'remarks' => (string) ($item['lnote'] ?? ''),
            'return_reason' => (string) ($item['lnote'] ?? ''),
        ];
    }
}
