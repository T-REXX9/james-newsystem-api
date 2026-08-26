<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class ReorderReportRepository
{
    private const WAREHOUSE_TYPES = ['wh1', 'wh2', 'wh3', 'wh4', 'wh5', 'wh6'];

    public function __construct(private readonly Database $db)
    {
    }

    public static function calculateSuggestedReorderQty(
        float $availableStock,
        float $reorderLevel,
        float $configuredReplenish,
        float $openPoQty
    ): float {
        $shortageToReorderLevel = max(0.0, $reorderLevel - $availableStock);
        $grossRequirement = max(max(0.0, $configuredReplenish), $shortageToReorderLevel);

        return max(0.0, $grossRequirement - max(0.0, $openPoQty));
    }

    public function listReport(
        int $mainId,
        string $warehouseType = 'total',
        string $search = '',
        int $page = 1,
        int $perPage = 100,
        bool $hideZeroReorder = false,
        bool $hideZeroReplenish = false,
        bool $includeHidden = false
    ): array {
        $normalizedWarehouseType = $this->normalizeWarehouseType($warehouseType);
        $cacheKey = $this->buildCacheKey([
            'purchasing_control_version' => 10,
            'main_id' => $mainId,
            'warehouse_type' => $normalizedWarehouseType,
            'search' => trim($search),
            'page' => $page,
            'per_page' => $perPage,
            'hide_zero_reorder' => $hideZeroReorder ? 1 : 0,
            'hide_zero_replenish' => $hideZeroReplenish ? 1 : 0,
            'include_hidden' => $includeHidden ? 1 : 0,
        ]);
        $cached = $this->readCache($cacheKey, 30);
        if ($cached !== null) {
            return $cached;
        }

        $pdo = $this->db->pdo();
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $offset = ($page - 1) * $perPage;
        $selectedWarehouse = $normalizedWarehouseType !== 'total' ? strtoupper($normalizedWarehouseType) : null;
        $isWarehouseSpecific = $selectedWarehouse !== null;

        $stockSubquery = $isWarehouseSpecific
            ? "SELECT lg.linvent_id, SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0)) AS current_stock
               FROM tblinventory_logs lg
               WHERE COALESCE(lg.lwarehouse, '') = " . $this->db->pdo()->quote($selectedWarehouse)
               . " GROUP BY lg.linvent_id"
            : "SELECT lg.linvent_id, SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0)) AS current_stock
               FROM tblinventory_logs lg
               GROUP BY lg.linvent_id";

        $targetExpr = $isWarehouseSpecific
            ? "CAST(COALESCE(NULLIF(itm.lreplenish, ''), '0') AS DECIMAL(15,2))"
            : "CAST(COALESCE(NULLIF(itm.lreorder_amt, ''), '0') AS DECIMAL(15,2))";

        $reservationSubquery = <<<SQL
SELECT
    COALESCE(NULLIF(TRIM(soi.litem_refno), ''), NULLIF(TRIM(soi.linv_refno), '')) AS item_session,
    SUM(COALESCE(soi.lqty, 0)) AS reserved_qty
FROM tbltransaction_item soi
INNER JOIN tbltransaction so ON so.lrefno = soi.lrefno
WHERE so.lmain_id = :reservation_main_id
  AND COALESCE(soi.lcancel, 0) = 0
  AND COALESCE(so.lcancel, 0) = 0
  AND LOWER(COALESCE(so.lsubmitstat, '')) IN ('approved', 'posted')
  AND LOWER(COALESCE(so.ltransaction_status, '')) NOT IN ('cancelled', 'canceled', 'unposted')
  AND TRIM(COALESCE(so.ldr_refno, '')) = ''
  AND TRIM(COALESCE(so.invoice_refno, '')) = ''
GROUP BY COALESCE(NULLIF(TRIM(soi.litem_refno), ''), NULLIF(TRIM(soi.linv_refno), ''))
SQL;

        $availableExpr = '(COALESCE(st.current_stock, 0) - COALESCE(res.reserved_qty, 0))';
        $activeWorkflowExpr = <<<SQL
(
    EXISTS (
        SELECT 1
        FROM tblpr_item active_pr_item
        INNER JOIN tblpr_list active_pr ON active_pr.lrefno = active_pr_item.lrefno
        WHERE (active_pr_item.litem_refno = itm.lsession OR active_pr_item.litem_code = itm.litemcode)
          AND LOWER(COALESCE(active_pr.lstatus, 'pending')) NOT IN ('cancelled', 'canceled', 'rejected', 'disapproved', 'completed', 'closed')
          AND TRIM(COALESCE(active_pr_item.lpo_refno, '')) = ''
    )
    OR EXISTS (
        SELECT 1
        FROM tblpo_itemlist active_po_item
        INNER JOIN tblpo_list active_po ON active_po.lrefno = active_po_item.lrefno
        WHERE active_po.lmain_id = itm.lmain_id
          AND (active_po_item.litem_refno = itm.lsession OR active_po_item.litem_code = itm.litemcode)
          AND LOWER(COALESCE(active_po.ltransaction_status, 'pending')) NOT IN ('cancelled', 'canceled', 'rejected', 'disapproved', 'completed', 'closed')
          AND COALESCE(active_po_item.lqty, 0) > COALESCE(active_po_item.lreceiving_qty, 0)
    )
)
SQL;

        $where = [
            'itm.lmain_id = :main_id',
            '(' . $availableExpr . ' <= ' . $targetExpr . ' OR ' . $activeWorkflowExpr . ')',
            // Items with a zero reorder level are not reorder candidates.
            "CAST(COALESCE(NULLIF(itm.lreorder_amt, ''), '0') AS DECIMAL(15,2)) > 0",
        ];
        $params = ['main_id' => $mainId];
        $params['reservation_main_id'] = $mainId;

        if (!$includeHidden) {
            $where[] = 'COALESCE(itm.lstatus, 0) = 1';
        }

        if ($hideZeroReorder) {
            $where[] = "CAST(COALESCE(NULLIF(itm.lreorder_amt, ''), '0') AS DECIMAL(15,2)) > 0";
        }
        if ($hideZeroReplenish) {
            $where[] = "CAST(COALESCE(NULLIF(itm.lreplenish, ''), '0') AS DECIMAL(15,2)) > 0";
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $where[] = "CONCAT_WS(' ', COALESCE(itm.litemcode, ''), COALESCE(itm.lpartno, ''), COALESCE(itm.ldescription, '')) LIKE :search";
            $params['search'] = '%' . $trimmedSearch . '%';
        }
        $whereSql = implode(' AND ', $where);

        $canonicalItemsSql = <<<SQL
SELECT
    MAX(lid) AS lid,
    lsession,
    MAX(lmain_id) AS lmain_id,
    MAX(COALESCE(litemcode, '')) AS litemcode,
    MAX(COALESCE(lpartno, '')) AS lpartno,
    MAX(COALESCE(ldescription, '')) AS ldescription,
    MAX(COALESCE(lstatus, 0)) AS lstatus,
    MAX(COALESCE(NULLIF(lreorder_amt, ''), '0')) AS lreorder_amt,
    MAX(COALESCE(NULLIF(lreplenish, ''), '0')) AS lreplenish
FROM tblinventory_item
WHERE lmain_id = :canonical_main_id
  AND TRIM(COALESCE(lsession, '')) <> ''
GROUP BY lsession
SQL;
        $params['canonical_main_id'] = $mainId;

        $listSql = <<<SQL
    SELECT
    CAST(itm.lid AS UNSIGNED) AS id,
    COALESCE(itm.lsession, '') AS product_session,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.ldescription, '') AS description,
    CASE WHEN COALESCE(itm.lstatus, 0) = 1 THEN 0 ELSE 1 END AS is_hidden,
    CAST(COALESCE(NULLIF(itm.lreorder_amt, ''), '0') AS DECIMAL(15,2)) AS reorder_qty,
    CAST(COALESCE(NULLIF(itm.lreplenish, ''), '0') AS DECIMAL(15,2)) AS replenish_qty,
    CAST(COALESCE(st.current_stock, 0) AS DECIMAL(15,2)) AS current_stock,
    CAST(COALESCE(res.reserved_qty, 0) AS DECIMAL(15,2)) AS reserved_stock,
    CAST({$availableExpr} AS DECIMAL(15,2)) AS available_stock,
    {$targetExpr} AS target_quantity,
    COUNT(*) OVER() AS report_total
FROM ({$canonicalItemsSql}) itm
LEFT JOIN ({$stockSubquery}) st ON st.linvent_id = itm.lsession
LEFT JOIN ({$reservationSubquery}) res ON res.item_session = itm.lsession
WHERE {$whereSql}
ORDER BY itm.ldescription ASC, itm.lpartno ASC, itm.litemcode ASC
LIMIT :limit OFFSET :offset
SQL;
        $stmt = $pdo->prepare($listSql);
        $this->bindParams($stmt, $params);
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = (int) ($rows[0]['report_total'] ?? 0);

        if (count($rows) === 0) {
            $result = [
                'items' => [],
                'meta' => [
                        'page' => $page,
                        'per_page' => $perPage,
                        'total' => $total,
                        'total_pages' => (int) ceil($total / max(1, $perPage)),
                        'filters' => [
                            'main_id' => $mainId,
                            'warehouse_type' => $normalizedWarehouseType,
                            'search' => $trimmedSearch,
                            'hide_zero_reorder' => $hideZeroReorder,
                            'hide_zero_replenish' => $hideZeroReplenish,
                    ],
                ],
            ];
            $this->writeCache($cacheKey, $result);
            return $result;
        }

        $sessions = [];
        $itemCodes = [];
        foreach ($rows as $row) {
            $session = (string) ($row['product_session'] ?? '');
            $itemCode = (string) ($row['item_code'] ?? '');
            if ($session !== '') $sessions[] = $session;
            if ($itemCode !== '') $itemCodes[] = $itemCode;
        }
        $sessions = array_values(array_unique($sessions));
        $itemCodes = array_values(array_unique($itemCodes));

        $totalRrBySession = $this->fetchTotalRrBySession($sessions);
        $totalReturnBySession = $this->fetchTotalReturnBySession($sessions);
        $preferredSuppliers = $this->fetchPreferredSuppliers($mainId, $sessions);
        $prDocumentsByItem = $this->fetchOpenPrDocuments($sessions, $itemCodes);
        $poDocumentsByItem = $this->fetchOpenPoDocuments($mainId, $sessions, $itemCodes);
        $openPoRefnos = [];
        foreach ($poDocumentsByItem as $documents) {
            foreach ($documents as $document) {
                $refno = trim((string) ($document['refno'] ?? ''));
                if ($refno !== '') $openPoRefnos[$refno] = true;
            }
        }
        $rrDocumentsByItem = $this->fetchReceivingDocuments($sessions, $itemCodes, array_keys($openPoRefnos));
        $latestRrByItem = $this->fetchLatestRrByItemCode($itemCodes);
        $lastArrivalByItem = $isWarehouseSpecific
            ? $this->fetchLastTransferByItemCode($itemCodes, $selectedWarehouse)
            : $this->mapRrAsLastArrival($latestRrByItem);

        $mapped = [];
        foreach ($rows as $row) {
            $session = (string) ($row['product_session'] ?? '');
            $itemCode = (string) ($row['item_code'] ?? '');
            $arrival = $lastArrivalByItem[$itemCode] ?? ['last_arrival_date' => '', 'last_arrival_qty' => 0];
            $preferredSupplier = $preferredSuppliers[$session] ?? [
                'supplier_id' => '',
                'supplier_name' => '',
                'supplier_cost' => 0,
            ];
            $prDocuments = $prDocumentsByItem[$session] ?? $prDocumentsByItem['code:' . $itemCode] ?? [];
            $poDocuments = $poDocumentsByItem[$session] ?? $poDocumentsByItem['code:' . $itemCode] ?? [];
            $rrDocuments = $rrDocumentsByItem[$session] ?? $rrDocumentsByItem['code:' . $itemCode] ?? [];
            $rowOpenPoRefnos = array_values(array_filter(array_map(
                static fn (array $document): string => trim((string) ($document['refno'] ?? '')),
                $poDocuments
            )));
            $rrDocuments = $rowOpenPoRefnos === []
                ? []
                : array_values(array_filter(
                    $rrDocuments,
                    static fn (array $document): bool => in_array(
                        trim((string) ($document['po_refno'] ?? '')),
                        $rowOpenPoRefnos,
                        true
                    )
                ));

            $requestedPrQty = 0.0;
            $openPrQty = 0.0;
            $hasPendingPr = false;
            $hasApprovedPr = false;
            foreach ($prDocuments as $document) {
                $requestedPrQty += (float) ($document['requested_qty'] ?? 0);
                if (trim((string) ($document['po_refno'] ?? '')) !== '') continue;
                $openPrQty += (float) ($document['requested_qty'] ?? 0);
                $status = strtolower(trim((string) ($document['status'] ?? '')));
                if ($status === 'approved') $hasApprovedPr = true;
                else $hasPendingPr = true;
            }

            $orderedQty = 0.0;
            $acceptedQty = 0.0;
            $openPoQty = 0.0;
            $recordedOutstandingQty = 0.0;
            $hasPendingPo = false;
            $isOverdue = false;
            foreach ($poDocuments as $document) {
                // A pending PO is still a real document with a visible ordered
                // quantity. It only becomes stock "on order" after posting.
                $orderedQty += (float) ($document['ordered_qty'] ?? 0);
                $acceptedQty += (float) ($document['accepted_qty'] ?? 0);
                $recordedOutstandingQty += (float) ($document['outstanding_qty'] ?? 0);
                $status = strtolower(trim((string) ($document['status'] ?? '')));
                $isOnOrder = in_array($status, ['posted', 'approved', 'ordered', 'awaiting delivery'], true);
                if (!$isOnOrder) {
                    $hasPendingPo = true;
                    continue;
                }
                $openPoQty += (float) ($document['outstanding_qty'] ?? 0);
                $eta = trim((string) ($document['expected_delivery_date'] ?? ''));
                if ($eta !== '' && $eta !== '1970-01-01' && $eta < date('Y-m-d') && (float) ($document['outstanding_qty'] ?? 0) > 0) {
                    $isOverdue = true;
                }
            }

            $physicallyReceivedQty = 0.0;
            foreach ($rrDocuments as $document) {
                $physicallyReceivedQty += (float) ($document['received_qty'] ?? 0);
            }

            $availableStock = (float) ($row['available_stock'] ?? 0);
            $reorderLevel = (float) ($row['reorder_qty'] ?? 0);
            $configuredReplenish = (float) ($row['replenish_qty'] ?? 0);
            $suggestedReorderQty = self::calculateSuggestedReorderQty(
                $availableStock,
                $reorderLevel,
                $configuredReplenish,
                $openPoQty
            );

            if ($isOverdue) {
                $overallStatus = 'Overdue';
            } elseif ($acceptedQty > 0 && $openPoQty > 0) {
                $overallStatus = 'Partially Received';
            } elseif ($openPoQty > 0) {
                $overallStatus = 'Ordered';
            } elseif ($hasPendingPo || $hasApprovedPr) {
                $overallStatus = 'Awaiting PO';
            } elseif ($hasPendingPr) {
                $overallStatus = 'PR Pending';
            } else {
                // This is an active-control report, not purchasing history. Once
                // a cycle is completed or cancelled its documents are excluded by
                // the open-document queries above. If the item is still below its
                // threshold, it begins a clean reorder cycle here.
                $overallStatus = 'Needs PR';
            }

            $currentPr = $prDocuments !== [] ? $prDocuments[array_key_last($prDocuments)] : null;
            $currentPo = $poDocuments !== [] ? $poDocuments[array_key_last($poDocuments)] : null;
            $currentRr = $rrDocuments !== [] ? $rrDocuments[array_key_last($rrDocuments)] : null;

            $mapped[] = [
                'id' => (int) ($row['id'] ?? 0),
                'product_session' => $session,
                'item_code' => $itemCode,
                'part_no' => (string) ($row['part_no'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'is_hidden' => (bool) ((int) ($row['is_hidden'] ?? 0)),
                'reorder_qty' => (float) ($row['reorder_qty'] ?? 0),
                'replenish_qty' => (float) ($row['replenish_qty'] ?? 0),
                'current_stock' => (float) ($row['current_stock'] ?? 0),
                'physical_stock' => (float) ($row['current_stock'] ?? 0),
                'reserved_stock' => (float) ($row['reserved_stock'] ?? 0),
                'available_stock' => $availableStock,
                'total_rr' => (float) ($totalRrBySession[$session] ?? 0),
                'total_return' => (float) ($totalReturnBySession[$session] ?? 0),
                'target_quantity' => (float) ($row['target_quantity'] ?? 0),
                'suggested_reorder_qty' => $suggestedReorderQty,
                'pr_requested_qty' => $requestedPrQty,
                'open_pr_qty' => $openPrQty,
                'po_ordered_qty' => $orderedQty,
                'open_po_qty' => $openPoQty,
                'received_qty' => $physicallyReceivedQty,
                'accepted_qty' => $acceptedQty,
                'remaining_qty' => $recordedOutstandingQty,
                'preferred_supplier_id' => (string) ($preferredSupplier['supplier_id'] ?? ''),
                'preferred_supplier_name' => (string) ($preferredSupplier['supplier_name'] ?? ''),
                'preferred_supplier_cost' => (float) ($preferredSupplier['supplier_cost'] ?? 0),
                'overall_status' => $overallStatus,
                'can_create_pr' => !in_array($overallStatus, ['PR Pending', 'Awaiting PO', 'Ordered', 'Partially Received', 'Overdue'], true),
                'pr_documents' => $prDocuments,
                'po_documents' => $poDocuments,
                'rr_documents' => $rrDocuments,
                'pr_refno' => (string) ($currentPr['refno'] ?? ''),
                'pr_no' => (string) ($currentPr['number'] ?? ''),
                'pr_status' => (string) ($currentPr['status'] ?? ''),
                'po_refno' => (string) ($currentPo['refno'] ?? ''),
                'po_no' => (string) ($currentPo['number'] ?? ''),
                'po_status' => (string) ($currentPo['status'] ?? ''),
                'rr_refno' => (string) ($currentRr['refno'] ?? ''),
                'rr_no' => (string) ($currentRr['number'] ?? ''),
                'rr_status' => (string) ($currentRr['status'] ?? ''),
                'last_arrival_date' => (string) ($arrival['last_arrival_date'] ?? ''),
                'last_arrival_qty' => (float) ($arrival['last_arrival_qty'] ?? 0),
            ];
        }

        $result = [
            'items' => $mapped,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
                'filters' => [
                    'main_id' => $mainId,
                    'warehouse_type' => $normalizedWarehouseType,
                    'search' => $trimmedSearch,
                    'hide_zero_reorder' => $hideZeroReorder,
                    'hide_zero_replenish' => $hideZeroReplenish,
                ],
            ],
        ];
        $this->writeCache($cacheKey, $result);
        return $result;
    }

    /**
     * @param array<int, int> $itemIds
     */
    public function hideItems(int $mainId, array $itemIds): int
    {
        if (count($itemIds) === 0) return 0;
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $sql = sprintf(
            'UPDATE tblinventory_item SET lstatus = 0 WHERE lmain_id = ? AND lid IN (%s)',
            $placeholders
        );
        $stmt = $this->db->pdo()->prepare($sql);
        $values = array_merge([$mainId], $itemIds);
        foreach ($values as $index => $value) {
            $stmt->bindValue($index + 1, (int) $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        $this->clearCache();
        return $stmt->rowCount();
    }

    /**
     * @param array<int, int> $itemIds
     */
    public function restoreItems(int $mainId, array $itemIds): int
    {
        if (count($itemIds) === 0) return 0;
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $sql = sprintf(
            'UPDATE tblinventory_item SET lstatus = 1 WHERE lmain_id = ? AND lstatus = 0 AND lid IN (%s)',
            $placeholders
        );
        $stmt = $this->db->pdo()->prepare($sql);
        $values = array_merge([$mainId], $itemIds);
        foreach ($values as $index => $value) {
            $stmt->bindValue($index + 1, (int) $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        $this->clearCache();
        return $stmt->rowCount();
    }

    /**
     * @param array<int, string> $sessions
     * @return array<string, array{supplier_id:string,supplier_name:string,supplier_cost:float}>
     */
    private function fetchPreferredSuppliers(int $mainId, array $sessions): array
    {
        if ($sessions === []) return [];
        [$inClause, $bind] = $this->buildInClause($sessions, 'supplier_session');
        $bind['supplier_main_id'] = (string) $mainId;
        $sql = <<<SQL
SELECT
    sc.litemsession AS item_session,
    COALESCE(sc.lsupplier_id, '') AS supplier_id,
    COALESCE(NULLIF(TRIM(s.lcompany), ''), NULLIF(TRIM(s.lname), ''), sc.lsupplier_name, '') AS supplier_name,
    CAST(COALESCE(sc.lcost, 0) AS DECIMAL(15,2)) AS supplier_cost
FROM tblsupplier_cost sc
INNER JOIN (
    SELECT litemsession, MAX(lid) AS max_lid
    FROM tblsupplier_cost
    WHERE lmainid = :supplier_main_id
      AND litemsession IN ({$inClause})
    GROUP BY litemsession
) latest ON latest.max_lid = sc.lid
LEFT JOIN tblsupplier s ON CAST(s.lid AS CHAR) = sc.lsupplier_id
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $bind);
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $session = trim((string) ($row['item_session'] ?? ''));
            if ($session === '') continue;
            $result[$session] = [
                'supplier_id' => (string) ($row['supplier_id'] ?? ''),
                'supplier_name' => (string) ($row['supplier_name'] ?? ''),
                'supplier_cost' => (float) ($row['supplier_cost'] ?? 0),
            ];
        }
        return $result;
    }

    /**
     * @param array<int, string> $sessions
     * @param array<int, string> $itemCodes
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function fetchOpenPrDocuments(array $sessions, array $itemCodes): array
    {
        if ($sessions === [] && $itemCodes === []) return [];
        [$sessionClause, $sessionBind] = $this->buildInClause($sessions, 'pr_session');
        [$codeClause, $codeBind] = $this->buildInClause($itemCodes, 'pr_code');
        $identifiers = [];
        if ($sessions !== []) $identifiers[] = 'pri.litem_refno IN (' . $sessionClause . ')';
        if ($itemCodes !== []) $identifiers[] = 'pri.litem_code IN (' . $codeClause . ')';
        $sql = <<<SQL
SELECT
    COALESCE(pri.litem_refno, '') AS item_session,
    COALESCE(pri.litem_code, '') AS item_code,
    COALESCE(pri.lrefno, '') AS refno,
    COALESCE(prl.lprno, pri.lrefno, '') AS number,
    CAST(COALESCE(NULLIF(pri.lqty, ''), '0') AS DECIMAL(15,2)) AS requested_qty,
    COALESCE(prl.ldatetime, '') AS request_date,
    CASE
        WHEN LOWER(COALESCE(prl.lstatus, '')) IN ('cancelled', 'canceled', 'rejected', 'disapproved') THEN 'Cancelled'
        WHEN LOWER(COALESCE(prl.lapproval, '')) = 'approved' THEN 'Approved'
        ELSE 'Pending Approval'
    END AS status,
    COALESCE(pri.lsupp_id, '') AS supplier_id,
    COALESCE(pri.lsupp_name, '') AS supplier_name,
    COALESCE(pri.lpo_refno, '') AS po_refno
FROM tblpr_item pri
INNER JOIN tblpr_list prl ON prl.lrefno = pri.lrefno
WHERE (%s)
  AND LOWER(COALESCE(prl.lstatus, 'pending')) NOT IN ('cancelled', 'canceled', 'rejected', 'disapproved', 'completed', 'closed')
  AND (
      TRIM(COALESCE(pri.lpo_refno, '')) = ''
      OR EXISTS (
          SELECT 1
          FROM tblpo_itemlist linked_po_item
          INNER JOIN tblpo_list linked_po ON linked_po.lrefno = linked_po_item.lrefno
          WHERE linked_po.lrefno = pri.lpo_refno
            AND linked_po_item.litem_refno = pri.litem_refno
            AND LOWER(COALESCE(linked_po.ltransaction_status, 'pending')) NOT IN ('cancelled', 'canceled', 'rejected', 'disapproved', 'completed', 'closed')
            AND COALESCE(linked_po_item.lqty, 0) > COALESCE(linked_po_item.lreceiving_qty, 0)
      )
  )
ORDER BY pri.lid ASC
SQL;
        $stmt = $this->db->pdo()->prepare(sprintf($sql, implode(' OR ', $identifiers)));
        $this->bindParams($stmt, array_merge($sessionBind, $codeBind));
        $stmt->execute();
        return $this->indexDocumentsByItem($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<int, string> $sessions
     * @param array<int, string> $itemCodes
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function fetchOpenPoDocuments(int $mainId, array $sessions, array $itemCodes): array
    {
        if ($sessions === [] && $itemCodes === []) return [];
        [$sessionClause, $sessionBind] = $this->buildInClause($sessions, 'po_session');
        [$codeClause, $codeBind] = $this->buildInClause($itemCodes, 'po_code');
        $identifiers = [];
        if ($sessions !== []) $identifiers[] = 'poi.litem_refno IN (' . $sessionClause . ')';
        if ($itemCodes !== []) $identifiers[] = 'poi.litem_code IN (' . $codeClause . ')';
        $bind = array_merge($sessionBind, $codeBind, ['po_main_id' => $mainId]);
        $sql = <<<SQL
SELECT
    COALESCE(poi.litem_refno, '') AS item_session,
    COALESCE(poi.litem_code, '') AS item_code,
    COALESCE(poi.lrefno, '') AS refno,
    COALESCE(pol.lpurchaseno, poi.lrefno, '') AS number,
    COALESCE(pol.ltransaction_status, 'Pending') AS status,
    COALESCE(pol.lsupplier, poi.lsupp_id, '') AS supplier_id,
    COALESCE(NULLIF(TRIM(pol.lsupplier_name), ''), poi.lsupp_name, '') AS supplier_name,
    CAST(COALESCE(poi.lqty, 0) AS DECIMAL(15,2)) AS ordered_qty,
    CAST(COALESCE(poi.lreceiving_qty, 0) AS DECIMAL(15,2)) AS accepted_qty,
    CAST(GREATEST(COALESCE(poi.lqty, 0) - COALESCE(poi.lreceiving_qty, 0), 0) AS DECIMAL(15,2)) AS outstanding_qty,
    CAST(COALESCE(NULLIF(poi.lsup_price, ''), '0') AS DECIMAL(15,2)) AS unit_cost,
    COALESCE(pol.ldate, '') AS order_date,
    COALESCE(poi.leta_date, '') AS expected_delivery_date,
    COALESCE(pol.lpr_refno, '') AS pr_refno,
    COALESCE(pol.lpr_no, '') AS pr_number
FROM tblpo_itemlist poi
INNER JOIN tblpo_list pol ON pol.lrefno = poi.lrefno
WHERE pol.lmain_id = :po_main_id
  AND (%s)
  AND LOWER(COALESCE(pol.ltransaction_status, 'pending')) NOT IN ('cancelled', 'canceled', 'rejected', 'disapproved', 'completed', 'closed')
  AND COALESCE(poi.lqty, 0) > COALESCE(poi.lreceiving_qty, 0)
ORDER BY poi.lid ASC
SQL;
        $stmt = $this->db->pdo()->prepare(sprintf($sql, implode(' OR ', $identifiers)));
        $this->bindParams($stmt, $bind);
        $stmt->execute();
        return $this->indexDocumentsByItem($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<int, string> $sessions
     * @param array<int, string> $itemCodes
     * @param array<int, string> $poRefnos
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function fetchReceivingDocuments(array $sessions, array $itemCodes, array $poRefnos): array
    {
        if ($poRefnos === [] || ($sessions === [] && $itemCodes === [])) return [];
        [$sessionClause, $sessionBind] = $this->buildInClause($sessions, 'rr_session');
        [$codeClause, $codeBind] = $this->buildInClause($itemCodes, 'rr_code');
        [$poClause, $poBind] = $this->buildInClause($poRefnos, 'rr_po');
        $identifiers = [];
        if ($sessions !== []) $identifiers[] = 'pi.litem_refno IN (' . $sessionClause . ')';
        if ($itemCodes !== []) $identifiers[] = 'pi.litem_code IN (' . $codeClause . ')';
        $sql = <<<SQL
SELECT
    COALESCE(pi.litem_refno, '') AS item_session,
    COALESCE(pi.litem_code, '') AS item_code,
    COALESCE(rr.lrefno, '') AS refno,
    COALESCE(rr.lpurchaseno, rr.lrefno, '') AS number,
    COALESCE(rr.ltransaction_status, 'Pending') AS status,
    COALESCE(rr.lpo_refno, '') AS po_refno,
    COALESCE(rr.lpo_number, '') AS po_number,
    CAST(SUM(COALESCE(pi.lqty, 0)) AS DECIMAL(15,2)) AS received_qty,
    CAST(SUM(CASE
        WHEN LOWER(COALESCE(rr.ltransaction_status, '')) IN ('posted', 'received', 'delivered', 'completed')
        THEN COALESCE(pi.lqty, 0)
        ELSE 0
    END) AS DECIMAL(15,2)) AS accepted_qty,
    COALESCE(rr.ldate_recieved, rr.ldate, '') AS receiving_date,
    COALESCE(rr.luser, '') AS received_by
FROM tblpurchase_item pi
INNER JOIN tblpurchase_order rr ON rr.lrefno = pi.lrefno
WHERE rr.lpo_refno IN ({$poClause})
  AND (%s)
GROUP BY pi.litem_refno, pi.litem_code, rr.lrefno, rr.lpurchaseno, rr.ltransaction_status,
    rr.lpo_refno, rr.lpo_number, rr.ldate_recieved, rr.ldate, rr.luser
ORDER BY MAX(pi.lid) ASC
SQL;
        $stmt = $this->db->pdo()->prepare(sprintf($sql, implode(' OR ', $identifiers)));
        $this->bindParams($stmt, array_merge($sessionBind, $codeBind, $poBind));
        $stmt->execute();
        return $this->indexDocumentsByItem($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function indexDocumentsByItem(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $session = trim((string) ($row['item_session'] ?? ''));
            $itemCode = trim((string) ($row['item_code'] ?? ''));
            $keys = [];
            if ($session !== '') $keys[] = $session;
            if ($itemCode !== '') $keys[] = 'code:' . $itemCode;
            foreach (array_values(array_unique($keys)) as $key) {
                $result[$key] ??= [];
                $result[$key][] = $row;
            }
        }
        return $result;
    }

    private function normalizeWarehouseType(string $warehouseType): string
    {
        $normalized = strtolower(trim($warehouseType));
        return in_array($normalized, self::WAREHOUSE_TYPES, true) ? $normalized : 'total';
    }

    private function buildCacheKey(array $payload): string
    {
        return md5(json_encode($payload));
    }

    private function cacheFile(string $cacheKey): string
    {
        return rtrim((string) sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'james_reorder_cache_' . $cacheKey . '.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(string $cacheKey, int $ttlSeconds): ?array
    {
        $file = $this->cacheFile($cacheKey);
        if (!is_file($file)) return null;
        $mtime = @filemtime($file);
        if ($mtime === false || (time() - $mtime) > $ttlSeconds) return null;
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeCache(string $cacheKey, array $payload): void
    {
        $file = $this->cacheFile($cacheKey);
        @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function clearCache(): void
    {
        $pattern = rtrim((string) sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'james_reorder_cache_*.json';
        $files = glob($pattern);
        if (!is_array($files)) return;
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /**
     * @param array<int, string> $sessions
     * @return array<string, float>
     */
    private function fetchTotalRrBySession(array $sessions): array
    {
        if (count($sessions) === 0) return [];
        [$inClause, $bind] = $this->buildInClause($sessions, 'sess');
        $sql = <<<SQL
SELECT litem_refno AS session, SUM(COALESCE(lqty, 0)) AS total_rr
FROM tblpurchase_item
WHERE litem_refno IN ({$inClause})
GROUP BY litem_refno
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $bind);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) ($row['session'] ?? '')] = (float) ($row['total_rr'] ?? 0);
        }
        return $map;
    }

    /**
     * @param array<int, string> $sessions
     * @return array<string, float>
     */
    private function fetchTotalReturnBySession(array $sessions): array
    {
        if (count($sessions) === 0) return [];
        [$inClauseA, $bindA] = $this->buildInClause($sessions, 'retA');
        [$inClauseB, $bindB] = $this->buildInClause($sessions, 'retB');

        $sql = <<<SQL
SELECT session, SUM(total_qty) AS total_return
FROM (
    SELECT linv_refno AS session, SUM(COALESCE(lqty, 0)) AS total_qty
    FROM tblcredit_return_item
    WHERE linv_refno IN ({$inClauseA})
    GROUP BY linv_refno
    UNION ALL
    SELECT litem_refno AS session, SUM(COALESCE(lqty, 0)) AS total_qty
    FROM tblcredit_return_item
    WHERE litem_refno IN ({$inClauseB})
    GROUP BY litem_refno
) x
GROUP BY session
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, array_merge($bindA, $bindB));
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) ($row['session'] ?? '')] = (float) ($row['total_return'] ?? 0);
        }
        return $map;
    }

    /**
     * @param array<int, string> $itemCodes
     * @return array<string, array{pr_refno:string,pr_no:string}>
     */
    private function fetchLatestPrByItemCode(array $itemCodes): array
    {
        if (count($itemCodes) === 0) return [];
        [$inClause, $bind] = $this->buildInClause($itemCodes, 'pr');
        $sql = <<<SQL
SELECT
    pri.litem_code,
    pri.lrefno AS pr_refno,
    COALESCE(prl.lprno, '') AS pr_no,
    CASE
        WHEN LOWER(COALESCE(prl.lstatus, '')) IN ('cancelled', 'canceled', 'rejected', 'disapproved') THEN 'Cancelled'
        WHEN LOWER(COALESCE(prl.lapproval, '')) = 'approved' THEN 'Approved'
        ELSE COALESCE(prl.lstatus, 'Pending')
    END AS pr_status
FROM tblpr_item pri
INNER JOIN (
    SELECT litem_code, MAX(lid) AS max_lid
    FROM tblpr_item
    WHERE litem_code IN ({$inClause})
    GROUP BY litem_code
) latest ON latest.max_lid = pri.lid
LEFT JOIN tblpr_list prl ON prl.lrefno = pri.lrefno
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $bind);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $item = (string) ($row['litem_code'] ?? '');
            $map[$item] = [
                'pr_refno' => (string) ($row['pr_refno'] ?? ''),
                'pr_no' => (string) ($row['pr_no'] ?? ''),
                'pr_status' => (string) ($row['pr_status'] ?? ''),
            ];
        }
        return $map;
    }

    /**
     * @param array<int, string> $itemCodes
     * @return array<string, array{po_refno:string,po_no:string}>
     */
    private function fetchLatestPoByItemCode(array $itemCodes): array
    {
        if (count($itemCodes) === 0) return [];
        [$inClause, $bind] = $this->buildInClause($itemCodes, 'po');
        $sql = <<<SQL
SELECT
    poi.litem_code,
    poi.lrefno AS po_refno,
    COALESCE(pol.lpurchaseno, '') AS po_no,
    COALESCE(pol.ltransaction_status, 'Pending') AS po_status
FROM tblpo_itemlist poi
INNER JOIN (
    SELECT litem_code, MAX(lid) AS max_lid
    FROM tblpo_itemlist
    WHERE litem_code IN ({$inClause})
    GROUP BY litem_code
) latest ON latest.max_lid = poi.lid
LEFT JOIN tblpo_list pol ON pol.lrefno = poi.lrefno
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $bind);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $item = (string) ($row['litem_code'] ?? '');
            $map[$item] = [
                'po_refno' => (string) ($row['po_refno'] ?? ''),
                'po_no' => (string) ($row['po_no'] ?? ''),
                'po_status' => (string) ($row['po_status'] ?? ''),
            ];
        }
        return $map;
    }

    /**
     * @param array<int, string> $itemCodes
     * @return array<string, array{rr_refno:string,rr_no:string,last_arrival_date:string,last_arrival_qty:float}>
     */
    private function fetchLatestRrByItemCode(array $itemCodes): array
    {
        if (count($itemCodes) === 0) return [];
        [$inClause, $bind] = $this->buildInClause($itemCodes, 'rr');
        $sql = <<<SQL
SELECT
    pi.litem_code,
    pi.lrefno AS rr_refno,
    COALESCE(po.lpurchaseno, '') AS rr_no,
    COALESCE(po.ltransaction_status, 'Pending') AS rr_status,
    COALESCE(po.ldate, '') AS last_arrival_date,
    SUM(COALESCE(pi2.lqty, 0)) AS last_arrival_qty
FROM tblpurchase_item pi
INNER JOIN (
    SELECT litem_code, MAX(lid) AS max_lid
    FROM tblpurchase_item
    WHERE litem_code IN ({$inClause})
    GROUP BY litem_code
) latest ON latest.max_lid = pi.lid
LEFT JOIN tblpurchase_item pi2
    ON pi2.litem_code = pi.litem_code
   AND pi2.lrefno = pi.lrefno
LEFT JOIN tblpurchase_order po
    ON po.lrefno = pi.lrefno
GROUP BY pi.litem_code, pi.lrefno, po.lpurchaseno, po.ltransaction_status, po.ldate
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $bind);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $item = (string) ($row['litem_code'] ?? '');
            $map[$item] = [
                'rr_refno' => (string) ($row['rr_refno'] ?? ''),
                'rr_no' => (string) ($row['rr_no'] ?? ''),
                'rr_status' => (string) ($row['rr_status'] ?? ''),
                'last_arrival_date' => (string) ($row['last_arrival_date'] ?? ''),
                'last_arrival_qty' => (float) ($row['last_arrival_qty'] ?? 0),
            ];
        }
        return $map;
    }

    /**
     * @param array<int, string> $itemCodes
     * @return array<string, array{last_arrival_date:string,last_arrival_qty:float}>
     */
    private function fetchLastTransferByItemCode(array $itemCodes, string $warehouse): array
    {
        if (count($itemCodes) === 0) return [];
        [$inClause, $bind] = $this->buildInClause($itemCodes, 'tr');
        $bind['warehouse'] = $warehouse;
        $sql = <<<SQL
SELECT
    trp.litemcode,
    COALESCE(trl.ltimestamp, '') AS last_arrival_date,
    SUM(COALESCE(trp2.ltransfer_qty, 0)) AS last_arrival_qty
FROM tblbranchinventory_transferproducts trp
INNER JOIN (
    SELECT litemcode, MAX(lid) AS max_lid
    FROM tblbranchinventory_transferproducts
    WHERE lwarehouse_to = :warehouse
      AND litemcode IN ({$inClause})
    GROUP BY litemcode
) latest ON latest.max_lid = trp.lid
LEFT JOIN tblbranchinventory_transferproducts trp2
    ON trp2.litemcode = trp.litemcode
   AND trp2.lrefno = trp.lrefno
LEFT JOIN tblbranchinventory_transferlist trl
    ON trl.lrefno = trp.lrefno
GROUP BY trp.litemcode, trl.ltimestamp
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $bind);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $item = (string) ($row['litemcode'] ?? '');
            $map[$item] = [
                'last_arrival_date' => (string) ($row['last_arrival_date'] ?? ''),
                'last_arrival_qty' => (float) ($row['last_arrival_qty'] ?? 0),
            ];
        }
        return $map;
    }

    /**
     * @param array<string, array{rr_refno:string,rr_no:string,last_arrival_date:string,last_arrival_qty:float}> $rrMap
     * @return array<string, array{last_arrival_date:string,last_arrival_qty:float}>
     */
    private function mapRrAsLastArrival(array $rrMap): array
    {
        $mapped = [];
        foreach ($rrMap as $itemCode => $row) {
            $mapped[$itemCode] = [
                'last_arrival_date' => (string) ($row['last_arrival_date'] ?? ''),
                'last_arrival_qty' => (float) ($row['last_arrival_qty'] ?? 0),
            ];
        }
        return $mapped;
    }

    /**
     * @param array<int, string> $values
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildInClause(array $values, string $prefix): array
    {
        $bind = [];
        $tokens = [];
        foreach (array_values($values) as $index => $value) {
            $key = $prefix . '_' . $index;
            $tokens[] = ':' . $key;
            $bind[$key] = $value;
        }
        return [implode(',', $tokens), $bind];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bindParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue((string) $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
