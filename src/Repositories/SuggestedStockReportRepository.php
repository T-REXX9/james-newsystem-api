<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class SuggestedStockReportRepository
{
    public const SORT_QTY_DESC = 'qty-desc';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCustomers(int $mainId, ?string $dateFrom, ?string $dateTo): array
    {
        [$from, $to] = $this->resolveDateRange($dateFrom, $dateTo);
        $kivSql = $this->kivExistsSql('kiv_cust_main_id');

        $sql = <<<SQL
SELECT
    COALESCE(tr.lcustomerid, '') AS id,
    TRIM(COALESCE(MAX(tr.lcompany), '')) AS company,
    COUNT(*) AS inquiry_count
FROM tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
LEFT JOIN (
    SELECT inv.litemcode AS litemcode
    FROM tblinventory_item inv
    WHERE inv.lmain_id = :inv_code_main_id
      AND COALESCE(inv.lnot_inventory, 0) = 0
      AND COALESCE(inv.ldeleted, 0) = 0
      AND COALESCE(inv.litemcode, '') <> ''
    GROUP BY inv.litemcode
) inv_by_code
  ON COALESCE(i.litem_code, '') <> ''
 AND inv_by_code.litemcode = i.litem_code
LEFT JOIN (
    SELECT inv.lpartno AS lpartno
    FROM tblinventory_item inv
    WHERE inv.lmain_id = :inv_part_main_id
      AND COALESCE(inv.lnot_inventory, 0) = 0
      AND COALESCE(inv.ldeleted, 0) = 0
      AND COALESCE(inv.lpartno, '') <> ''
    GROUP BY inv.lpartno
) inv_by_part
  ON COALESCE(i.lpartno, '') <> ''
 AND inv_by_part.lpartno = i.lpartno
WHERE tr.lmain_id = :main_id
  AND (
    COALESCE(i.lremark, '') = 'ProductCreated'
    OR (
      COALESCE(i.lremark, '') = 'NotListed'
      AND inv_by_code.litemcode IS NULL
      AND inv_by_part.lpartno IS NULL
    )
  )
  AND tr.ldate >= :date_from
  AND tr.ldate <= :date_to
  AND NOT {$kivSql}
GROUP BY tr.lcustomerid
ORDER BY inquiry_count DESC, company ASC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => (string) $mainId,
            'date_from' => $from,
            'date_to' => $to,
            'inv_code_main_id' => (string) $mainId,
            'inv_part_main_id' => (string) $mainId,
            'kiv_cust_main_id' => (string) $mainId,
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
        int $perPage,
        ?string $partNo = null,
        string $sortBy = self::SORT_QTY_DESC,
        bool $kivFolder = false,
        bool $cartFolder = false
    ): array {
        [$from, $to] = $this->resolveDateRange($dateFrom, $dateTo);
        $orderSql = $this->summaryOrderSql($sortBy);
        $offset = ($page - 1) * $perPage;
        $fetchLimit = $perPage + 1;
        if ($cartFolder) {
            $kivFolder = false;
        }

        $where = [
            'tr.lmain_id = :main_id',
            $cartFolder
                ? "COALESCE(i.lremark, '') = 'AddedToPR'"
                : "COALESCE(i.lremark, '') IN ('NotListed', 'ProductCreated')",
            'tr.ldate >= :date_from',
            'tr.ldate <= :date_to',
        ];
        $params = [
            'main_id' => (string) $mainId,
            'date_from' => $from,
            'date_to' => $to,
            'inv_code_main_id' => (string) $mainId,
            'inv_part_main_id' => (string) $mainId,
        ];
        if (!$cartFolder) {
            $where[] = ($kivFolder ? '' : 'NOT ') . $this->kivExistsSql('kiv_main_id');
            $params['kiv_main_id'] = (string) $mainId;
        }

        $customer = trim((string) $customerId);
        if ($customer !== '' && strtolower($customer) !== 'all') {
            $where[] = 'tr.lcustomerid = :customer_id';
            $params['customer_id'] = $customer;
        }

        $partSearch = trim((string) $partNo);
        if ($partSearch !== '') {
            $where[] = 'LOWER(COALESCE(i.lpartno, \'\')) LIKE :part_no_search';
            $params['part_no_search'] = '%' . strtolower($partSearch) . '%';
        }

        if ($cartFolder) {
            $where[] = 'covering_pr.covering_pr_id IS NOT NULL';
        } else {
            // Keep NotListed rows that soft-match inventory out of the active report,
            // while ProductCreated rows stay visible for the PR workflow.
            $where[] = <<<SQL
(
  COALESCE(i.lremark, '') = 'ProductCreated'
  OR (
    COALESCE(i.lremark, '') = 'NotListed'
    AND inv_by_code.lsession IS NULL
    AND inv_by_part.lsession IS NULL
  )
)
SQL;
        }

        $whereSql = implode(' AND ', $where);
        $coveringJoinSql = $this->coveringPurchaseRequestJoinSql();

        // Pre-aggregate inventory matches (one row per code/part) so joins cannot
        // multiply inquiry lines and inflate qty/inquiry counts.
        $sql = <<<SQL
SELECT
    CAST(MIN(i.lid) AS CHAR) AS id,
    COALESCE(i.lpartno, '') AS part_no,
    COALESCE(i.litem_code, '') AS item_code,
    COALESCE(i.ldesc, '') AS description,
    COUNT(*) AS inquiry_count,
    CAST(COALESCE(SUM(CASE WHEN COALESCE(i.lqty, 0) <= 0 THEN 1 ELSE i.lqty END), 0) AS SIGNED) AS total_qty,
    COUNT(DISTINCT COALESCE(tr.lcustomerid, '')) AS customer_count,
    GROUP_CONCAT(DISTINCT CONCAT(COALESCE(tr.lcustomerid, ''), '::', TRIM(COALESCE(tr.lcompany, ''))) SEPARATOR '||') AS customers,
    COALESCE(MAX(i.lreport_remark), '') AS report_remark,
    COALESCE(MAX(tr.ldate), '') AS last_inquiry_date,
    '' AS brand,
    COALESCE(MAX(inv_by_code.lsession), MAX(inv_by_part.lsession), '') AS database_item_id,
    COALESCE(MAX(inv_by_code.litemcode), MAX(inv_by_part.litemcode), '') AS database_item_code,
    COALESCE(MAX(inv_by_code.lpartno), MAX(inv_by_part.lpartno), '') AS database_part_no,
    CASE
      WHEN SUM(CASE WHEN COALESCE(i.lremark, '') IN ('ProductCreated', 'AddedToPR') THEN 1 ELSE 0 END) > 0
       AND (
         MAX(inv_by_code.lsession) IS NOT NULL
         OR MAX(inv_by_part.lsession) IS NOT NULL
       )
      THEN 1 ELSE 0
    END AS product_created,
    :kiv_flag AS is_kiv,
    COALESCE(MAX(covering_pr.covering_pr_id), '') AS covering_pr_id,
    COALESCE(MAX(covering_pr.covering_pr_number), '') AS covering_pr_number
FROM tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
LEFT JOIN (
    SELECT
        inv.litemcode AS litemcode,
        CAST(MIN(inv.lsession) AS CHAR) AS lsession,
        SUBSTRING_INDEX(GROUP_CONCAT(inv.lpartno ORDER BY inv.lsession SEPARATOR '\n'), '\n', 1) AS lpartno
    FROM tblinventory_item inv
    WHERE inv.lmain_id = :inv_code_main_id
      AND COALESCE(inv.lnot_inventory, 0) = 0
      AND COALESCE(inv.ldeleted, 0) = 0
      AND COALESCE(inv.litemcode, '') <> ''
    GROUP BY inv.litemcode
) inv_by_code
  ON COALESCE(i.litem_code, '') <> ''
 AND inv_by_code.litemcode = i.litem_code
LEFT JOIN (
    SELECT
        inv.lpartno AS lpartno,
        CAST(MIN(inv.lsession) AS CHAR) AS lsession,
        SUBSTRING_INDEX(GROUP_CONCAT(inv.litemcode ORDER BY inv.lsession SEPARATOR '\n'), '\n', 1) AS litemcode
    FROM tblinventory_item inv
    WHERE inv.lmain_id = :inv_part_main_id
      AND COALESCE(inv.lnot_inventory, 0) = 0
      AND COALESCE(inv.ldeleted, 0) = 0
      AND COALESCE(inv.lpartno, '') <> ''
    GROUP BY inv.lpartno
) inv_by_part
  ON COALESCE(i.lpartno, '') <> ''
 AND inv_by_part.lpartno = i.lpartno
{$coveringJoinSql}
WHERE {$whereSql}
GROUP BY i.lpartno, i.litem_code, i.ldesc
ORDER BY {$orderSql}
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue('kiv_flag', $kivFolder ? '1' : '0', PDO::PARAM_STR);
        $stmt->bindValue('limit', $fetchLimit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $perPage;
        if ($hasMore) {
            array_pop($rows);
        }

        return [
            'items' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => $hasMore,
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
        $kivSql = $this->kivExistsSql('kiv_main_id');

        $where = [
            'tr.lmain_id = :main_id',
            'tr.ldate >= :date_from',
            'tr.ldate <= :date_to',
            "NOT {$kivSql}",
            <<<SQL
(
  COALESCE(i.lremark, '') = 'ProductCreated'
  OR (
    COALESCE(i.lremark, '') = 'NotListed'
    AND inv_by_code.litemcode IS NULL
    AND inv_by_part.lpartno IS NULL
  )
)
SQL,
        ];
        $params = [
            'main_id' => (string) $mainId,
            'date_from' => $from,
            'date_to' => $to,
            'kiv_main_id' => (string) $mainId,
            'inv_code_main_id' => (string) $mainId,
            'inv_part_main_id' => (string) $mainId,
        ];

        $customer = trim((string) $customerId);
        if ($customer !== '' && strtolower($customer) !== 'all') {
            $where[] = 'tr.lcustomerid = :customer_id';
            $params['customer_id'] = $customer;
        }

        $whereSql = implode(' AND ', $where);
        $inventoryJoins = <<<SQL
LEFT JOIN (
    SELECT inv.litemcode AS litemcode
    FROM tblinventory_item inv
    WHERE inv.lmain_id = :inv_code_main_id
      AND COALESCE(inv.lnot_inventory, 0) = 0
      AND COALESCE(inv.ldeleted, 0) = 0
      AND COALESCE(inv.litemcode, '') <> ''
    GROUP BY inv.litemcode
) inv_by_code
  ON COALESCE(i.litem_code, '') <> ''
 AND inv_by_code.litemcode = i.litem_code
LEFT JOIN (
    SELECT inv.lpartno AS lpartno
    FROM tblinventory_item inv
    WHERE inv.lmain_id = :inv_part_main_id
      AND COALESCE(inv.lnot_inventory, 0) = 0
      AND COALESCE(inv.ldeleted, 0) = 0
      AND COALESCE(inv.lpartno, '') <> ''
    GROUP BY inv.lpartno
) inv_by_part
  ON COALESCE(i.lpartno, '') <> ''
 AND inv_by_part.lpartno = i.lpartno
SQL;

        $countSql = <<<SQL
SELECT COUNT(*)
FROM tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
{$inventoryJoins}
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
{$inventoryJoins}
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
     * Keep a suggested-stock row active after its product is created, so it can be
     * selected and added directly to a purchase request.
     * Prefer inquiry item id when provided; otherwise match by part no and/or item code.
     *
     * @return array{cleared:int}
     */
    public function clearNotListedRemarks(
        int $mainId,
        ?int $inquiryItemId = null,
        string $partNo = '',
        string $itemCode = ''
    ): array {
        $partNo = trim($partNo);
        $itemCode = trim($itemCode);
        $cleared = 0;

        if ($inquiryItemId !== null && $inquiryItemId > 0) {
            $sql = <<<SQL
UPDATE tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
SET i.lremark = 'ProductCreated'
WHERE i.lid = :item_id
  AND tr.lmain_id = :main_id
  AND COALESCE(i.lremark, '') = 'NotListed'
SQL;
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([
                'item_id' => $inquiryItemId,
                'main_id' => $mainId,
            ]);
            $cleared += $stmt->rowCount();

        }

        // The product-creation handoff always includes the source inquiry id.
        // Avoid a broad part/code update after that fast primary-key update.
        if ($cleared === 0 && ($partNo !== '' || $itemCode !== '')) {
            $matchParts = [];
            $params = [
                'main_id' => $mainId,
            ];
            if ($itemCode !== '') {
                $matchParts[] = 'COALESCE(i.litem_code, "") = :item_code';
                $params['item_code'] = $itemCode;
            }
            if ($partNo !== '') {
                $matchParts[] = 'COALESCE(i.lpartno, "") = :part_no';
                $params['part_no'] = $partNo;
            }
            $matchSql = implode(' OR ', $matchParts);
            $sql = <<<SQL
UPDATE tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
SET i.lremark = 'ProductCreated'
WHERE tr.lmain_id = :main_id
  AND COALESCE(i.lremark, '') = 'NotListed'
  AND ({$matchSql})
SQL;
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute($params);
            $cleared += $stmt->rowCount();
        }

        return ['cleared' => $cleared];
    }

    /**
     * Remove product-created suggestions from the active report once their PR was
     * successfully created.  Inquiry history is deliberately retained.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{removed:int,requested:int}
     */
    public function markAddedToPurchaseRequest(int $mainId, array $items): array
    {
        $normalized = $this->normalizeKivItems($items);
        if ($normalized === []) return ['removed' => 0, 'requested' => 0];

        $stmt = $this->db->pdo()->prepare(<<<SQL
UPDATE tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
SET i.lremark = 'AddedToPR'
WHERE tr.lmain_id = :main_id
  AND COALESCE(i.lremark, '') IN ('NotListed', 'ProductCreated')
  AND i.lpartno = :part_no
  AND (
    i.litem_code = :item_code
    OR (i.litem_code IS NULL AND :match_null_item_code = 1)
  )
  AND i.ldesc = :description
SQL);
        $removed = 0;
        foreach ($normalized as $item) {
            $stmt->execute([
                'main_id' => (string) $mainId,
                'part_no' => $item['part_no'],
                'item_code' => $item['item_code'],
                'match_null_item_code' => $item['item_code'] === '' ? 1 : 0,
                'description' => $item['description'],
            ]);
            $removed += $stmt->rowCount();
        }
        return ['removed' => $removed, 'requested' => count($normalized)];
    }

    /**
     * Recompute AddedToPR vs ProductCreated for the given product identities
     * from current Live Purchase Request coverage.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public function syncCoverageForIdentities(int $mainId, array $items): void
    {
        foreach ($this->normalizeKivItems($items) as $item) {
            if ($this->hasLivePurchaseRequestCoverage($item['part_no'], $item['item_code'], $item['description'])) {
                $this->setRemarkForIdentity($mainId, $item, ['ProductCreated', 'AddedToPR'], 'AddedToPR');
                continue;
            }
            $this->setRemarkForIdentity($mainId, $item, ['AddedToPR'], 'ProductCreated');
        }
    }

    public function syncCoverageForPurchaseRequest(int $mainId, string $prRefno): void
    {
        $refno = trim($prRefno);
        if ($refno === '') {
            return;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(lpart_no, "") AS part_no, COALESCE(litem_code, "") AS item_code, COALESCE(ldesc, "") AS description
             FROM tblpr_item
             WHERE lrefno = :refno'
        );
        $stmt->execute(['refno' => $refno]);
        $this->syncCoverageForIdentities($mainId, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function repairUncoveredAddedToPr(int $mainId): int
    {
        $stmt = $this->db->pdo()->prepare(
            <<<SQL
UPDATE tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
SET i.lremark = 'ProductCreated'
WHERE tr.lmain_id = :main_id
  AND COALESCE(i.lremark, '') = 'AddedToPR'
  AND NOT EXISTS (
    SELECT 1
    FROM tblpr_item pri
    INNER JOIN tblpr_list pr ON pr.lrefno = pri.lrefno
    WHERE COALESCE(pr.ldeleted, 0) = 0
      AND LOWER(COALESCE(pr.lstatus, '')) <> 'deleted'
      AND pri.lpart_no = i.lpartno
      AND (
        pri.litem_code = i.litem_code
        OR (COALESCE(pri.litem_code, '') = '' AND COALESCE(i.litem_code, '') = '')
      )
      AND pri.ldesc = i.ldesc
  )
SQL
        );
        $stmt->execute(['main_id' => (string) $mainId]);
        return $stmt->rowCount();
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{added:int,requested:int}
     */
    public function addToKiv(int $mainId, array $items, string $createdBy = ''): array
    {
        $normalized = $this->normalizeKivItems($items);
        if ($normalized === []) {
            return ['added' => 0, 'requested' => 0];
        }

        $sql = <<<SQL
INSERT INTO suggested_stock_kiv (main_id, part_no, item_code, description, item_key, created_by)
VALUES (:main_id, :part_no, :item_code, :description, :item_key, :created_by)
ON DUPLICATE KEY UPDATE
    part_no = VALUES(part_no),
    item_code = VALUES(item_code),
    description = VALUES(description)
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $added = 0;
        foreach ($normalized as $item) {
            $stmt->execute([
                'main_id' => (string) $mainId,
                'part_no' => $item['part_no'],
                'item_code' => $item['item_code'],
                'description' => $item['description'],
                'item_key' => $item['item_key'],
                'created_by' => $createdBy,
            ]);
            $added++;
        }

        return ['added' => $added, 'requested' => count($normalized)];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{removed:int,requested:int}
     */
    public function removeFromKiv(int $mainId, array $items): array
    {
        $normalized = $this->normalizeKivItems($items);
        if ($normalized === []) {
            return ['removed' => 0, 'requested' => 0];
        }

        $sql = <<<SQL
DELETE FROM suggested_stock_kiv
WHERE main_id = :main_id
  AND item_key = :item_key
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $removed = 0;
        foreach ($normalized as $item) {
            $stmt->execute([
                'main_id' => (string) $mainId,
                'item_key' => $item['item_key'],
            ]);
            $removed += $stmt->rowCount();
        }

        return ['removed' => $removed, 'requested' => count($normalized)];
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
    COALESCE(
        NULLIF(TRIM(po.lsupplier_name), ''),
        NULLIF(TRIM(item_supplier.supplier_name), ''),
        NULLIF(TRIM(s.lname), ''),
        ''
    ) AS supplier_name,
    CASE
        WHEN LOWER(COALESCE(po.ltransaction_status, 'pending')) IN ('posted', 'approved') THEN 'Posted'
        ELSE COALESCE(po.ltransaction_status, 'Pending')
    END AS status
FROM tblpo_list po
LEFT JOIN tblsupplier s
    ON CAST(s.lid AS CHAR) = CAST(po.lsupplier AS CHAR)
LEFT JOIN (
    SELECT
        lrefno,
        SUBSTRING_INDEX(
            GROUP_CONCAT(NULLIF(TRIM(lsupp_name), '') ORDER BY lid ASC SEPARATOR '||'),
            '||',
            1
        ) AS supplier_name
    FROM tblpo_itemlist
    GROUP BY lrefno
) item_supplier
    ON item_supplier.lrefno = po.lrefno
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

    private function summaryOrderSql(string $sortBy): string
    {
        $qtyDescSql = 'total_qty DESC, inquiry_count DESC, part_no ASC';
        return match ($sortBy) {
            self::SORT_QTY_DESC => $qtyDescSql,
            'inquiries-asc' => 'inquiry_count ASC, total_qty DESC, part_no ASC',
            'description-asc' => 'description ASC, part_no ASC',
            'description-desc' => 'description DESC, part_no ASC',
            'inquiries-desc' => 'inquiry_count DESC, total_qty DESC, part_no ASC',
            default => $qtyDescSql,
        };
    }

    private function kivExistsSql(string $mainParam): string
    {
        return <<<SQL
EXISTS (
    SELECT 1
    FROM suggested_stock_kiv kiv
    WHERE kiv.main_id = :{$mainParam}
      AND kiv.part_no = TRIM(COALESCE(i.lpartno, ''))
      AND kiv.item_code = TRIM(COALESCE(i.litem_code, ''))
      AND kiv.description = TRIM(COALESCE(i.ldesc, ''))
)
SQL;
    }

    private function coveringPurchaseRequestJoinSql(): string
    {
        return <<<SQL
LEFT JOIN (
    SELECT
        pri.lpart_no AS part_no,
        COALESCE(pri.litem_code, '') AS item_code,
        COALESCE(pri.ldesc, '') AS description,
        SUBSTRING_INDEX(GROUP_CONCAT(pr.lrefno ORDER BY pr.ldatetime DESC, pr.lrefno DESC SEPARATOR '\n'), '\n', 1) AS covering_pr_id,
        SUBSTRING_INDEX(GROUP_CONCAT(pr.lprno ORDER BY pr.ldatetime DESC, pr.lrefno DESC SEPARATOR '\n'), '\n', 1) AS covering_pr_number
    FROM tblpr_item pri
    INNER JOIN tblpr_list pr ON pr.lrefno = pri.lrefno
    WHERE COALESCE(pr.ldeleted, 0) = 0
      AND LOWER(COALESCE(pr.lstatus, '')) <> 'deleted'
    GROUP BY pri.lpart_no, COALESCE(pri.litem_code, ''), COALESCE(pri.ldesc, '')
) covering_pr
  ON covering_pr.part_no = COALESCE(i.lpartno, '')
 AND covering_pr.item_code = COALESCE(i.litem_code, '')
 AND covering_pr.description = COALESCE(i.ldesc, '')
SQL;
    }

    private function hasLivePurchaseRequestCoverage(string $partNo, string $itemCode, string $description): bool
    {
        $stmt = $this->db->pdo()->prepare(
            <<<SQL
SELECT 1
FROM tblpr_item pri
INNER JOIN tblpr_list pr ON pr.lrefno = pri.lrefno
WHERE COALESCE(pr.ldeleted, 0) = 0
  AND LOWER(COALESCE(pr.lstatus, '')) <> 'deleted'
  AND pri.lpart_no = :part_no
  AND (
    pri.litem_code = :item_code
    OR (COALESCE(pri.litem_code, '') = '' AND :match_null_item_code = 1)
  )
  AND pri.ldesc = :description
LIMIT 1
SQL
        );
        $stmt->execute([
            'part_no' => $partNo,
            'item_code' => $itemCode,
            'match_null_item_code' => $itemCode === '' ? 1 : 0,
            'description' => $description,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array{part_no:string,item_code:string,description:string} $item
     * @param array<int, string> $fromRemarks
     */
    private function setRemarkForIdentity(int $mainId, array $item, array $fromRemarks, string $toRemark): void
    {
        if ($fromRemarks === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($fromRemarks), '?'));
        $sql = <<<SQL
UPDATE tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
SET i.lremark = ?
WHERE tr.lmain_id = ?
  AND COALESCE(i.lremark, '') IN ({$placeholders})
  AND i.lpartno = ?
  AND (
    i.litem_code = ?
    OR (i.litem_code IS NULL AND ? = 1)
  )
  AND i.ldesc = ?
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_merge(
            [$toRemark, (string) $mainId],
            $fromRemarks,
            [
                $item['part_no'],
                $item['item_code'],
                $item['item_code'] === '' ? 1 : 0,
                $item['description'],
            ]
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, string>>
     */
    private function normalizeKivItems(array $items): array
    {
        $normalized = [];
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $partNo = trim((string) ($item['part_no'] ?? $item['partNo'] ?? ''));
            $itemCode = trim((string) ($item['item_code'] ?? $item['itemCode'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));
            if ($partNo === '' && $itemCode === '' && $description === '') {
                continue;
            }
            $itemKey = $this->kivItemKey($partNo, $itemCode, $description);
            if (isset($seen[$itemKey])) {
                continue;
            }
            $seen[$itemKey] = true;
            $normalized[] = [
                'part_no' => $partNo,
                'item_code' => $itemCode,
                'description' => $description,
                'item_key' => $itemKey,
            ];
        }

        return $normalized;
    }

    private function kivItemKey(string $partNo, string $itemCode, string $description): string
    {
        return hash('sha256', implode("\0", [$partNo, $itemCode, $description]));
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
