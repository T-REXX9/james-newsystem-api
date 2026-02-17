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
    itm.lsession AS item_session,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.ldescription, '') AS description,
    CAST(COALESCE(itm.lcost, 0) AS DECIMAL(15,2)) AS cost
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
