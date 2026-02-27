<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class SuggestedStockReportRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCustomers(int $mainId, ?string $dateFrom, ?string $dateTo): array
    {
        [$from, $to] = $this->resolveDateRange($dateFrom, $dateTo);

        $sql = <<<SQL
SELECT
    COALESCE(tr.lcustomerid, '') AS id,
    TRIM(COALESCE(tr.lcompany, '')) AS company,
    COUNT(*) AS inquiry_count
FROM tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
WHERE tr.lmain_id = :main_id
  AND COALESCE(i.lremark, '') = 'NotListed'
  AND tr.ldate >= :date_from
  AND tr.ldate <= :date_to
GROUP BY tr.lcustomerid, tr.lcompany
ORDER BY inquiry_count DESC, company ASC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => (string) $mainId,
            'date_from' => $from,
            'date_to' => $to,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(
        int $mainId,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $customerId,
        int $page,
        int $perPage
    ): array {
        [$from, $to] = $this->resolveDateRange($dateFrom, $dateTo);
        [$whereSql, $params] = $this->buildFilters($mainId, $from, $to, $customerId);

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

        $offset = ($page - 1) * $perPage;
        $sql = <<<SQL
SELECT
    MIN(i.lid) AS id,
    COALESCE(i.lpartno, '') AS part_no,
    COALESCE(i.litem_code, '') AS item_code,
    COALESCE(i.ldesc, '') AS description,
    COUNT(*) AS inquiry_count,
    CAST(COALESCE(SUM(COALESCE(i.lqty, 0)), 0) AS SIGNED) AS total_qty,
    COUNT(DISTINCT COALESCE(tr.lcustomerid, '')) AS customer_count,
    GROUP_CONCAT(DISTINCT CONCAT(COALESCE(tr.lcustomerid, ''), '::', TRIM(COALESCE(tr.lcompany, ''))) SEPARATOR '||') AS customers,
    COALESCE(MAX(i.lreport_remark), '') AS report_remark,
    COALESCE(MAX(tr.ldate), '') AS last_inquiry_date
FROM tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
WHERE {$whereSql}
GROUP BY i.lpartno, i.litem_code, i.ldesc
ORDER BY inquiry_count DESC, total_qty DESC, part_no ASC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
                'date_from' => $from,
                'date_to' => $to,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function details(
        int $mainId,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $customerId,
        int $page,
        int $perPage
    ): array {
        [$from, $to] = $this->resolveDateRange($dateFrom, $dateTo);
        [$whereSql, $params] = $this->buildFilters($mainId, $from, $to, $customerId);

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

        $offset = ($page - 1) * $perPage;
        $sql = <<<SQL
SELECT
    i.lid AS id,
    COALESCE(tr.lrefno, '') AS inquiry_id,
    COALESCE(tr.linqno, '') AS inquiry_no,
    COALESCE(tr.ldate, '') AS inquiry_date,
    COALESCE(tr.lcustomerid, '') AS customer_id,
    TRIM(COALESCE(tr.lcompany, '')) AS customer_name,
    COALESCE(i.lpartno, '') AS part_no,
    COALESCE(i.litem_code, '') AS item_code,
    COALESCE(i.ldesc, '') AS description,
    CAST(COALESCE(i.lqty, 0) AS DECIMAL(15,2)) AS qty,
    COALESCE(i.lremark, '') AS remark,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS sales_person
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
                'date_from' => $from,
                'date_to' => $to,
            ],
        ];
    }

    public function updateRemark(int $mainId, int $itemId, string $remark): bool
    {
        $sql = <<<SQL
UPDATE tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
SET i.lreport_remark = :remark
WHERE i.lid = :item_id
  AND tr.lmain_id = :main_id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'remark' => $remark,
            'item_id' => $itemId,
            'main_id' => $mainId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function listSuppliers(int $mainId): array
    {
        $sql = <<<SQL
SELECT
    CAST(s.lid AS CHAR) AS id,
    COALESCE(s.lname, '') AS company
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
     * @return array<int, array<string, string>>
     */
    public function listPurchaseOrders(int $mainId): array
    {
        $sql = <<<SQL
SELECT
    COALESCE(po.lrefno, '') AS id,
    COALESCE(po.lpurchaseno, '') AS po_no,
    COALESCE(po.lsupplier_name, '') AS supplier_name,
    COALESCE(po.ltransaction_status, 'Pending') AS status
FROM tblpo_list po
WHERE po.lmain_id = :main_id
  AND LOWER(COALESCE(po.ltransaction_status, 'pending')) NOT IN ('cancelled', 'completed', 'closed', 'deleted')
ORDER BY po.lid DESC
LIMIT 200
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['main_id' => $mainId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function addPurchaseOrderItem(int $mainId, int $userId, string $purchaseRefno, array $payload): array
    {
        $po = $this->findPurchaseOrder($mainId, $purchaseRefno);
        if ($po === null) {
            throw new RuntimeException('Purchase order not found');
        }

        $qty = (float) ($payload['qty'] ?? 0);
        if ($qty <= 0) {
            throw new RuntimeException('qty must be greater than 0');
        }

        $unitPrice = max(0, (float) ($payload['unit_price'] ?? 0));
        $partNo = trim((string) ($payload['part_no'] ?? ''));
        $itemCode = trim((string) ($payload['item_code'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));

        $supplierId = trim((string) ($payload['supplier_id'] ?? $po['supplier_id'] ?? ''));
        $supplier = $this->getSupplierById($supplierId);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblpo_itemlist
            (lrefno, litemid, ldesc, lqty, luser, lpartno, litem_code, litem_refno, lopn_number, lsup_price, lbrand, lsupp_id, lsupp_code, lsupp_name, leta_date)
            VALUES
            (:refno, 0, :description, :qty, :user_id, :part_no, :item_code, "", "", :supplier_price, "", :supplier_id, :supplier_code, :supplier_name, :eta_date)'
        );
        $stmt->execute([
            'refno' => $purchaseRefno,
            'description' => $description,
            'qty' => (string) $qty,
            'user_id' => (string) $userId,
            'part_no' => $partNo,
            'item_code' => $itemCode,
            'supplier_price' => number_format($unitPrice, 2, '.', ''),
            'supplier_id' => $supplier['id'],
            'supplier_code' => $supplier['code'],
            'supplier_name' => $supplier['name'],
            'eta_date' => date('Y-m-d'),
        ]);

        return [
            'added' => true,
            'po_refno' => $purchaseRefno,
            'item_id' => (int) $this->db->pdo()->lastInsertId(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createPurchaseOrderWithItem(int $mainId, int $userId, array $payload): array
    {
        $qty = (float) ($payload['qty'] ?? 0);
        if ($qty <= 0) {
            throw new RuntimeException('qty must be greater than 0');
        }

        $supplierId = trim((string) ($payload['supplier_id'] ?? ''));
        if ($supplierId === '') {
            throw new RuntimeException('supplier_id is required');
        }

        $supplier = $this->getSupplierById($supplierId);
        if ($supplier['id'] === '') {
            throw new RuntimeException('Supplier not found');
        }

        $poRefno = $this->generateRefno();
        $poNumber = $this->nextPurchaseOrderNumber();

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $insertHeader = $pdo->prepare(
                'INSERT INTO tblpo_list
                (lpurchaseno, ldate, ltime, lmain_id, luser, lrefno, ltransaction_status, lsupplier, lsupplier_name, lsupplier_code, lreference)
                VALUES
                (:po_number, :order_date, CURRENT_TIME(), :main_id, :user_id, :refno, "Pending", :supplier_id, :supplier_name, :supplier_code, :reference)'
            );
            $insertHeader->execute([
                'po_number' => $poNumber,
                'order_date' => date('Y-m-d'),
                'main_id' => (string) $mainId,
                'user_id' => (string) $userId,
                'refno' => $poRefno,
                'supplier_id' => $supplier['id'],
                'supplier_name' => $supplier['name'],
                'supplier_code' => $supplier['code'],
                'reference' => 'Suggested Stock Report',
            ]);

            $insertItem = $pdo->prepare(
                'INSERT INTO tblpo_itemlist
                (lrefno, litemid, ldesc, lqty, luser, lpartno, litem_code, litem_refno, lopn_number, lsup_price, lbrand, lsupp_id, lsupp_code, lsupp_name, leta_date)
                VALUES
                (:refno, 0, :description, :qty, :user_id, :part_no, :item_code, "", "", :supplier_price, "", :supplier_id, :supplier_code, :supplier_name, :eta_date)'
            );
            $insertItem->execute([
                'refno' => $poRefno,
                'description' => trim((string) ($payload['description'] ?? '')),
                'qty' => (string) $qty,
                'user_id' => (string) $userId,
                'part_no' => trim((string) ($payload['part_no'] ?? '')),
                'item_code' => trim((string) ($payload['item_code'] ?? '')),
                'supplier_price' => number_format(max(0, (float) ($payload['unit_price'] ?? 0)), 2, '.', ''),
                'supplier_id' => $supplier['id'],
                'supplier_code' => $supplier['code'],
                'supplier_name' => $supplier['name'],
                'eta_date' => date('Y-m-d'),
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'po_refno' => $poRefno,
            'po_number' => $poNumber,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveDateRange(?string $dateFrom, ?string $dateTo): array
    {
        $today = new DateTimeImmutable('today');
        $to = $this->normalizeDate($dateTo) ?? $today->format('Y-m-d');
        $from = $this->normalizeDate($dateFrom) ?? $today->modify('-1 month')->format('Y-m-d');

        if ($from > $to) {
            return [$to, $to];
        }

        return [$from, $to];
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function buildFilters(int $mainId, string $dateFrom, string $dateTo, ?string $customerId): array
    {
        $where = [
            'tr.lmain_id = :main_id',
            'COALESCE(i.lremark, "") = "NotListed"',
            'tr.ldate >= :date_from',
            'tr.ldate <= :date_to',
        ];
        $params = [
            'main_id' => (string) $mainId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];

        $customer = trim((string) $customerId);
        if ($customer !== '' && strtolower($customer) !== 'all') {
            $where[] = 'tr.lcustomerid = :customer_id';
            $params['customer_id'] = $customer;
        }

        return [implode(' AND ', $where), $params];
    }

    private function normalizeDate(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '' || $trimmed === '0000-00-00') {
            return null;
        }

        $ts = strtotime($trimmed);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts);
    }

    /**
     * @return array<string, string>|null
     */
    private function findPurchaseOrder(int $mainId, string $purchaseRefno): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(lrefno, "") AS refno, COALESCE(lsupplier, "") AS supplier_id
             FROM tblpo_list
             WHERE lmain_id = :main_id AND lrefno = :refno
             LIMIT 1'
        );
        $stmt->execute([
            'main_id' => $mainId,
            'refno' => $purchaseRefno,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @return array{id:string,code:string,name:string}
     */
    private function getSupplierById(string $supplierId): array
    {
        $trimmed = trim($supplierId);
        if ($trimmed === '') {
            return ['id' => '', 'code' => '', 'name' => ''];
        }

        $stmt = $this->db->pdo()->prepare('SELECT lid, lcode, lname FROM tblsupplier WHERE lid = :id LIMIT 1');
        $stmt->execute(['id' => $trimmed]);
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

    private function nextPurchaseOrderNumber(): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(lmax_no), 0) + 1 AS next_no
             FROM tblnumber_generator
             WHERE ltransaction_type = :type'
        );
        $stmt->execute(['type' => 'Purchase Order']);
        $next = (int) ($stmt->fetchColumn() ?: 1);

        $insert = $this->db->pdo()->prepare(
            'INSERT INTO tblnumber_generator (ltransaction_type, lmax_no)
             VALUES (:type, :max_no)'
        );
        $insert->execute([
            'type' => 'Purchase Order',
            'max_no' => $next,
        ]);

        return 'PO-' . date('y') . str_pad((string) $next, 2, '0', STR_PAD_LEFT);
    }

    private function generateRefno(): string
    {
        return date('YmdHis') . (string) random_int(1000, 9999999);
    }
}
