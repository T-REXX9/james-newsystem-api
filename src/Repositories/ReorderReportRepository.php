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

        $where = [
            'itm.lmain_id = :main_id',
            'COALESCE(st.current_stock, 0) < ' . $targetExpr,
        ];
        $params = ['main_id' => $mainId];

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

        $countSql = <<<SQL
SELECT COUNT(*) AS total
FROM tblinventory_item itm
LEFT JOIN ({$stockSubquery}) st ON st.linvent_id = itm.lsession
WHERE {$whereSql}
SQL;
        $countStmt = $pdo->prepare($countSql);
        $this->bindParams($countStmt, $params);
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

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
    {$targetExpr} AS target_quantity
FROM tblinventory_item itm
LEFT JOIN ({$stockSubquery}) st ON st.linvent_id = itm.lsession
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
        $latestPrByItem = $this->fetchLatestPrByItemCode($itemCodes);
        $latestPoByItem = $this->fetchLatestPoByItemCode($itemCodes);
        $latestRrByItem = $this->fetchLatestRrByItemCode($itemCodes);
        $lastArrivalByItem = $isWarehouseSpecific
            ? $this->fetchLastTransferByItemCode($itemCodes, $selectedWarehouse)
            : $this->mapRrAsLastArrival($latestRrByItem);

        $mapped = [];
        foreach ($rows as $row) {
            $session = (string) ($row['product_session'] ?? '');
            $itemCode = (string) ($row['item_code'] ?? '');
            $pr = $latestPrByItem[$itemCode] ?? ['pr_refno' => '', 'pr_no' => ''];
            $po = $latestPoByItem[$itemCode] ?? ['po_refno' => '', 'po_no' => ''];
            $rr = $latestRrByItem[$itemCode] ?? ['rr_refno' => '', 'rr_no' => ''];
            $arrival = $lastArrivalByItem[$itemCode] ?? ['last_arrival_date' => '', 'last_arrival_qty' => 0];

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
                'total_rr' => (float) ($totalRrBySession[$session] ?? 0),
                'total_return' => (float) ($totalReturnBySession[$session] ?? 0),
                'target_quantity' => (float) ($row['target_quantity'] ?? 0),
                'pr_refno' => (string) ($pr['pr_refno'] ?? ''),
                'pr_no' => (string) ($pr['pr_no'] ?? ''),
                'po_refno' => (string) ($po['po_refno'] ?? ''),
                'po_no' => (string) ($po['po_no'] ?? ''),
                'rr_refno' => (string) ($rr['rr_refno'] ?? ''),
                'rr_no' => (string) ($rr['rr_no'] ?? ''),
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
    COALESCE(prl.lprno, '') AS pr_no
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
    COALESCE(pol.lpurchaseno, '') AS po_no
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
GROUP BY pi.litem_code, pi.lrefno, po.lpurchaseno, po.ldate
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
