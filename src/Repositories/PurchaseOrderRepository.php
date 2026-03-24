<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class PurchaseOrderRepository
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
    public function listPurchaseOrders(
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
            'main_id' => $mainId,
            'month' => $month,
            'year' => $year,
            'limit' => $perPage,
            'offset' => $offset,
        ];
        $where = [
            'po.lmain_id = :main_id',
            'MONTH(po.ldate) = :month',
            'YEAR(po.ldate) = :year',
        ];

        $trimmedStatus = strtolower(trim($status));
        if ($trimmedStatus === 'approved') {
            $trimmedStatus = 'posted';
        }
        if ($trimmedStatus !== '' && $trimmedStatus !== 'all') {
            $params['status'] = $trimmedStatus;
            if ($trimmedStatus === 'posted') {
                $where[] = 'LOWER(COALESCE(po.ltransaction_status, "")) IN ("posted", "approved")';
            } else {
                $where[] = 'LOWER(COALESCE(po.ltransaction_status, "")) = :status';
            }
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $params['search_po'] = '%' . $trimmedSearch . '%';
            $params['search_pr'] = '%' . $trimmedSearch . '%';
            $params['search_supplier'] = '%' . $trimmedSearch . '%';
            $params['search_supplier_code'] = '%' . $trimmedSearch . '%';
            $params['search_item'] = '%' . $trimmedSearch . '%';
            $where[] = <<<SQL
(
    po.lpurchaseno LIKE :search_po
    OR COALESCE(po.lpr_no, '') LIKE :search_pr
    OR COALESCE(po.lsupplier_name, '') LIKE :search_supplier
    OR COALESCE(po.lsupplier_code, '') LIKE :search_supplier_code
    OR EXISTS (
        SELECT 1
        FROM tblpo_itemlist poi_search
        WHERE poi_search.lrefno = po.lrefno
          AND CONCAT_WS(' ', COALESCE(poi_search.lpartno, ''), COALESCE(poi_search.litem_code, ''), COALESCE(poi_search.ldesc, '')) LIKE :search_item
    )
)
SQL;
        }

        $whereSql = implode(' AND ', $where);

        $countSql = <<<SQL
SELECT COUNT(*) AS total
FROM tblpo_list po
WHERE {$whereSql}
SQL;
        $countStmt = $this->db->pdo()->prepare($countSql);
        $this->bindParams($countStmt, $params, false);
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = <<<SQL
SELECT
    po.lid AS id,
    COALESCE(po.lrefno, '') AS refno,
    COALESCE(po.lpurchaseno, '') AS po_number,
    po.ldate AS order_date,
    po.ltime AS order_time,
    CASE
        WHEN LOWER(COALESCE(po.ltransaction_status, 'pending')) IN ('posted', 'approved') THEN 'Posted'
        ELSE COALESCE(po.ltransaction_status, 'Pending')
    END AS status,
    COALESCE(po.lpr_no, '') AS pr_number,
    COALESCE(po.lpr_refno, '') AS pr_refno,
    COALESCE(po.lsupplier, '') AS supplier_id,
    COALESCE(po.lsupplier_name, '') AS supplier_name,
    COALESCE(po.lsupplier_code, '') AS supplier_code,
    COALESCE(po.lreference, '') AS reference,
    COALESCE(po.lterms, '') AS terms,
    COALESCE(po.laddress, '') AS address,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by,
    CAST(COALESCE(agg.item_count, 0) AS SIGNED) AS item_count,
    CAST(COALESCE(agg.total_qty, 0) AS SIGNED) AS total_qty,
    CAST(COALESCE(agg.total_cogs, 0) AS DECIMAL(15,2)) AS total_cogs,
    CAST(COALESCE(agg.received_lines, 0) AS SIGNED) AS received_lines,
    CAST(COALESCE(agg.received_qty, 0) AS SIGNED) AS received_qty,
    agg.eta_from AS first_eta_date,
    agg.eta_to AS last_eta_date
FROM tblpo_list po
LEFT JOIN tblaccount acc
    ON acc.lid = po.luser
LEFT JOIN (
    SELECT
        lrefno,
        COUNT(*) AS item_count,
        SUM(COALESCE(lqty, 0)) AS total_qty,
        SUM(COALESCE(lqty, 0) * CAST(COALESCE(NULLIF(lsup_price, ''), '0') AS DECIMAL(15,2))) AS total_cogs,
        SUM(CASE WHEN COALESCE(lreceiving_refno, '') <> '' THEN 1 ELSE 0 END) AS received_lines,
        SUM(COALESCE(lreceiving_qty, 0)) AS received_qty,
        MIN(leta_date) AS eta_from,
        MAX(leta_date) AS eta_to
    FROM tblpo_itemlist
    GROUP BY lrefno
) agg
    ON agg.lrefno = po.lrefno
WHERE {$whereSql}
ORDER BY po.lid DESC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $params, true);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
                'filters' => [
                    'month' => $month,
                    'year' => $year,
                    'status' => $trimmedStatus === '' ? 'all' : $trimmedStatus,
                    'search' => $trimmedSearch,
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSuppliers(int $mainId): array
    {
        $sql = <<<SQL
SELECT
    CAST(s.lid AS CHAR) AS id,
    COALESCE(s.lcode, '') AS code,
    COALESCE(s.lname, '') AS name,
    COALESCE(s.laddress, '') AS address,
    CAST(COALESCE(s.lstatus, 1) AS SIGNED) AS status
FROM tblsupplier s
WHERE s.lmain_id = :main_id
  AND COALESCE(s.lstatus, 1) = 1
ORDER BY s.lname ASC, s.lid ASC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['main_id' => $mainId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPurchaseOrder(int $mainId, string $purchaseRefno): ?array
    {
        $orderSql = <<<SQL
SELECT
    po.lid AS id,
    COALESCE(po.lrefno, '') AS refno,
    COALESCE(po.lpurchaseno, '') AS po_number,
    po.ldate AS order_date,
    po.ltime AS order_time,
    CASE
        WHEN LOWER(COALESCE(po.ltransaction_status, 'pending')) IN ('posted', 'approved') THEN 'Posted'
        ELSE COALESCE(po.ltransaction_status, 'Pending')
    END AS status,
    COALESCE(po.lpr_no, '') AS pr_number,
    COALESCE(po.lpr_refno, '') AS pr_refno,
    COALESCE(po.lsupplier, '') AS supplier_id,
    COALESCE(po.lsupplier_name, '') AS supplier_name,
    COALESCE(po.lsupplier_code, '') AS supplier_code,
    COALESCE(po.lreference, '') AS reference,
    COALESCE(po.lterms, '') AS terms,
    COALESCE(po.laddress, '') AS address,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by
FROM tblpo_list po
LEFT JOIN tblaccount acc
    ON acc.lid = po.luser
WHERE po.lmain_id = :main_id
  AND po.lrefno = :refno
LIMIT 1
SQL;
        $orderStmt = $this->db->pdo()->prepare($orderSql);
        $orderStmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $orderStmt->bindValue('refno', $purchaseRefno, PDO::PARAM_STR);
        $orderStmt->execute();
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if ($order === false) {
            return null;
        }

        $itemsSql = <<<SQL
SELECT
    itm.lid AS id,
    COALESCE(itm.lrefno, '') AS po_refno,
    CAST(COALESCE(itm.litemid, 0) AS SIGNED) AS product_id,
    COALESCE(itm.litem_refno, '') AS product_session,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.lopn_number, '') AS original_part_no,
    COALESCE(itm.litem_code, '') AS item_code,
    COALESCE(itm.lbrand, inv.lbrand, '') AS brand,
    COALESCE(itm.ldesc, '') AS description,
    CAST(COALESCE(itm.lqty, 0) AS SIGNED) AS qty,
    CAST(COALESCE(NULLIF(itm.lsup_price, ''), '0') AS DECIMAL(15,2)) AS supplier_price,
    CAST(COALESCE(itm.lqty, 0) * CAST(COALESCE(NULLIF(itm.lsup_price, ''), '0') AS DECIMAL(15,2)) AS DECIMAL(15,2)) AS line_total,
    itm.leta_date AS eta_date,
    COALESCE(itm.lsupp_id, '') AS supplier_id,
    COALESCE(itm.lsupp_code, '') AS supplier_code,
    COALESCE(itm.lsupp_name, '') AS supplier_name,
    COALESCE(itm.lreceiving_refno, '') AS receiving_refno,
    COALESCE(itm.lreceiving_no, '') AS receiving_number,
    CAST(COALESCE(itm.lreceiving_qty, 0) AS SIGNED) AS receiving_qty
FROM tblpo_itemlist itm
LEFT JOIN tblinventory_item inv
    ON inv.lid = itm.litemid
WHERE itm.lrefno = :refno
ORDER BY itm.lid ASC
SQL;
        $itemsStmt = $this->db->pdo()->prepare($itemsSql);
        $itemsStmt->bindValue('refno', $purchaseRefno, PDO::PARAM_STR);
        $itemsStmt->execute();
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $receivingSql = <<<SQL
SELECT
    COALESCE(rr.lrefno, '') AS refno,
    COALESCE(rr.lpurchaseno, '') AS rr_number,
    rr.ldate AS rr_date,
    COALESCE(rr.ltransaction_status, 'Pending') AS status,
    COALESCE(rr.lsupplier_name, '') AS supplier_name,
    COALESCE(rr.leta_date, '') AS eta_date
FROM tblpurchase_order rr
WHERE rr.lmain_id = :main_id
  AND rr.lpo_refno = :refno
ORDER BY rr.lid DESC
SQL;
        $receivingStmt = $this->db->pdo()->prepare($receivingSql);
        $receivingStmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $receivingStmt->bindValue('refno', $purchaseRefno, PDO::PARAM_STR);
        $receivingStmt->execute();
        $receivingReports = $receivingStmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'item_count' => count($items),
            'total_qty' => 0,
            'total_cogs' => 0.0,
            'received_lines' => 0,
            'received_qty' => 0,
        ];
        foreach ($items as $item) {
            $qty = (int) ($item['qty'] ?? 0);
            $lineTotal = (float) ($item['line_total'] ?? 0);
            $receivingQty = (int) ($item['receiving_qty'] ?? 0);
            $summary['total_qty'] += $qty;
            $summary['total_cogs'] += $lineTotal;
            $summary['received_qty'] += $receivingQty;
            if ((string) ($item['receiving_refno'] ?? '') !== '') {
                $summary['received_lines']++;
            }
        }

        return [
            'order' => $order,
            'items' => $items,
            'receiving_reports' => $receivingReports,
            'summary' => $summary,
        ];
    }

    public function createPurchaseOrder(int $mainId, int $userId, array $payload): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $refno = (string) ($payload['refno'] ?? ($this->generateRefno()));
            $poNumber = trim((string) ($payload['po_number'] ?? ''));
            if ($poNumber === '') {
                $poNumber = $this->nextPurchaseOrderNumber($pdo);
            }

            $supplier = $this->getSupplierById((string) ($payload['supplier_id'] ?? ''));
            $orderDate = $this->normalizeDate((string) ($payload['order_date'] ?? date('Y-m-d')));
            $status = $this->normalizeStatus((string) ($payload['status'] ?? 'Pending'));

            $stmt = $pdo->prepare(
                'INSERT INTO tblpo_list
                (lpurchaseno, ldate, ltime, lmain_id, luser, lrefno, ltransaction_status, lsupplier, lsupplier_name, lsupplier_code, lreference, lterms, laddress, lpr_no, lpr_refno)
                VALUES
                (:po_number, :order_date, CURRENT_TIME(), :main_id, :user_id, :refno, :status, :supplier_id, :supplier_name, :supplier_code, :reference, :terms, :address, :pr_no, :pr_refno)'
            );
            $stmt->execute([
                'po_number' => $poNumber,
                'order_date' => $orderDate,
                'main_id' => $mainId,
                'user_id' => $userId,
                'refno' => $refno,
                'status' => $status,
                'supplier_id' => $supplier['id'],
                'supplier_name' => $supplier['name'],
                'supplier_code' => $supplier['code'],
                'reference' => (string) ($payload['reference'] ?? ''),
                'terms' => (string) ($payload['terms'] ?? ''),
                'address' => (string) ($payload['address'] ?? ''),
                'pr_no' => (string) ($payload['pr_number'] ?? ''),
                'pr_refno' => (string) ($payload['pr_refno'] ?? ''),
            ]);

            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $this->insertPoItem($pdo, $mainId, $userId, $refno, $item);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $record = $this->getPurchaseOrder($mainId, $refno);
        if ($record === null) {
            throw new RuntimeException('Failed to create purchase order');
        }
        return $record;
    }

    public function updatePurchaseOrder(int $mainId, string $purchaseRefno, array $payload): ?array
    {
        $existing = $this->getPurchaseOrder($mainId, $purchaseRefno);
        if ($existing === null) {
            return null;
        }

        $order = $existing['order'];
        $supplierId = array_key_exists('supplier_id', $payload)
            ? (string) ($payload['supplier_id'] ?? '')
            : (string) ($order['supplier_id'] ?? '');
        $supplier = $this->getSupplierById($supplierId);

        $sql = <<<SQL
UPDATE tblpo_list
SET
    ldate = :order_date,
    ltransaction_status = :status,
    lsupplier = :supplier_id,
    lsupplier_name = :supplier_name,
    lsupplier_code = :supplier_code,
    lreference = :reference,
    lterms = :terms,
    laddress = :address,
    lpr_no = :pr_no,
    lpr_refno = :pr_refno
WHERE lmain_id = :main_id
  AND lrefno = :refno
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'order_date' => $this->normalizeDate((string) ($payload['order_date'] ?? $order['order_date'] ?? date('Y-m-d'))),
            'status' => $this->normalizeStatus((string) ($payload['status'] ?? $order['status'] ?? 'Pending')),
            'supplier_id' => $supplier['id'],
            'supplier_name' => $supplier['name'],
            'supplier_code' => $supplier['code'],
            'reference' => (string) ($payload['reference'] ?? $order['reference'] ?? ''),
            'terms' => (string) ($payload['terms'] ?? $order['terms'] ?? ''),
            'address' => (string) ($payload['address'] ?? $order['address'] ?? ''),
            'pr_no' => (string) ($payload['pr_number'] ?? $order['pr_number'] ?? ''),
            'pr_refno' => (string) ($payload['pr_refno'] ?? $order['pr_refno'] ?? ''),
            'main_id' => $mainId,
            'refno' => $purchaseRefno,
        ]);

        return $this->getPurchaseOrder($mainId, $purchaseRefno);
    }

    public function deletePurchaseOrder(int $mainId, string $purchaseRefno): bool
    {
        $exists = $this->getPurchaseOrder($mainId, $purchaseRefno);
        if ($exists === null) {
            return false;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $deleteItems = $pdo->prepare('DELETE FROM tblpo_itemlist WHERE lrefno = :refno');
            $deleteItems->execute(['refno' => $purchaseRefno]);

            $deleteHeader = $pdo->prepare('DELETE FROM tblpo_list WHERE lmain_id = :main_id AND lrefno = :refno');
            $deleteHeader->execute([
                'main_id' => $mainId,
                'refno' => $purchaseRefno,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return true;
    }

    public function addPurchaseOrderItem(int $mainId, int $userId, string $purchaseRefno, array $payload): array
    {
        $header = $this->getPurchaseOrder($mainId, $purchaseRefno);
        if ($header === null) {
            throw new RuntimeException('Purchase order not found');
        }

        $this->insertPoItem($this->db->pdo(), $mainId, $userId, $purchaseRefno, $payload);
        $po = $this->getPurchaseOrder($mainId, $purchaseRefno);
        if ($po === null) {
            throw new RuntimeException('Purchase order not found after item insert');
        }
        $items = $po['items'] ?? [];
        return end($items) ?: [];
    }

    public function updatePurchaseOrderItem(int $mainId, int $itemId, array $payload): ?array
    {
        $existing = $this->getPurchaseOrderItem($mainId, $itemId);
        if ($existing === null) {
            return null;
        }

        $supplierId = array_key_exists('supplier_id', $payload)
            ? (string) ($payload['supplier_id'] ?? '')
            : (string) ($existing['supplier_id'] ?? '');
        $supplier = $this->getSupplierById($supplierId);

        $qty = (int) ($payload['qty'] ?? $existing['qty'] ?? 0);
        if ($qty < 0) {
            $qty = 0;
        }
        $price = (float) ($payload['supplier_price'] ?? $existing['supplier_price'] ?? 0);

        $sql = <<<SQL
UPDATE tblpo_itemlist
SET
    lqty = :qty,
    leta_date = :eta_date,
    lsup_price = :supplier_price,
    lsupp_id = :supplier_id,
    lsupp_code = :supplier_code,
    lsupp_name = :supplier_name
WHERE lid = :item_id
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'qty' => $qty,
            'eta_date' => $this->normalizeDateOrNull((string) ($payload['eta_date'] ?? (string) ($existing['eta_date'] ?? ''))),
            'supplier_price' => number_format($price, 2, '.', ''),
            'supplier_id' => $supplier['id'],
            'supplier_code' => $supplier['code'],
            'supplier_name' => $supplier['name'],
            'item_id' => $itemId,
        ]);

        return $this->getPurchaseOrderItem($mainId, $itemId);
    }

    public function deletePurchaseOrderItem(int $mainId, int $itemId): bool
    {
        $existing = $this->getPurchaseOrderItem($mainId, $itemId);
        if ($existing === null) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare('DELETE FROM tblpo_itemlist WHERE lid = :item_id');
        $stmt->execute(['item_id' => $itemId]);

        return true;
    }

    private function bindParams(\PDOStatement $stmt, array $params, bool $withPagination): void
    {
        foreach ($params as $key => $value) {
            if (!$withPagination && ($key === 'limit' || $key === 'offset')) {
                continue;
            }
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    private function insertPoItem(PDO $pdo, int $mainId, int $userId, string $purchaseRefno, array $payload): void
    {
        $item = $this->resolveInventoryItem($mainId, $payload);
        $supplier = $this->getSupplierById((string) ($payload['supplier_id'] ?? ''));

        $qty = (int) ($payload['qty'] ?? 0);
        if ($qty <= 0) {
            throw new RuntimeException('qty must be greater than 0');
        }
        $supplierPrice = array_key_exists('supplier_price', $payload)
            ? (float) $payload['supplier_price']
            : (float) ($item['lcog'] ?? 0);
        $etaDate = $this->normalizeDateOrNull((string) ($payload['eta_date'] ?? ''));

        $stmt = $pdo->prepare(
            'INSERT INTO tblpo_itemlist
            (lrefno, litemid, ldesc, lqty, luser, lpartno, litem_code, litem_refno, lopn_number, lsup_price, lbrand, lsupp_id, lsupp_code, lsupp_name, leta_date)
            VALUES
            (:refno, :item_id, :description, :qty, :user_id, :part_no, :item_code, :item_refno, :original_part_no, :supplier_price, :brand, :supplier_id, :supplier_code, :supplier_name, :eta_date)'
        );
        $stmt->execute([
            'refno' => $purchaseRefno,
            'item_id' => (int) ($item['lid'] ?? 0),
            'description' => (string) ($item['ldescription'] ?? ''),
            'qty' => $qty,
            'user_id' => $userId,
            'part_no' => (string) ($item['lpartno'] ?? ''),
            'item_code' => (string) ($item['litemcode'] ?? ''),
            'item_refno' => (string) ($item['lsession'] ?? ''),
            'original_part_no' => (string) ($item['lopn_number'] ?? ''),
            'supplier_price' => number_format($supplierPrice, 2, '.', ''),
            'brand' => (string) ($item['lbrand'] ?? ''),
            'supplier_id' => $supplier['id'],
            'supplier_code' => $supplier['code'],
            'supplier_name' => $supplier['name'],
            'eta_date' => $etaDate,
        ]);
    }

    private function resolveInventoryItem(int $mainId, array $payload): array
    {
        $productSession = trim((string) ($payload['product_session'] ?? ''));
        $productId = (int) ($payload['product_id'] ?? 0);

        if ($productSession === '' && $productId <= 0) {
            throw new RuntimeException('product_session or product_id is required');
        }

        if ($productSession !== '') {
            $stmt = $this->db->pdo()->prepare(
                'SELECT lid, lsession, lpartno, ldescription, litemcode, lopn_number, lbrand, lcog
                 FROM tblinventory_item
                 WHERE lmain_id = :main_id AND lsession = :session
                 LIMIT 1'
            );
            $stmt->execute([
                'main_id' => $mainId,
                'session' => $productSession,
            ]);
        } else {
            $stmt = $this->db->pdo()->prepare(
                'SELECT lid, lsession, lpartno, ldescription, litemcode, lopn_number, lbrand, lcog
                 FROM tblinventory_item
                 WHERE lmain_id = :main_id AND lid = :id
                 LIMIT 1'
            );
            $stmt->execute([
                'main_id' => $mainId,
                'id' => $productId,
            ]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Product not found');
        }
        return $row;
    }

    /**
     * @return array{id: string, code: string, name: string}
     */
    private function getSupplierById(string $supplierId): array
    {
        $supplierId = trim($supplierId);
        if ($supplierId === '') {
            return ['id' => '', 'code' => '', 'name' => ''];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT lid, lcode, lname FROM tblsupplier WHERE lid = :id LIMIT 1'
        );
        $stmt->execute(['id' => $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Supplier not found');
        }

        return [
            'id' => (string) ($row['lid'] ?? ''),
            'code' => (string) ($row['lcode'] ?? ''),
            'name' => (string) ($row['lname'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPurchaseOrderItem(int $mainId, int $itemId): ?array
    {
        $sql = <<<SQL
SELECT
    itm.lid AS id,
    COALESCE(itm.lrefno, '') AS po_refno,
    CAST(COALESCE(itm.litemid, 0) AS SIGNED) AS product_id,
    COALESCE(itm.litem_refno, '') AS product_session,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.lopn_number, '') AS original_part_no,
    COALESCE(itm.litem_code, '') AS item_code,
    COALESCE(itm.lbrand, inv.lbrand, '') AS brand,
    COALESCE(itm.ldesc, '') AS description,
    CAST(COALESCE(itm.lqty, 0) AS SIGNED) AS qty,
    CAST(COALESCE(NULLIF(itm.lsup_price, ''), '0') AS DECIMAL(15,2)) AS supplier_price,
    CAST(COALESCE(itm.lqty, 0) * CAST(COALESCE(NULLIF(itm.lsup_price, ''), '0') AS DECIMAL(15,2)) AS DECIMAL(15,2)) AS line_total,
    itm.leta_date AS eta_date,
    COALESCE(itm.lsupp_id, '') AS supplier_id,
    COALESCE(itm.lsupp_code, '') AS supplier_code,
    COALESCE(itm.lsupp_name, '') AS supplier_name,
    COALESCE(itm.lreceiving_refno, '') AS receiving_refno,
    COALESCE(itm.lreceiving_no, '') AS receiving_number,
    CAST(COALESCE(itm.lreceiving_qty, 0) AS SIGNED) AS receiving_qty
FROM tblpo_itemlist itm
INNER JOIN tblpo_list po
    ON po.lrefno = itm.lrefno
LEFT JOIN tblinventory_item inv
    ON inv.lid = itm.litemid
WHERE po.lmain_id = :main_id
  AND itm.lid = :item_id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => $mainId,
            'item_id' => $itemId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function nextPurchaseOrderNumber(PDO $pdo): string
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(lmax_no), 0) + 1 AS next_no
             FROM tblnumber_generator
             WHERE ltransaction_type = :type'
        );
        $stmt->execute(['type' => 'Purchase Order']);
        $next = (int) ($stmt->fetchColumn() ?: 1);

        $insert = $pdo->prepare(
            'INSERT INTO tblnumber_generator (ltransaction_type, lmax_no)
             VALUES (:type, :max_no)'
        );
        $insert->execute([
            'type' => 'Purchase Order',
            'max_no' => $next,
        ]);

        return 'PO-' . date('y') . str_pad((string) $next, 2, '0', STR_PAD_LEFT);
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return date('Y-m-d');
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return date('Y-m-d');
        }
        return date('Y-m-d', $ts);
    }

    private function normalizeDateOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d', $ts);
    }

    private function generateRefno(): string
    {
        return date('YmdHis') . (string) random_int(1000, 9999999);
    }

    private function normalizeStatus(string $status): string
    {
        $normalized = strtolower(trim($status));

        return match ($normalized) {
            '', 'draft' => 'Pending',
            'approved', 'posted' => 'Posted',
            'partial delivery' => 'Partial Delivery',
            'cancelled' => 'Cancelled',
            default => trim($status),
        };
    }
}
