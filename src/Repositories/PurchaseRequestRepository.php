<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class PurchaseRequestRepository
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
    public function listPurchaseRequests(
        int $mainId,
        string $status = 'all',
        string $search = '',
        int $page = 1,
        int $perPage = 100,
        ?int $month = null,
        ?int $year = null
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $params = [
            'limit' => $perPage,
            'offset' => $offset,
        ];
        $where = ['1=1'];

        if ($month !== null && $year !== null) {
            $params['month'] = $month;
            $params['year'] = $year;
            $where[] = 'MONTH(pr.ldatetime) = :month';
            $where[] = 'YEAR(pr.ldatetime) = :year';
        } elseif ($year !== null) {
            $params['year'] = $year;
            $where[] = 'YEAR(pr.ldatetime) = :year';
        }

        $trimmedStatus = strtolower(trim($status));
        if ($trimmedStatus !== '' && $trimmedStatus !== 'all') {
            $params['status'] = $trimmedStatus;
            $where[] = 'LOWER(
                CASE
                    WHEN LOWER(COALESCE(pr.lstatus, "")) = "cancelled" THEN "Cancelled"
                    WHEN LOWER(COALESCE(pr.lstatus, "")) = "submitted" THEN "Submitted"
                    WHEN LOWER(COALESCE(pr.lapproval, "")) = "approved" THEN "Approved"
                    WHEN LOWER(COALESCE(pr.lstatus, "")) = "draft" THEN "Draft"
                    ELSE "Pending"
                END
            ) = :status';
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $params['search_pr'] = '%' . $trimmedSearch . '%';
            $params['search_notes'] = '%' . $trimmedSearch . '%';
            $params['search_item'] = '%' . $trimmedSearch . '%';
            $where[] = <<<SQL
(
    COALESCE(pr.lprno, '') LIKE :search_pr
    OR COALESCE(pr.lremark, '') LIKE :search_notes
    OR EXISTS (
        SELECT 1
        FROM tblpr_item pri_search
        WHERE pri_search.lrefno = pr.lrefno
          AND CONCAT_WS(' ', COALESCE(pri_search.lpart_no, ''), COALESCE(pri_search.litem_code, ''), COALESCE(pri_search.ldesc, '')) LIKE :search_item
    )
)
SQL;
        }

        $whereSql = implode(' AND ', $where);

        $countSql = <<<SQL
SELECT COUNT(*) AS total
FROM tblpr_list pr
WHERE {$whereSql}
SQL;
        $countStmt = $this->db->pdo()->prepare($countSql);
        $this->bindParams($countStmt, $params, false);
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = <<<SQL
SELECT
    pr.lid AS id,
    COALESCE(pr.lrefno, '') AS refno,
    COALESCE(pr.lprno, '') AS pr_number,
    pr.ldatetime AS request_datetime,
    DATE(pr.ldatetime) AS request_date,
    COALESCE(pr.lremark, '') AS notes,
    COALESCE(pr.lapproval, 'Pending') AS approval_status,
    COALESCE(pr.lstatus, 'Pending') AS status_raw,
    CASE
        WHEN LOWER(COALESCE(pr.lstatus, "")) = "cancelled" THEN "Cancelled"
        WHEN LOWER(COALESCE(pr.lstatus, "")) = "submitted" THEN "Submitted"
        WHEN LOWER(COALESCE(pr.lapproval, "")) = "approved" THEN "Approved"
        WHEN LOWER(COALESCE(pr.lstatus, "")) = "draft" THEN "Draft"
        ELSE "Pending"
    END AS status,
    CAST(COALESCE(pr.luser, 0) AS UNSIGNED) AS created_by,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by_name,
    CAST(COALESCE(agg.item_count, 0) AS SIGNED) AS item_count,
    CAST(COALESCE(agg.total_qty, 0) AS SIGNED) AS total_qty,
    CAST(COALESCE(agg.total_cost, 0) AS DECIMAL(15,2)) AS total_cost
FROM tblpr_list pr
LEFT JOIN tblaccount acc
    ON acc.lid = pr.luser
LEFT JOIN (
    SELECT
        lrefno,
        COUNT(*) AS item_count,
        SUM(CAST(COALESCE(NULLIF(lqty, ''), '0') AS DECIMAL(15,2))) AS total_qty,
        SUM(
            CAST(COALESCE(NULLIF(lqty, ''), '0') AS DECIMAL(15,2))
            * CAST(COALESCE(NULLIF(lcost, ''), '0') AS DECIMAL(15,2))
        ) AS total_cost
    FROM tblpr_item
    GROUP BY lrefno
) agg
    ON agg.lrefno = pr.lrefno
WHERE {$whereSql}
ORDER BY pr.lid DESC
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
                    'status' => $trimmedStatus === '' ? 'all' : $trimmedStatus,
                    'search' => $trimmedSearch,
                    'month' => $month,
                    'year' => $year,
                    'main_id' => $mainId,
                ],
            ],
        ];
    }

    public function nextPurchaseRequestNumber(): string
    {
        $seq = $this->nextSequence('Purchase Request');
        return sprintf('PR-%s%s', date('y'), str_pad((string) $seq, 2, '0', STR_PAD_LEFT));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPurchaseRequest(int $mainId, string $prRefno): ?array
    {
        $headerSql = <<<SQL
SELECT
    pr.lid AS id,
    COALESCE(pr.lrefno, '') AS refno,
    COALESCE(pr.lprno, '') AS pr_number,
    pr.ldatetime AS request_datetime,
    DATE(pr.ldatetime) AS request_date,
    COALESCE(pr.lremark, '') AS notes,
    COALESCE(pr.lapproval, 'Pending') AS approval_status,
    COALESCE(pr.lstatus, 'Pending') AS status_raw,
    CASE
        WHEN LOWER(COALESCE(pr.lstatus, "")) = "cancelled" THEN "Cancelled"
        WHEN LOWER(COALESCE(pr.lstatus, "")) = "submitted" THEN "Submitted"
        WHEN LOWER(COALESCE(pr.lapproval, "")) = "approved" THEN "Approved"
        WHEN LOWER(COALESCE(pr.lstatus, "")) = "draft" THEN "Draft"
        ELSE "Pending"
    END AS status,
    CAST(COALESCE(pr.luser, 0) AS UNSIGNED) AS created_by,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by_name
FROM tblpr_list pr
LEFT JOIN tblaccount acc
    ON acc.lid = pr.luser
WHERE pr.lrefno = :refno
LIMIT 1
SQL;
        $headerStmt = $this->db->pdo()->prepare($headerSql);
        $headerStmt->bindValue('refno', $prRefno, PDO::PARAM_STR);
        $headerStmt->execute();
        $header = $headerStmt->fetch(PDO::FETCH_ASSOC);
        if ($header === false) {
            return null;
        }

        $itemsSql = <<<SQL
SELECT
    itm.lid AS id,
    COALESCE(itm.lrefno, '') AS pr_refno,
    COALESCE(itm.litem_refno, '') AS item_id,
    COALESCE(itm.litem_code, '') AS item_code,
    COALESCE(itm.lpart_no, '') AS part_number,
    COALESCE(itm.ldesc, '') AS description,
    CAST(COALESCE(NULLIF(itm.lqty, ''), '0') AS DECIMAL(15,2)) AS quantity,
    CAST(COALESCE(NULLIF(itm.lcost, ''), '0') AS DECIMAL(15,2)) AS unit_cost,
    COALESCE(itm.lsupp_id, '') AS supplier_id,
    COALESCE(itm.lsupp_name, '') AS supplier_name,
    COALESCE(itm.lsupp_code, '') AS supplier_code,
    COALESCE(itm.lpo_refno, '') AS po_refno,
    COALESCE(itm.lpo_no, '') AS po_number,
    COALESCE(itm.lremark, '') AS notes,
    CASE
        WHEN itm.lremark REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN itm.lremark
        ELSE NULL
    END AS eta_date
FROM tblpr_item itm
WHERE itm.lrefno = :refno
ORDER BY itm.lid ASC
SQL;
        $itemsStmt = $this->db->pdo()->prepare($itemsSql);
        $itemsStmt->bindValue('refno', $prRefno, PDO::PARAM_STR);
        $itemsStmt->execute();
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'item_count' => count($items),
            'total_qty' => 0.0,
            'total_cost' => 0.0,
        ];
        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 0);
            $cost = (float) ($item['unit_cost'] ?? 0);
            $summary['total_qty'] += $qty;
            $summary['total_cost'] += ($qty * $cost);
        }

        return [
            'request' => $header,
            'items' => $items,
            'summary' => $summary,
            'context' => [
                'main_id' => $mainId,
            ],
        ];
    }

    public function createPurchaseRequest(int $mainId, int $userId, array $payload): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $refno = (string) ($payload['refno'] ?? $this->generateRefno());
            $prNumber = trim((string) ($payload['pr_number'] ?? ''));
            if ($prNumber === '') {
                $prNumber = sprintf('PR-%s%s', date('y'), str_pad((string) $this->nextSequence('Purchase Request', $pdo), 2, '0', STR_PAD_LEFT));
            }

            $requestDate = $this->normalizeDate((string) ($payload['request_date'] ?? date('Y-m-d')));
            $status = trim((string) ($payload['status'] ?? 'Pending'));
            if ($status === '') {
                $status = 'Pending';
            }
            $approval = trim((string) ($payload['approval_status'] ?? 'Pending'));
            if ($approval === '') {
                $approval = 'Pending';
            }
            $reference = trim((string) ($payload['reference_no'] ?? ''));
            $notes = trim((string) ($payload['notes'] ?? ''));
            if ($reference !== '') {
                $notes = trim($notes . ($notes !== '' ? ' ' : '') . '[Ref:' . $reference . ']');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO tblpr_list (lrefno, lprno, ldatetime, luser, lstatus, lremark, lapproval)
                 VALUES (:refno, :pr_number, :request_datetime, :user_id, :status, :notes, :approval)'
            );
            $stmt->execute([
                'refno' => $refno,
                'pr_number' => $prNumber,
                'request_datetime' => $requestDate . ' ' . date('H:i:s'),
                'user_id' => (string) $userId,
                'status' => $status,
                'notes' => $notes,
                'approval' => $approval,
            ]);

            $this->incrementSequence('Purchase Request', $pdo);

            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $this->insertPrItem($pdo, $mainId, $refno, $item);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $record = $this->getPurchaseRequest($mainId, $refno);
        if ($record === null) {
            throw new RuntimeException('Failed to create purchase request');
        }
        return $record;
    }

    public function updatePurchaseRequest(int $mainId, string $prRefno, array $payload): ?array
    {
        $existing = $this->getPurchaseRequest($mainId, $prRefno);
        if ($existing === null) {
            return null;
        }

        $request = $existing['request'];
        $statusRaw = (string) ($request['status_raw'] ?? 'Pending');
        $approvalRaw = (string) ($request['approval_status'] ?? 'Pending');
        if (array_key_exists('status', $payload)) {
            $newStatus = trim((string) ($payload['status'] ?? ''));
            if ($newStatus !== '') {
                if (strcasecmp($newStatus, 'Approved') === 0) {
                    $approvalRaw = 'Approved';
                } else {
                    $statusRaw = $newStatus;
                }
            }
        }

        $notes = (string) ($payload['notes'] ?? $request['notes'] ?? '');
        $reference = trim((string) ($payload['reference_no'] ?? ''));
        if ($reference !== '' && strpos($notes, '[Ref:') === false) {
            $notes = trim($notes . ($notes !== '' ? ' ' : '') . '[Ref:' . $reference . ']');
        }

        $sql = <<<SQL
UPDATE tblpr_list
SET
    lprno = :pr_number,
    ldatetime = :request_datetime,
    lstatus = :status,
    lapproval = :approval,
    lremark = :notes
WHERE lrefno = :refno
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'pr_number' => (string) ($payload['pr_number'] ?? $request['pr_number'] ?? ''),
            'request_datetime' => $this->normalizeDate((string) ($payload['request_date'] ?? $request['request_date'] ?? date('Y-m-d'))) . ' ' . date('H:i:s'),
            'status' => $statusRaw,
            'approval' => $approvalRaw,
            'notes' => $notes,
            'refno' => $prRefno,
        ]);

        return $this->getPurchaseRequest($mainId, $prRefno);
    }

    public function deletePurchaseRequest(int $mainId, string $prRefno): bool
    {
        $exists = $this->getPurchaseRequest($mainId, $prRefno);
        if ($exists === null) {
            return false;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $deleteItems = $pdo->prepare('DELETE FROM tblpr_item WHERE lrefno = :refno');
            $deleteItems->execute(['refno' => $prRefno]);

            $deleteHeader = $pdo->prepare('DELETE FROM tblpr_list WHERE lrefno = :refno');
            $deleteHeader->execute(['refno' => $prRefno]);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function addPurchaseRequestItem(int $mainId, int $userId, string $prRefno, array $payload): array
    {
        $header = $this->getPurchaseRequest($mainId, $prRefno);
        if ($header === null) {
            throw new RuntimeException('Purchase request not found');
        }

        $pdo = $this->db->pdo();
        $this->insertPrItem($pdo, $mainId, $prRefno, $payload);

        $sql = <<<SQL
SELECT
    itm.lid AS id,
    COALESCE(itm.lrefno, '') AS pr_refno,
    COALESCE(itm.litem_refno, '') AS item_id,
    COALESCE(itm.litem_code, '') AS item_code,
    COALESCE(itm.lpart_no, '') AS part_number,
    COALESCE(itm.ldesc, '') AS description,
    CAST(COALESCE(NULLIF(itm.lqty, ''), '0') AS DECIMAL(15,2)) AS quantity,
    CAST(COALESCE(NULLIF(itm.lcost, ''), '0') AS DECIMAL(15,2)) AS unit_cost,
    COALESCE(itm.lsupp_id, '') AS supplier_id,
    COALESCE(itm.lsupp_name, '') AS supplier_name,
    COALESCE(itm.lsupp_code, '') AS supplier_code,
    COALESCE(itm.lpo_refno, '') AS po_refno,
    COALESCE(itm.lpo_no, '') AS po_number,
    COALESCE(itm.lremark, '') AS notes,
    CASE
        WHEN itm.lremark REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN itm.lremark
        ELSE NULL
    END AS eta_date
FROM tblpr_item itm
WHERE itm.lid = LAST_INSERT_ID()
LIMIT 1
SQL;
        $stmt = $pdo->query($sql);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Failed to add purchase request item');
        }
        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updatePurchaseRequestItem(int $mainId, int $itemId, array $payload): ?array
    {
        $item = $this->getPurchaseRequestItem($itemId);
        if ($item === null) {
            return null;
        }

        $supplier = null;
        if (array_key_exists('supplier_id', $payload)) {
            $supplierId = trim((string) ($payload['supplier_id'] ?? ''));
            $supplier = $supplierId !== '' ? $this->getSupplierById($supplierId) : [
                'id' => '',
                'name' => '',
                'code' => '',
            ];
        }

        $sql = <<<SQL
UPDATE tblpr_item
SET
    lqty = :qty,
    lcost = :cost,
    lremark = :remark,
    lsupp_id = :supplier_id,
    lsupp_name = :supplier_name,
    lsupp_code = :supplier_code
WHERE lid = :item_id
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'qty' => array_key_exists('quantity', $payload)
                ? (string) max(0, (float) $payload['quantity'])
                : (string) ($item['quantity'] ?? '0'),
            'cost' => array_key_exists('unit_cost', $payload)
                ? (string) max(0, (float) $payload['unit_cost'])
                : (string) ($item['unit_cost'] ?? '0'),
            'remark' => (string) ($payload['eta_date'] ?? $payload['notes'] ?? $item['notes'] ?? ''),
            'supplier_id' => $supplier['id'] ?? (string) ($item['supplier_id'] ?? ''),
            'supplier_name' => $supplier['name'] ?? (string) ($item['supplier_name'] ?? ''),
            'supplier_code' => $supplier['code'] ?? (string) ($item['supplier_code'] ?? ''),
            'item_id' => $itemId,
        ]);

        return $this->getPurchaseRequestItem($itemId);
    }

    public function deletePurchaseRequestItem(int $mainId, int $itemId): bool
    {
        $item = $this->getPurchaseRequestItem($itemId);
        if ($item === null) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('DELETE FROM tblpr_item WHERE lid = :item_id');
        $stmt->execute(['item_id' => $itemId]);
        return true;
    }

    public function applyAction(int $mainId, int $userId, string $prRefno, string $action, array $payload): array
    {
        $normalized = strtolower(trim($action));
        if (!in_array($normalized, ['approve', 'cancel', 'submit', 'convert-po'], true)) {
            throw new RuntimeException('Unsupported action: ' . $action);
        }

        if ($normalized === 'approve') {
            $stmt = $this->db->pdo()->prepare('UPDATE tblpr_list SET lapproval = "Approved" WHERE lrefno = :refno');
            $stmt->execute(['refno' => $prRefno]);
            $record = $this->getPurchaseRequest($mainId, $prRefno);
            if ($record === null) {
                throw new RuntimeException('Purchase request not found');
            }
            return $record;
        }

        if ($normalized === 'cancel') {
            $stmt = $this->db->pdo()->prepare('UPDATE tblpr_list SET lstatus = "Cancelled" WHERE lrefno = :refno');
            $stmt->execute(['refno' => $prRefno]);
            $record = $this->getPurchaseRequest($mainId, $prRefno);
            if ($record === null) {
                throw new RuntimeException('Purchase request not found');
            }
            return $record;
        }

        if ($normalized === 'submit') {
            $stmt = $this->db->pdo()->prepare('UPDATE tblpr_list SET lstatus = "Pending" WHERE lrefno = :refno');
            $stmt->execute(['refno' => $prRefno]);
            $record = $this->getPurchaseRequest($mainId, $prRefno);
            if ($record === null) {
                throw new RuntimeException('Purchase request not found');
            }
            return $record;
        }

        return $this->convertPurchaseRequestToPo($mainId, $userId, $prRefno, $payload);
    }

    private function convertPurchaseRequestToPo(int $mainId, int $userId, string $prRefno, array $payload): array
    {
        $record = $this->getPurchaseRequest($mainId, $prRefno);
        if ($record === null) {
            throw new RuntimeException('Purchase request not found');
        }

        $items = $record['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new RuntimeException('Purchase request has no items to convert');
        }

        $selectedIds = [];
        if (is_array($payload['item_ids'] ?? null)) {
            foreach ($payload['item_ids'] as $id) {
                $intId = (int) $id;
                if ($intId > 0) {
                    $selectedIds[$intId] = true;
                }
            }
        }

        $convertItems = [];
        foreach ($items as $item) {
            $itemId = (int) ($item['id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            if ($selectedIds !== [] && !isset($selectedIds[$itemId])) {
                continue;
            }
            if ((string) ($item['po_refno'] ?? '') !== '') {
                continue;
            }
            $convertItems[] = $item;
        }

        if ($convertItems === []) {
            throw new RuntimeException('No convertible purchase request items found');
        }

        $pdo = $this->db->pdo();
        $poRefno = $this->generateRefno();
        $poSequence = $this->nextSequence('Purchase Order');
        $poNumber = sprintf('PO-%s%s', date('y'), str_pad((string) $poSequence, 2, '0', STR_PAD_LEFT));

        $requestHeader = $record['request'] ?? [];
        $supplierName = '';
        $supplierCode = '';
        $supplierId = '';
        foreach ($convertItems as $item) {
            $supplierId = trim((string) ($item['supplier_id'] ?? ''));
            if ($supplierId !== '') {
                $supplierName = (string) ($item['supplier_name'] ?? '');
                $supplier = $this->getSupplierById($supplierId);
                $supplierCode = $supplier['code'];
                $supplierName = $supplier['name'] !== '' ? $supplier['name'] : $supplierName;
                break;
            }
        }

        $insertPo = $pdo->prepare(
            'INSERT INTO tblpo_list
            (lpurchaseno, ldate, ltime, lmain_id, luser, lrefno, ltransaction_status, lsupplier, lsupplier_name, lsupplier_code, lpr_no, lpr_refno)
            VALUES
            (:po_number, :order_date, CURRENT_TIME(), :main_id, :user_id, :refno, "Pending", :supplier_id, :supplier_name, :supplier_code, :pr_no, :pr_refno)'
        );
        $insertPo->execute([
            'po_number' => $poNumber,
            'order_date' => date('Y-m-d'),
            'main_id' => (string) $mainId,
            'user_id' => (string) $userId,
            'refno' => $poRefno,
            'supplier_id' => $supplierId,
            'supplier_name' => $supplierName,
            'supplier_code' => $supplierCode,
            'pr_no' => (string) ($requestHeader['pr_number'] ?? ''),
            'pr_refno' => $prRefno,
        ]);

        foreach ($convertItems as $item) {
            $inventory = $this->findInventoryBySession((string) ($item['item_id'] ?? ''));
            $insertPoItem = $pdo->prepare(
                'INSERT INTO tblpo_itemlist
                (lrefno, litemid, ldesc, lqty, luser, lpartno, litem_code, litem_refno, lopn_number, lsup_price, lbrand, lsupp_id, lsupp_code, lsupp_name, leta_date)
                VALUES
                (:refno, :itemid, :description, :qty, :user_id, :part_no, :item_code, :item_refno, :opn_no, :sup_price, :brand, :supp_id, :supp_code, :supp_name, :eta_date)'
            );
            $insertPoItem->execute([
                'refno' => $poRefno,
                'itemid' => (int) ($inventory['legacy_id'] ?? 0),
                'description' => (string) ($item['description'] ?? ''),
                'qty' => (string) ((float) ($item['quantity'] ?? 0)),
                'user_id' => (string) $userId,
                'part_no' => (string) ($item['part_number'] ?? ''),
                'item_code' => (string) ($item['item_code'] ?? ''),
                'item_refno' => (string) ($item['item_id'] ?? ''),
                'opn_no' => (string) ($inventory['opn_number'] ?? ''),
                'sup_price' => (string) ((float) ($item['unit_cost'] ?? 0)),
                'brand' => (string) ($inventory['brand'] ?? ''),
                'supp_id' => (string) ($item['supplier_id'] ?? ''),
                'supp_code' => (string) ($item['supplier_code'] ?? ''),
                'supp_name' => (string) ($item['supplier_name'] ?? ''),
                'eta_date' => $this->normalizeNullableDate((string) ($item['eta_date'] ?? '')) ?? date('Y-m-d'),
            ]);

            $updatePrItem = $pdo->prepare('UPDATE tblpr_item SET lpo_refno = :po_refno, lpo_no = :po_no WHERE lid = :id');
            $updatePrItem->execute([
                'po_refno' => $poRefno,
                'po_no' => $poNumber,
                'id' => (int) ($item['id'] ?? 0),
            ]);
        }

        $updatePr = $pdo->prepare('UPDATE tblpr_list SET lstatus = "Submitted" WHERE lrefno = :refno');
        $updatePr->execute(['refno' => $prRefno]);

        $this->incrementSequence('Purchase Order');

        $updated = $this->getPurchaseRequest($mainId, $prRefno);
        if ($updated === null) {
            throw new RuntimeException('Purchase request not found after conversion');
        }

        return [
            'request' => $updated['request'],
            'items' => $updated['items'],
            'summary' => $updated['summary'],
            'conversion' => [
                'po_refno' => $poRefno ?? '',
                'po_number' => $poNumber ?? '',
                'converted_count' => count($convertItems),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPurchaseRequestItem(int $itemId): ?array
    {
        $sql = <<<SQL
SELECT
    itm.lid AS id,
    COALESCE(itm.lrefno, '') AS pr_refno,
    COALESCE(itm.litem_refno, '') AS item_id,
    COALESCE(itm.litem_code, '') AS item_code,
    COALESCE(itm.lpart_no, '') AS part_number,
    COALESCE(itm.ldesc, '') AS description,
    CAST(COALESCE(NULLIF(itm.lqty, ''), '0') AS DECIMAL(15,2)) AS quantity,
    CAST(COALESCE(NULLIF(itm.lcost, ''), '0') AS DECIMAL(15,2)) AS unit_cost,
    COALESCE(itm.lsupp_id, '') AS supplier_id,
    COALESCE(itm.lsupp_name, '') AS supplier_name,
    COALESCE(itm.lsupp_code, '') AS supplier_code,
    COALESCE(itm.lpo_refno, '') AS po_refno,
    COALESCE(itm.lpo_no, '') AS po_number,
    COALESCE(itm.lremark, '') AS notes,
    CASE
        WHEN itm.lremark REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN itm.lremark
        ELSE NULL
    END AS eta_date
FROM tblpr_item itm
WHERE itm.lid = :item_id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('item_id', $itemId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertPrItem(PDO $pdo, int $mainId, string $prRefno, array $payload): void
    {
        $itemSession = trim((string) ($payload['item_id'] ?? $payload['item_session'] ?? ''));
        $inventory = $this->findInventoryBySession($itemSession);

        $supplierId = trim((string) ($payload['supplier_id'] ?? ''));
        $supplier = $supplierId !== '' ? $this->getSupplierById($supplierId) : [
            'id' => '',
            'name' => '',
            'code' => '',
        ];

        $qty = max(0, (float) ($payload['quantity'] ?? $payload['qty'] ?? 0));
        if ($qty <= 0) {
            throw new RuntimeException('quantity must be greater than 0');
        }

        $unitCost = array_key_exists('unit_cost', $payload)
            ? max(0, (float) $payload['unit_cost'])
            : (float) ($this->findSupplierCost($supplierId, $itemSession) ?? ($inventory['cost'] ?? 0));

        $partNo = trim((string) ($payload['part_number'] ?? ($inventory['part_no'] ?? '')));
        $itemCode = trim((string) ($payload['item_code'] ?? ($inventory['item_code'] ?? '')));
        $description = trim((string) ($payload['description'] ?? ($inventory['description'] ?? '')));
        $brand = trim((string) ($payload['brand'] ?? ($inventory['brand'] ?? '')));
        $opnNo = trim((string) ($payload['original_part_no'] ?? ($inventory['opn_number'] ?? '')));
        $etaOrNote = trim((string) ($payload['eta_date'] ?? $payload['notes'] ?? ''));

        $stmt = $pdo->prepare(
            'INSERT INTO tblpr_item
            (lrefno, litem_code, lpart_no, lqty, lcost, lremark, lstatus, ldesc, litem_refno, lopn_number, lbrand, lsupp_id, lsupp_code, lsupp_name)
            VALUES
            (:refno, :item_code, :part_no, :qty, :cost, :remark, :status, :description, :item_refno, :opn_no, :brand, :supp_id, :supp_code, :supp_name)'
        );
        $stmt->execute([
            'refno' => $prRefno,
            'item_code' => $itemCode,
            'part_no' => $partNo,
            'qty' => (string) $qty,
            'cost' => (string) $unitCost,
            'remark' => $etaOrNote,
            'status' => 'Pending',
            'description' => $description,
            'item_refno' => $itemSession,
            'opn_no' => $opnNo,
            'brand' => $brand,
            'supp_id' => $supplier['id'],
            'supp_code' => $supplier['code'],
            'supp_name' => $supplier['name'],
        ]);
    }

    /**
     * @return array{legacy_id:int,item_code:string,part_no:string,description:string,brand:string,cost:float,opn_number:string}
     */
    private function findInventoryBySession(string $itemSession): array
    {
        if ($itemSession === '') {
            return [
                'legacy_id' => 0,
                'item_code' => '',
                'part_no' => '',
                'description' => '',
                'brand' => '',
                'cost' => 0.0,
                'opn_number' => '',
            ];
        }

        $sql = <<<SQL
SELECT
    CAST(COALESCE(itm.lid, 0) AS SIGNED) AS legacy_id,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.ldescription, '') AS description,
    COALESCE(itm.lbrand, '') AS brand,
    CAST(COALESCE(itm.lcog, 0) AS DECIMAL(15,2)) AS cost,
    COALESCE(itm.lopn_number, '') AS opn_number
FROM tblinventory_item itm
WHERE itm.lsession = :session
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('session', $itemSession, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [
                'legacy_id' => 0,
                'item_code' => '',
                'part_no' => '',
                'description' => '',
                'brand' => '',
                'cost' => 0.0,
                'opn_number' => '',
            ];
        }

        return [
            'legacy_id' => (int) ($row['legacy_id'] ?? 0),
            'item_code' => (string) ($row['item_code'] ?? ''),
            'part_no' => (string) ($row['part_no'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'brand' => (string) ($row['brand'] ?? ''),
            'cost' => (float) ($row['cost'] ?? 0.0),
            'opn_number' => (string) ($row['opn_number'] ?? ''),
        ];
    }

    private function findSupplierCost(string $supplierId, string $itemSession): ?float
    {
        if ($supplierId === '' || $itemSession === '') {
            return null;
        }
        $sql = <<<SQL
SELECT CAST(COALESCE(lcost, 0) AS DECIMAL(15,2)) AS cost
FROM tblsupplier_cost
WHERE lsupplier_id = :supplier_id
  AND litemsession = :item_session
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('supplier_id', $supplierId, PDO::PARAM_STR);
        $stmt->bindValue('item_session', $itemSession, PDO::PARAM_STR);
        $stmt->execute();
        $cost = $stmt->fetchColumn();
        return $cost === false ? null : (float) $cost;
    }

    /**
     * @return array{id:string,name:string,code:string}
     */
    private function getSupplierById(string $supplierId): array
    {
        if ($supplierId === '') {
            return [
                'id' => '',
                'name' => '',
                'code' => '',
            ];
        }

        $sql = <<<SQL
SELECT
    CAST(s.lid AS CHAR) AS id,
    COALESCE(s.lname, '') AS name,
    COALESCE(s.lcode, '') AS code
FROM tblsupplier s
WHERE s.lid = :supplier_id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('supplier_id', $supplierId, PDO::PARAM_STR);
        $stmt->execute();
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($supplier === false) {
            throw new RuntimeException('Supplier not found');
        }
        return [
            'id' => (string) ($supplier['id'] ?? ''),
            'name' => (string) ($supplier['name'] ?? ''),
            'code' => (string) ($supplier['code'] ?? ''),
        ];
    }

    private function normalizeDate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return date('Y-m-d');
        }

        $formats = ['Y-m-d', 'Y/m/d', 'm/d/Y', 'm-d-Y', 'd/m/Y', 'd-m-Y'];
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $trimmed);
            if ($date instanceof \DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }

        $timestamp = strtotime($trimmed);
        if ($timestamp === false) {
            throw new RuntimeException('Invalid date value: ' . $value);
        }
        return date('Y-m-d', $timestamp);
    }

    private function normalizeNullableDate(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        return $this->normalizeDate($trimmed);
    }

    private function generateRefno(): string
    {
        return date('YmdHis') . random_int(100000, 9999999);
    }

    private function nextPurchaseOrderNumber(): string
    {
        $seq = $this->nextSequence('Purchase Order');
        return sprintf('PO-%s%s', date('y'), str_pad((string) $seq, 2, '0', STR_PAD_LEFT));
    }

    private function nextSequence(string $transactionType, ?PDO $pdo = null): int
    {
        $conn = $pdo ?? $this->db->pdo();
        $stmt = $conn->prepare(
            'SELECT CAST(COALESCE(MAX(lmax_no), 0) AS SIGNED) AS max_no
             FROM tblnumber_generator
             WHERE ltransaction_type = :type'
        );
        $stmt->execute(['type' => $transactionType]);
        $max = (int) ($stmt->fetchColumn() ?: 0);
        return $max + 1;
    }

    private function incrementSequence(string $transactionType, ?PDO $pdo = null): void
    {
        $conn = $pdo ?? $this->db->pdo();
        $next = $this->nextSequence($transactionType, $conn);
        $stmt = $conn->prepare(
            'INSERT INTO tblnumber_generator (ltransaction_type, lmax_no) VALUES (:type, :max_no)'
        );
        $stmt->execute([
            'type' => $transactionType,
            'max_no' => $next,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bindParams(\PDOStatement $stmt, array $params, bool $withPagination): void
    {
        foreach ($params as $key => $value) {
            if (!$withPagination && ($key === 'limit' || $key === 'offset')) {
                continue;
            }
            if ($withPagination && ($key === 'limit' || $key === 'offset')) {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }
            if ($key === 'month' || $key === 'year') {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }
            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
    }
}
