<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class InventoryAuditRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function report(
        int $mainId,
        string $timePeriod,
        string $dateFrom,
        string $dateTo,
        string $partNo,
        string $itemCode,
        int $page,
        int $perPage
    ): array {
        $perPage = min(200, max(1, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        [$from, $to] = $this->resolveDateRange($timePeriod, $dateFrom, $dateTo);

        $baseWhere = [
            'itm.lmain_id = :main_id',
            'sa.litemsession = itm.lsession',
            'COALESCE(sa.ladjust_qty, 0) <> 0',
        ];
        $baseParams = [
            'main_id' => $mainId,
        ];

        if ($from !== null && $to !== null) {
            $baseWhere[] = 'sa.ldatetime >= :date_from';
            $baseWhere[] = 'sa.ldatetime <= :date_to';
            $baseParams['date_from'] = $from;
            $baseParams['date_to'] = $to;
        }

        $normalizedPartNo = trim($partNo);
        if ($normalizedPartNo !== '' && strcasecmp($normalizedPartNo, 'All') !== 0) {
            $baseWhere[] = 'TRIM(itm.lpartno) = TRIM(:part_no)';
            $baseParams['part_no'] = $normalizedPartNo;
        }

        $normalizedItemCode = trim($itemCode);
        if ($normalizedItemCode !== '' && strcasecmp($normalizedItemCode, 'All') !== 0) {
            $baseWhere[] = 'itm.litemcode LIKE :item_code';
            $baseParams['item_code'] = '%' . $normalizedItemCode . '%';
        }

        $whereSql = implode(' AND ', $baseWhere);

        $totalItemsSql = <<<SQL
SELECT COUNT(*) AS total
FROM (
    SELECT itm.lsession
    FROM tblinventory_item itm
    INNER JOIN tblstock_adjustment_item sa ON {$whereSql}
    GROUP BY itm.lsession
) grouped
SQL;
        $totalStmt = $this->db->pdo()->prepare($totalItemsSql);
        $this->bindParams($totalStmt, $baseParams);
        $totalStmt->execute();
        $totalItems = (int) ($totalStmt->fetchColumn() ?: 0);

        $itemSql = <<<SQL
SELECT
    itm.lsession AS item_session,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.ldescription, '') AS description,
    COALESCE(br.lname, itm.lbrand, '') AS brand
FROM tblinventory_item itm
LEFT JOIN tblbrand br ON br.lid = itm.lbrand
INNER JOIN tblstock_adjustment_item sa ON {$whereSql}
GROUP BY itm.lsession, itm.litemcode, itm.lpartno, itm.ldescription, br.lname, itm.lbrand
ORDER BY itm.lpartno ASC, itm.litemcode ASC
LIMIT :limit OFFSET :offset
SQL;
        $itemStmt = $this->db->pdo()->prepare($itemSql);
        $this->bindParams($itemStmt, $baseParams);
        $itemStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $itemStmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $itemStmt->execute();
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $sessions = array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['item_session'] ?? ''),
            $items
        )));

        $adjustmentsBySession = [];
        if ($sessions !== []) {
            $placeholders = [];
            $sessionParams = [];
            foreach ($sessions as $index => $session) {
                $key = ':session_' . $index;
                $placeholders[] = $key;
                $sessionParams[$key] = $session;
            }

            $adjustWhere = [
                sprintf('sa.litemsession IN (%s)', implode(', ', $placeholders)),
                'COALESCE(sa.ladjust_qty, 0) <> 0',
            ];
            $adjustParams = $sessionParams;
            if ($from !== null && $to !== null) {
                $adjustWhere[] = 'sa.ldatetime >= :adj_date_from';
                $adjustWhere[] = 'sa.ldatetime <= :adj_date_to';
                $adjustParams['adj_date_from'] = $from;
                $adjustParams['adj_date_to'] = $to;
            }

            $adjustSql = sprintf(
                'SELECT
                    sa.lid AS id,
                    sa.litemsession AS item_session,
                    sa.ldatetime AS adjustment_datetime,
                    DATE(sa.ldatetime) AS adjustment_date,
                    COALESCE(sa.lwarehouse, "") AS warehouse,
                    COALESCE(sa.llocation, "") AS location,
                    CAST(COALESCE(sa.lold_qty, 0) AS SIGNED) AS qty_stock,
                    CAST(COALESCE(sa.ladjust_qty, 0) AS SIGNED) AS physical_count,
                    ABS(CAST(COALESCE(sa.ladjust_qty, 0) AS SIGNED) - CAST(COALESCE(sa.lold_qty, 0) AS SIGNED)) AS discrepancy,
                    CAST(COALESCE(sa.linv_value, 0) AS DECIMAL(15,2)) AS value,
                    COALESCE(sa.lremarks, "") AS remarks,
                    COALESCE(sa.ladjustment_refno, "") AS adjustment_refno
                 FROM tblstock_adjustment_item sa
                 WHERE %s
                 ORDER BY sa.ldatetime ASC, sa.lid ASC',
                implode(' AND ', $adjustWhere)
            );
            $adjustStmt = $this->db->pdo()->prepare($adjustSql);
            foreach ($adjustParams as $key => $value) {
                $adjustStmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $adjustStmt->execute();
            foreach ($adjustStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $session = (string) ($row['item_session'] ?? '');
                if ($session === '') {
                    continue;
                }
                $adjustmentsBySession[$session][] = $row;
            }
        }

        $records = [];
        $flatRecords = [];
        $totalValue = 0.0;
        $totalDiscrepancy = 0;
        $totalAdjustments = 0;

        foreach ($items as $item) {
            $session = (string) ($item['item_session'] ?? '');
            $rows = $adjustmentsBySession[$session] ?? [];
            if ($rows === []) {
                continue;
            }

            $itemValue = 0.0;
            $itemDiscrepancy = 0;
            foreach ($rows as $row) {
                $val = (float) ($row['value'] ?? 0);
                $disc = (int) ($row['discrepancy'] ?? 0);
                $itemValue += $val;
                $itemDiscrepancy += $disc;
                $totalValue += $val;
                $totalDiscrepancy += $disc;
                $totalAdjustments++;

                $flatRecords[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'item_session' => $session,
                    'item_code' => (string) ($item['item_code'] ?? ''),
                    'part_no' => (string) ($item['part_no'] ?? ''),
                    'description' => (string) ($item['description'] ?? ''),
                    'brand' => (string) ($item['brand'] ?? ''),
                    'adjustment_date' => (string) ($row['adjustment_date'] ?? ''),
                    'adjustment_datetime' => (string) ($row['adjustment_datetime'] ?? ''),
                    'warehouse' => (string) ($row['warehouse'] ?? ''),
                    'location' => (string) ($row['location'] ?? ''),
                    'qty_stock' => (int) ($row['qty_stock'] ?? 0),
                    'physical_count' => (int) ($row['physical_count'] ?? 0),
                    'discrepancy' => $disc,
                    'value' => $val,
                    'remarks' => (string) ($row['remarks'] ?? ''),
                    'adjustment_refno' => (string) ($row['adjustment_refno'] ?? ''),
                ];
            }

            $records[] = [
                'item_session' => $session,
                'item_code' => (string) ($item['item_code'] ?? ''),
                'part_no' => (string) ($item['part_no'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'brand' => (string) ($item['brand'] ?? ''),
                'adjustments' => $rows,
                'summary' => [
                    'total_adjustments' => count($rows),
                    'total_value' => $itemValue,
                    'total_discrepancy' => $itemDiscrepancy,
                ],
            ];
        }

        return [
            'records' => $records,
            'flat_records' => $flatRecords,
            'summary' => [
                'total_items' => count($records),
                'total_adjustments' => $totalAdjustments,
                'total_value' => $totalValue,
                'total_discrepancy' => $totalDiscrepancy,
            ],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalItems,
                'total_pages' => (int) ceil($totalItems / max(1, $perPage)),
                'filters' => [
                    'time_period' => strtolower($timePeriod) !== '' ? strtolower($timePeriod) : 'all',
                    'date_from' => $from,
                    'date_to' => $to,
                    'part_no' => $normalizedPartNo !== '' ? $normalizedPartNo : 'All',
                    'item_code' => $normalizedItemCode !== '' ? $normalizedItemCode : 'All',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function filterOptions(int $mainId): array
    {
        $partStmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT TRIM(lpartno) AS value
             FROM tblinventory_item
             WHERE lmain_id = :main_id
               AND TRIM(COALESCE(lpartno, "")) <> ""
             ORDER BY TRIM(lpartno) ASC'
        );
        $partStmt->execute(['main_id' => $mainId]);
        $parts = array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['value'] ?? ''),
            $partStmt->fetchAll(PDO::FETCH_ASSOC)
        )));

        $codeStmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT TRIM(litemcode) AS value
             FROM tblinventory_item
             WHERE lmain_id = :main_id
               AND TRIM(COALESCE(litemcode, "")) <> ""
             ORDER BY TRIM(litemcode) ASC'
        );
        $codeStmt->execute(['main_id' => $mainId]);
        $codes = array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['value'] ?? ''),
            $codeStmt->fetchAll(PDO::FETCH_ASSOC)
        )));

        return [
            'part_numbers' => $parts,
            'item_codes' => $codes,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAdjustment(int $mainId, int $adjustmentId): ?array
    {
        $sql = <<<SQL
SELECT
    sa.lid AS id,
    sa.litemsession AS item_session,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.ldescription, '') AS description,
    COALESCE(br.lname, itm.lbrand, '') AS brand,
    COALESCE(sa.lwarehouse, '') AS warehouse,
    COALESCE(sa.llocation, '') AS location,
    CAST(COALESCE(sa.lold_qty, 0) AS SIGNED) AS qty_stock,
    CAST(COALESCE(sa.ladjust_qty, 0) AS SIGNED) AS physical_count,
    ABS(CAST(COALESCE(sa.ladjust_qty, 0) AS SIGNED) - CAST(COALESCE(sa.lold_qty, 0) AS SIGNED)) AS discrepancy,
    CAST(COALESCE(sa.linv_value, 0) AS DECIMAL(15,2)) AS value,
    COALESCE(sa.lremarks, '') AS remarks,
    COALESCE(sa.ladjustment_refno, '') AS adjustment_refno,
    COALESCE(sa.ldatetime, '') AS adjustment_datetime,
    DATE(sa.ldatetime) AS adjustment_date
FROM tblstock_adjustment_item sa
INNER JOIN tblinventory_item itm ON itm.lsession = sa.litemsession
LEFT JOIN tblbrand br ON br.lid = itm.lbrand
WHERE itm.lmain_id = :main_id
  AND sa.lid = :adjustment_id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('adjustment_id', $adjustmentId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function createAdjustment(int $mainId, string $userId, array $payload): array
    {
        $itemSession = trim((string) ($payload['item_session'] ?? $payload['item_id'] ?? ''));
        $item = $this->findItem($mainId, $itemSession);
        if ($item === null) {
            throw new RuntimeException('Inventory item not found for main_id');
        }

        $qtyStock = (int) ($payload['qty_stock'] ?? $payload['old_qty'] ?? 0);
        $physicalCount = (int) ($payload['physical_count'] ?? $payload['adjust_qty'] ?? 0);
        $warehouse = trim((string) ($payload['warehouse'] ?? 'WH1'));
        $location = trim((string) ($payload['location'] ?? ''));
        $remarks = trim((string) ($payload['remarks'] ?? ''));
        $adjustmentRefno = trim((string) ($payload['adjustment_refno'] ?? ''));
        if ($adjustmentRefno === '') {
            $adjustmentRefno = date('YmdHis') . random_int(1000, 9999);
        }
        $datetime = $this->normalizeDateTime((string) ($payload['adjustment_datetime'] ?? 'now'));

        $value = isset($payload['value'])
            ? (float) $payload['value']
            : abs($physicalCount - $qtyStock) * (float) ($item['cost'] ?? 0.0);

        $sql = <<<SQL
INSERT INTO tblstock_adjustment_item
(litemsession, lwarehouse, llocation, lold_qty, ladjust_qty, ldatetime, lremarks, linv_value, ladjustment_refno)
VALUES
(:item_session, :warehouse, :location, :old_qty, :adjust_qty, :adjustment_datetime, :remarks, :inv_value, :adjustment_refno)
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('item_session', $itemSession, PDO::PARAM_STR);
        $stmt->bindValue('warehouse', $warehouse, PDO::PARAM_STR);
        $stmt->bindValue('location', $location, PDO::PARAM_STR);
        $stmt->bindValue('old_qty', $qtyStock, PDO::PARAM_INT);
        $stmt->bindValue('adjust_qty', $physicalCount, PDO::PARAM_INT);
        $stmt->bindValue('adjustment_datetime', $datetime, PDO::PARAM_STR);
        $stmt->bindValue('remarks', $remarks !== '' ? $remarks : ('Created by user ' . $userId), PDO::PARAM_STR);
        $stmt->bindValue('inv_value', (string) $value, PDO::PARAM_STR);
        $stmt->bindValue('adjustment_refno', $adjustmentRefno, PDO::PARAM_STR);
        $stmt->execute();

        $id = (int) $this->db->pdo()->lastInsertId();
        $created = $this->getAdjustment($mainId, $id);
        if ($created === null) {
            throw new RuntimeException('Failed to load created inventory audit adjustment');
        }
        return $created;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateAdjustment(int $mainId, int $adjustmentId, array $payload): ?array
    {
        $existing = $this->getAdjustment($mainId, $adjustmentId);
        if ($existing === null) {
            return null;
        }

        $fields = [];
        $params = ['id' => $adjustmentId];

        if (array_key_exists('warehouse', $payload)) {
            $fields[] = 'lwarehouse = :warehouse';
            $params['warehouse'] = trim((string) $payload['warehouse']);
        }
        if (array_key_exists('location', $payload)) {
            $fields[] = 'llocation = :location';
            $params['location'] = trim((string) $payload['location']);
        }
        if (array_key_exists('qty_stock', $payload) || array_key_exists('old_qty', $payload)) {
            $fields[] = 'lold_qty = :old_qty';
            $params['old_qty'] = (int) ($payload['qty_stock'] ?? $payload['old_qty'] ?? 0);
        }
        if (array_key_exists('physical_count', $payload) || array_key_exists('adjust_qty', $payload)) {
            $fields[] = 'ladjust_qty = :adjust_qty';
            $params['adjust_qty'] = (int) ($payload['physical_count'] ?? $payload['adjust_qty'] ?? 0);
        }
        if (array_key_exists('remarks', $payload)) {
            $fields[] = 'lremarks = :remarks';
            $params['remarks'] = trim((string) $payload['remarks']);
        }
        if (array_key_exists('adjustment_datetime', $payload)) {
            $fields[] = 'ldatetime = :adjustment_datetime';
            $params['adjustment_datetime'] = $this->normalizeDateTime((string) $payload['adjustment_datetime']);
        }
        if (array_key_exists('value', $payload)) {
            $fields[] = 'linv_value = :inv_value';
            $params['inv_value'] = (string) ((float) $payload['value']);
        }
        if (array_key_exists('adjustment_refno', $payload)) {
            $fields[] = 'ladjustment_refno = :adjustment_refno';
            $params['adjustment_refno'] = trim((string) $payload['adjustment_refno']);
        }

        if ($fields !== []) {
            $sql = 'UPDATE tblstock_adjustment_item SET ' . implode(', ', $fields) . ' WHERE lid = :id';
            $stmt = $this->db->pdo()->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
        }

        return $this->getAdjustment($mainId, $adjustmentId);
    }

    public function deleteAdjustment(int $mainId, int $adjustmentId): bool
    {
        $existing = $this->getAdjustment($mainId, $adjustmentId);
        if ($existing === null) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare('DELETE FROM tblstock_adjustment_item WHERE lid = :id');
        $stmt->bindValue('id', $adjustmentId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /** @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>} */
    public function listStockAdjustments(int $mainId, int $month, int $year): array
    {
        $sql = <<<SQL
SELECT
    sa.lrefno AS refno,
    COALESCE(sa.ladjustment_number, '') AS adjustment_no,
    COALESCE(sa.lstatus, 'Pending') AS status,
    COALESCE(sa.ldatetime, '') AS adjustment_datetime,
    DATE(sa.ldatetime) AS adjustment_date,
    COALESCE(sa.luser_id, '') AS user_id,
    COUNT(sai.lid) AS adjustment_count
FROM tblstock_adjustment sa
LEFT JOIN tblstock_adjustment_item sai ON sai.ladjustment_refno = sa.lrefno
WHERE CAST(COALESCE(sa.lmain_id, 0) AS SIGNED) = :main_id
  AND MONTH(sa.ldatetime) = :month
  AND YEAR(sa.ldatetime) = :year
GROUP BY sa.lid, sa.lrefno, sa.ladjustment_number, sa.lstatus, sa.ldatetime, sa.luser_id
ORDER BY sa.lid DESC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('month', $month, PDO::PARAM_INT);
        $stmt->bindValue('year', $year, PDO::PARAM_INT);
        $stmt->execute();
        $items = array_map(
            static fn (array $row): array => [
                'refno' => (string) ($row['refno'] ?? ''),
                'adjustment_no' => (string) ($row['adjustment_no'] ?? ''),
                'status' => (string) ($row['status'] ?? 'Pending'),
                'adjustment_datetime' => (string) ($row['adjustment_datetime'] ?? ''),
                'adjustment_date' => (string) ($row['adjustment_date'] ?? ''),
                'user_id' => (string) ($row['user_id'] ?? ''),
                'adjustment_count' => (int) ($row['adjustment_count'] ?? 0),
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );

        return [
            'items' => $items,
            'meta' => ['total' => count($items), 'month' => $month, 'year' => $year],
        ];
    }

    /** @return array<string, mixed>|null */
    public function getStockAdjustment(
        int $mainId,
        string $refno,
        string $partNo = '',
        string $itemCode = '',
        int $page = 1,
        int $perPage = 100
    ): ?array {
        $header = $this->getStockAdjustmentHeader($mainId, $refno);
        if ($header === null) {
            return null;
        }

        $page = max(1, $page);
        $perPage = min(250, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $where = [
            'itm.lmain_id = :main_id',
            'COALESCE(itm.lstatus, 1) = 1',
            'COALESCE(itm.lnot_inventory, 0) = 0',
        ];
        $params = ['main_id' => $mainId];
        if ($partNo !== '') {
            $where[] = 'itm.lpartno LIKE :part_no';
            $params['part_no'] = '%' . $partNo . '%';
        }
        if ($itemCode !== '') {
            $where[] = 'itm.litemcode LIKE :item_code';
            $params['item_code'] = '%' . $itemCode . '%';
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM tblinventory_item itm WHERE {$whereSql}");
        $this->bindParams($countStmt, $params);
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $itemSql = <<<SQL
SELECT
    itm.lid AS inventory_id,
    itm.lsession AS item_session,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.ldescription, '') AS description,
    COALESCE(br.lname, itm.lbrand, '') AS brand,
    CAST(COALESCE(
        (SELECT NULLIF(ip.lprice_amt, '') FROM tblinventory_price ip
         WHERE ip.linv_refno = itm.lsession AND ip.lprice_name = 'AAA'
         ORDER BY ip.lid DESC LIMIT 1),
        itm.lcost,
        itm.lcog,
        0
    ) AS DECIMAL(15,2)) AS cost
FROM tblinventory_item itm
LEFT JOIN tblbrand br ON CAST(br.lid AS CHAR) = CAST(itm.lbrand AS CHAR)
WHERE {$whereSql}
ORDER BY itm.lpartno ASC, itm.litemcode ASC
LIMIT :limit OFFSET :offset
SQL;
        $itemStmt = $this->db->pdo()->prepare($itemSql);
        $this->bindParams($itemStmt, $params);
        $itemStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $itemStmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $itemStmt->execute();
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $sessions = array_values(array_filter(array_map(
            static fn (array $item): string => (string) ($item['item_session'] ?? ''),
            $items
        )));
        $stockByItem = [];
        $adjustmentByItem = [];
        if ($sessions !== []) {
            [$sessionSql, $sessionParams] = $this->makeInParams($sessions, 'item');

            $stockStmt = $this->db->pdo()->prepare(
                "SELECT linvent_id AS item_session, 'CENTRALIZED' AS warehouse,
                        CAST(COALESCE(SUM(lin), 0) - COALESCE(SUM(lout), 0) AS SIGNED) AS stock,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(llocation, '') ORDER BY lid DESC), ',', 1) AS location
                 FROM tblinventory_logs
                 WHERE linvent_id IN ({$sessionSql})
                 GROUP BY linvent_id"
            );
            $this->bindParams($stockStmt, $sessionParams);
            $stockStmt->execute();
            foreach ($stockStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $stockByItem[(string) $row['item_session']][strtoupper((string) $row['warehouse'])] = [
                    'stock' => (int) ($row['stock'] ?? 0),
                    'location' => (string) ($row['location'] ?? ''),
                ];
            }

            $adjustmentStmt = $this->db->pdo()->prepare(
                "SELECT MIN(lid) AS id, litemsession AS item_session, 'CENTRALIZED' AS warehouse,
                        CAST(COALESCE(SUM(lold_qty), 0) AS SIGNED) AS old_qty,
                        CAST(COALESCE(SUM(ladjust_qty), 0) AS SIGNED) AS physical_count,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(llocation, '') ORDER BY lid DESC), ',', 1) AS location,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(lremarks, '') ORDER BY lid DESC SEPARATOR ' | '), ' | ', 1) AS remarks
                 FROM tblstock_adjustment_item
                 WHERE ladjustment_refno = :refno AND litemsession IN ({$sessionSql})
                 GROUP BY litemsession"
            );
            $adjustmentStmt->bindValue('refno', $refno, PDO::PARAM_STR);
            $this->bindParams($adjustmentStmt, $sessionParams);
            $adjustmentStmt->execute();
            foreach ($adjustmentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $adjustmentByItem[(string) $row['item_session']][(string) $row['warehouse']] = $row;
            }
        }

        $warehouses = [['name' => 'CENTRALIZED']];
        $normalizedItems = [];
        foreach ($items as $item) {
            $session = (string) ($item['item_session'] ?? '');
            $warehouseRows = [];
            $totalStock = 0;
            $totalDiscrepancy = 0;
            foreach ($warehouses as $warehouse) {
                $warehouseName = (string) $warehouse['name'];
                $stock = $stockByItem[$session][$warehouseName] ?? ['stock' => 0, 'location' => ''];
                $adjustment = $adjustmentByItem[$session][$warehouseName] ?? null;
                $systemStock = $adjustment !== null ? (int) $adjustment['old_qty'] : (int) $stock['stock'];
                $physical = $adjustment !== null ? (int) $adjustment['physical_count'] : null;
                $discrepancy = $physical !== null ? $physical - $systemStock : null;
                $totalStock += $systemStock;
                $totalDiscrepancy += $discrepancy ?? 0;
                $warehouseRows[] = [
                    'warehouse' => $warehouseName,
                    'stock' => $systemStock,
                    'location' => $adjustment !== null
                        ? (string) ($adjustment['location'] ?? '')
                        : (string) ($stock['location'] ?? ''),
                    'physical_count' => $physical,
                    'discrepancy' => $discrepancy,
                    'remarks' => $adjustment !== null ? (string) ($adjustment['remarks'] ?? '') : '',
                    'adjustment_item_id' => $adjustment !== null ? (int) ($adjustment['id'] ?? 0) : null,
                ];
            }
            $cost = (float) ($item['cost'] ?? 0);
            $normalizedItems[] = [
                'inventory_id' => (int) ($item['inventory_id'] ?? 0),
                'item_session' => $session,
                'part_no' => (string) ($item['part_no'] ?? ''),
                'item_code' => (string) ($item['item_code'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'brand' => (string) ($item['brand'] ?? ''),
                'cost' => $cost,
                'warehouses' => $warehouseRows,
                'total_inventory' => $totalStock,
                'inventory_value' => $totalStock * $cost,
                'total_missing' => $totalDiscrepancy,
                'missing_value' => $totalDiscrepancy * $cost,
            ];
        }

        return [
            'header' => $header,
            'warehouses' => $warehouses,
            'items' => $normalizedItems,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
                'part_no' => $partNo,
                'item_code' => $itemCode,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function createStockAdjustment(int $mainId, string $userId): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $counterStmt = $pdo->query(
                "SELECT COALESCE(MAX(CAST(lmax_no AS UNSIGNED)), 0) FROM tblnumber_generator WHERE ltransaction_type = 'Stock Adjustment' FOR UPDATE"
            );
            $counter = (int) ($counterStmt->fetchColumn() ?: 0) + 1;
            $refno = date('YmdHis') . random_int(12345, 99999);
            $adjustmentNo = 'SA' . date('y') . '-' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare(
                'INSERT INTO tblstock_adjustment
                 (lrefno, ldatetime, ladjustment_number, luser_id, lmain_id, lstatus, ladjustment_type)
                 VALUES (:refno, NOW(), :adjustment_no, :user_id, :main_id, :status, :adjustment_type)'
            );
            $stmt->execute([
                'refno' => $refno,
                'adjustment_no' => $adjustmentNo,
                'user_id' => $userId,
                'main_id' => (string) $mainId,
                'status' => 'Pending',
                'adjustment_type' => 'physical_count',
            ]);
            $numberStmt = $pdo->prepare(
                'INSERT INTO tblnumber_generator (ltransaction_type, lmax_no) VALUES (:type, :counter)'
            );
            $numberStmt->execute(['type' => 'Stock Adjustment', 'counter' => $counter]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $created = $this->getStockAdjustmentHeader($mainId, $refno);
        if ($created === null) {
            throw new RuntimeException('Failed to load created stock adjustment');
        }
        return $created;
    }

    /**
     * @param array<int, mixed> $entries
     * @return array<string, mixed>
     */
    public function saveStockAdjustmentCounts(int $mainId, string $refno, array $entries): array
    {
        $header = $this->requirePendingStockAdjustment($mainId, $refno);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            foreach ($entries as $entry) {
                if (!is_array($entry)) continue;
                $itemSession = trim((string) ($entry['item_session'] ?? ''));
                $warehouse = strtoupper(trim((string) ($entry['warehouse'] ?? '')));
                if ($itemSession === '') {
                    throw new RuntimeException('Each count requires item_session');
                }
                $warehouse = 'CENTRALIZED';
                $product = $this->findItem($mainId, $itemSession);
                if ($product === null) {
                    throw new RuntimeException('Inventory item not found: ' . $itemSession);
                }

                $deleteItem = $pdo->prepare(
                    'DELETE FROM tblstock_adjustment_item
                     WHERE ladjustment_refno = :refno AND litemsession = :item_session'
                );
                $deleteItem->execute(['refno' => $refno, 'item_session' => $itemSession]);
                $deleteLog = $pdo->prepare(
                    "DELETE FROM tblinventory_logs
                     WHERE lrefno = :refno AND linvent_id = :item_session
                       AND ltransaction_type = 'Stock Adjustment'"
                );
                $deleteLog->execute(['refno' => $refno, 'item_session' => $itemSession]);

                if (!array_key_exists('physical_count', $entry) || $entry['physical_count'] === null || $entry['physical_count'] === '') {
                    continue;
                }

                $physicalCount = (int) $entry['physical_count'];
                $stockRow = $this->getBaseStock($pdo, $itemSession, $warehouse, $refno);
                $systemStock = (int) $stockRow['stock'];
                $difference = $physicalCount - $systemStock;
                if ($difference === 0) {
                    continue;
                }

                $location = trim((string) ($entry['location'] ?? $stockRow['location'] ?? ''));
                $remarks = trim((string) ($entry['remarks'] ?? ''));
                $cost = (float) ($product['cost'] ?? 0);
                $itemStmt = $pdo->prepare(
                    'INSERT INTO tblstock_adjustment_item
                     (litemsession, lwarehouse, lold_qty, ladjust_qty, llocation, ldatetime, lremarks, linv_value, ladjustment_refno)
                     VALUES (:item_session, :warehouse, :old_qty, :physical_count, :location, NOW(), :remarks, :value, :refno)'
                );
                $itemStmt->execute([
                    'item_session' => $itemSession,
                    'warehouse' => $warehouse,
                    'old_qty' => $systemStock,
                    'physical_count' => $physicalCount,
                    'location' => $location,
                    'remarks' => $remarks,
                    'value' => (string) (abs($difference) * $cost),
                    'refno' => $refno,
                ]);

                $logStmt = $pdo->prepare(
                    'INSERT INTO tblinventory_logs
                     (linvent_id, lin, lout, ltotal, ldateadded, lprocess_by, lstatus_logs, lnote,
                      linventory_id, lprice, lrefno, llocation, lwarehouse, lphysical_count,
                      ltransaction_type, litemcode, lpartno)
                     VALUES
                     (:item_session, :qty_in, :qty_out, :total, :date_added, :process_by, :status_logs, :note,
                      :inventory_id, :price, :refno, :location, :warehouse, :physical_count,
                      :transaction_type, :item_code, :part_no)'
                );
                $logStmt->execute([
                    'item_session' => $itemSession,
                    'qty_in' => $difference > 0 ? $difference : 0,
                    'qty_out' => $difference < 0 ? abs($difference) : 0,
                    'total' => abs($difference),
                    'date_added' => (string) $header['adjustment_datetime'],
                    'process_by' => (string) $header['adjustment_no'],
                    'status_logs' => $difference > 0 ? '+' : '-',
                    'note' => 'STOCK ADJUSTMENT PHYSICAL COUNT: ' . $physicalCount,
                    'inventory_id' => (string) ($product['inventory_id'] ?? ''),
                    'price' => (string) $cost,
                    'refno' => $refno,
                    'location' => $location,
                    'warehouse' => $warehouse,
                    'physical_count' => $physicalCount,
                    'transaction_type' => 'Stock Adjustment',
                    'item_code' => (string) ($product['item_code'] ?? ''),
                    'part_no' => (string) ($product['part_no'] ?? ''),
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return ['saved' => true, 'refno' => $refno];
    }

    /** @return array<string, mixed>|null */
    public function postStockAdjustment(int $mainId, string $refno): ?array
    {
        $this->requirePendingStockAdjustment($mainId, $refno);
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tblstock_adjustment SET lstatus = 'Posted'
             WHERE lrefno = :refno AND CAST(COALESCE(lmain_id, 0) AS SIGNED) = :main_id"
        );
        $stmt->execute(['refno' => $refno, 'main_id' => $mainId]);
        return $this->getStockAdjustmentHeader($mainId, $refno);
    }

    /** @return array<string, mixed>|null */
    public function updateStockAdjustmentDate(int $mainId, string $refno, string $date): ?array
    {
        $this->requirePendingStockAdjustment($mainId, $refno);
        $datetime = $this->normalizeDateTime($date);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE tblstock_adjustment SET ldatetime = :datetime
                 WHERE lrefno = :refno AND CAST(COALESCE(lmain_id, 0) AS SIGNED) = :main_id'
            );
            $stmt->execute(['datetime' => $datetime, 'refno' => $refno, 'main_id' => $mainId]);
            $itemStmt = $pdo->prepare('UPDATE tblstock_adjustment_item SET ldatetime = :datetime WHERE ladjustment_refno = :refno');
            $itemStmt->execute(['datetime' => $datetime, 'refno' => $refno]);
            $logStmt = $pdo->prepare("UPDATE tblinventory_logs SET ldateadded = :datetime WHERE lrefno = :refno AND ltransaction_type = 'Stock Adjustment'");
            $logStmt->execute(['datetime' => $datetime, 'refno' => $refno]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->getStockAdjustmentHeader($mainId, $refno);
    }

    public function deleteStockAdjustmentItem(int $mainId, string $refno, string $itemSession): bool
    {
        $this->requirePendingStockAdjustment($mainId, $refno);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM tblstock_adjustment_item WHERE ladjustment_refno = :refno AND litemsession = :item_session');
            $stmt->execute(['refno' => $refno, 'item_session' => $itemSession]);
            $deleted = $stmt->rowCount() > 0;
            $logStmt = $pdo->prepare("DELETE FROM tblinventory_logs WHERE lrefno = :refno AND linvent_id = :item_session AND ltransaction_type = 'Stock Adjustment'");
            $logStmt->execute(['refno' => $refno, 'item_session' => $itemSession]);
            $pdo->commit();
            return $deleted;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function deleteStockAdjustment(int $mainId, string $refno): bool
    {
        $this->requirePendingStockAdjustment($mainId, $refno);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM tblstock_adjustment_item WHERE ladjustment_refno = :refno')->execute(['refno' => $refno]);
            $pdo->prepare("DELETE FROM tblinventory_logs WHERE lrefno = :refno AND ltransaction_type = 'Stock Adjustment'")->execute(['refno' => $refno]);
            $stmt = $pdo->prepare('DELETE FROM tblstock_adjustment WHERE lrefno = :refno AND CAST(COALESCE(lmain_id, 0) AS SIGNED) = :main_id');
            $stmt->execute(['refno' => $refno, 'main_id' => $mainId]);
            $deleted = $stmt->rowCount() > 0;
            $pdo->commit();
            return $deleted;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private function getStockAdjustmentHeader(int $mainId, string $refno): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT lrefno AS refno, COALESCE(ladjustment_number, '') AS adjustment_no,
                    COALESCE(lstatus, 'Pending') AS status, COALESCE(ldatetime, '') AS adjustment_datetime,
                    DATE(ldatetime) AS adjustment_date, COALESCE(luser_id, '') AS user_id
             FROM tblstock_adjustment
             WHERE lrefno = :refno AND CAST(COALESCE(lmain_id, 0) AS SIGNED) = :main_id LIMIT 1"
        );
        $stmt->execute(['refno' => $refno, 'main_id' => $mainId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return [
            'refno' => (string) ($row['refno'] ?? ''),
            'adjustment_no' => (string) ($row['adjustment_no'] ?? ''),
            'status' => (string) ($row['status'] ?? 'Pending'),
            'adjustment_datetime' => (string) ($row['adjustment_datetime'] ?? ''),
            'adjustment_date' => (string) ($row['adjustment_date'] ?? ''),
            'user_id' => (string) ($row['user_id'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function requirePendingStockAdjustment(int $mainId, string $refno): array
    {
        $header = $this->getStockAdjustmentHeader($mainId, $refno);
        if ($header === null) {
            throw new RuntimeException('Stock adjustment not found');
        }
        if (strcasecmp((string) $header['status'], 'Pending') !== 0) {
            throw new RuntimeException('Posted stock adjustments cannot be edited or deleted');
        }
        return $header;
    }

    /** @return array<int, array{name:string}> */
    private function getWarehouses(int $mainId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT UPPER(TRIM(lname)) AS name FROM tblbranch
             WHERE (CAST(COALESCE(lmain_id, 0) AS SIGNED) = :main_id OR COALESCE(lmain_id, "") = "")
               AND COALESCE(lstatus, 1) = 1 AND TRIM(COALESCE(lname, "")) <> ""
             ORDER BY lname ASC'
        );
        $stmt->execute(['main_id' => $mainId]);
        $rows = array_map(
            static fn (array $row): array => ['name' => (string) ($row['name'] ?? '')],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
        if ($rows !== []) return $rows;
        return array_map(static fn (int $number): array => ['name' => 'WH' . $number], range(1, 6));
    }

    /** @return array{stock:int,location:string} */
    private function getBaseStock(PDO $pdo, string $itemSession, string $warehouse, string $excludeRefno): array
    {
        $stmt = $pdo->prepare(
            "SELECT CAST(COALESCE(SUM(lin), 0) - COALESCE(SUM(lout), 0) AS SIGNED) AS stock,
                    SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(llocation, '') ORDER BY lid DESC), ',', 1) AS location
             FROM tblinventory_logs
             WHERE linvent_id = :item_session
               AND NOT (COALESCE(lrefno, '') = :refno AND COALESCE(ltransaction_type, '') = 'Stock Adjustment')"
        );
        $stmt->execute(['item_session' => $itemSession, 'refno' => $excludeRefno]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['stock' => (int) ($row['stock'] ?? 0), 'location' => (string) ($row['location'] ?? '')];
    }

    /** @param array<int, string> $values @return array{0:string,1:array<string,string>} */
    private function makeInParams(array $values, string $prefix): array
    {
        $tokens = [];
        $params = [];
        foreach ($values as $index => $value) {
            $key = $prefix . '_' . $index;
            $tokens[] = ':' . $key;
            $params[$key] = $value;
        }
        return [implode(', ', $tokens), $params];
    }

    /**
     * @return array{0:string|null,1:string|null}
     */
    private function resolveDateRange(string $timePeriod, string $dateFrom, string $dateTo): array
    {
        $period = strtolower(trim($timePeriod));
        $now = new DateTimeImmutable('now');

        return match ($period) {
            '', 'all' => [null, null],
            'today' => [$now->format('Y-m-d 00:00:01'), $now->format('Y-m-d 23:59:59')],
            'week' => [$now->modify('-1 week')->format('Y-m-d 00:00:01'), $now->format('Y-m-d 23:59:59')],
            'month' => [$now->modify('-1 month')->format('Y-m-d 00:00:01'), $now->format('Y-m-d 23:59:59')],
            'year' => [$now->modify('-1 year')->format('Y-m-d 00:00:01'), $now->format('Y-m-d 23:59:59')],
            'custom' => [$this->normalizeDateStart($dateFrom), $this->normalizeDateEnd($dateTo)],
            default => [null, null],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findItem(int $mainId, string $itemSession): ?array
    {
        $sql = <<<SQL
SELECT
    itm.lid AS inventory_id,
    itm.lsession AS item_session,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.ldescription, '') AS description,
    CAST(COALESCE(
        (SELECT NULLIF(ip.lprice_amt, '') FROM tblinventory_price ip
         WHERE ip.linv_refno = itm.lsession AND ip.lprice_name = 'AAA'
         ORDER BY ip.lid DESC LIMIT 1),
        itm.lcost,
        itm.lcog,
        0
    ) AS DECIMAL(15,2)) AS cost
FROM tblinventory_item itm
WHERE itm.lmain_id = :main_id
  AND itm.lsession = :item_session
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('item_session', $itemSession, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function normalizeDateStart(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            throw new RuntimeException('date_from is required when time_period is custom');
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            throw new RuntimeException('Invalid date_from value');
        }
        return date('Y-m-d 00:00:01', $ts);
    }

    private function normalizeDateEnd(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            throw new RuntimeException('date_to is required when time_period is custom');
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            throw new RuntimeException('Invalid date_to value');
        }
        return date('Y-m-d 23:59:59', $ts);
    }

    private function normalizeDateTime(string $value): string
    {
        $raw = trim($value);
        $ts = strtotime($raw === '' ? 'now' : $raw);
        if ($ts === false) {
            throw new RuntimeException('Invalid adjustment_datetime value');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bindParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
