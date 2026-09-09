<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class FastSlowInventoryReportRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function report(int $mainId, string $sortBy = 'part_no', string $sortDirection = 'asc'): array
    {
        $periods = $this->getThreeMonthPeriods();
        $items = $this->getItems($mainId);

        if (count($items) === 0) {
            return [
                'fastMovingItems' => [],
                'slowMovingItems' => [],
                'generatedAt' => date('c'),
            ];
        }

        $itemIds = array_map(static fn(array $row): int => (int) ($row['item_id'] ?? 0), $items);
        $itemSessions = array_map(static fn(array $row): string => (string) ($row['item_session'] ?? ''), $items);

        $salesStatsBySession = $this->getSalesStatsBySession($mainId, $itemSessions, $periods);
        $arrivalByItemId = $this->getFirstArrivalByItemId($mainId, $itemIds);

        $rows = [];
        foreach ($items as $item) {
            $itemId = (int) ($item['item_id'] ?? 0);
            $itemSession = (string) ($item['item_session'] ?? '');
            if ($itemSession === '') {
                continue;
            }

            $stats = $salesStatsBySession[$itemSession] ?? [
                'total_purchased' => 0,
                'total_sold' => 0,
                'month1_sales' => 0,
                'month2_sales' => 0,
                'month3_sales' => 0,
            ];

            // Old-system behavior: exclude rows with no sold movement at all.
            if (((int) ($stats['total_sold'] ?? 0)) <= 0) {
                continue;
            }

            $month1Sales = (int) ($stats['month1_sales'] ?? 0);
            $month2Sales = (int) ($stats['month2_sales'] ?? 0);
            $month3Sales = (int) ($stats['month3_sales'] ?? 0);

            $rows[] = [
                'item_id' => $itemSession,
                'part_no' => (string) ($item['part_no'] ?? ''),
                'item_code' => (string) ($item['item_code'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'vip1_price' => (float) ($item['vip1_price'] ?? 0),
                'first_arrival_date' => $arrivalByItemId[$itemId] ?? null,
                'total_purchased' => (int) ($stats['total_purchased'] ?? 0),
                'total_sold' => (int) ($stats['total_sold'] ?? 0),
                'month1_sales' => $month1Sales,
                'month2_sales' => $month2Sales,
                'month3_sales' => $month3Sales,
                'month1_label' => $periods['month1']['label'],
                'month2_label' => $periods['month2']['label'],
                'month3_label' => $periods['month3']['label'],
                'last_price_update' => $item['last_price_update'] ?? null,
                'category' => $this->categorizeMovement($month1Sales, $month2Sales, $month3Sales),
            ];
        }

        usort($rows, function (array $a, array $b) use ($sortBy, $sortDirection): int {
            $fieldMap = [
                'part_no' => ['part_no', 'text'],
                'item_code' => ['item_code', 'text'],
                'description' => ['description', 'text'],
                'last_arrived' => ['first_arrival_date', 'text'],
                'total_purchase' => ['total_purchased', 'number'],
                'total_sold' => ['total_sold', 'number'],
            ];
            [$field, $type] = $fieldMap[$sortBy] ?? $fieldMap['part_no'];
            $cmp = $type === 'number'
                ? ((int) ($a[$field] ?? 0) <=> (int) ($b[$field] ?? 0))
                : strcasecmp((string) ($a[$field] ?? ''), (string) ($b[$field] ?? ''));
            return $sortDirection === 'asc' ? $cmp : -$cmp;
        });

        $fast = array_values(array_filter($rows, static fn(array $row): bool => ($row['category'] ?? '') === 'fast'));
        $slow = array_values(array_filter($rows, static fn(array $row): bool => ($row['category'] ?? '') === 'slow'));

        return [
            'fastMovingItems' => $fast,
            'slowMovingItems' => $slow,
            'generatedAt' => date('c'),
        ];
    }

    private function categorizeMovement(int $month1Sales, int $month2Sales, int $month3Sales): string
    {
        // Fast Moving means the item sold at least once in each of the three consecutive months.
        return $month1Sales > 0 && $month2Sales > 0 && $month3Sales > 0 ? 'fast' : 'slow';
    }

    private function getItems(int $mainId): array
    {
        $sql = <<<SQL
SELECT
    itm.lid AS item_id,
    itm.lsession AS item_session,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.ldescription, '') AS description,
    COALESCE((
        SELECT ip.lprice_amt
        FROM tblinventory_price ip
        WHERE ip.linv_refno = itm.lsession
          AND ip.lprice_name = 'VIP 1'
        ORDER BY ip.lid DESC
        LIMIT 1
    ), 0) AS vip1_price,
    COALESCE((
        SELECT MAX(ph.lupdated_at)
        FROM tblinventory_price_history ph
        WHERE ph.linv_refno = itm.lsession
    ), '') AS last_price_update
FROM tblinventory_item itm
WHERE itm.lmain_id = :main_id
  AND COALESCE(itm.lstatus, 1) = 1
ORDER BY itm.lpartno ASC, itm.lid ASC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['main_id' => $mainId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getFirstArrivalByItemId(int $mainId, array $itemIds): array
    {
        if (count($itemIds) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $sql = <<<SQL
SELECT
    pi.litemid AS item_id,
    MIN(po.ldate) AS first_arrival_date
FROM tblpurchase_item pi
INNER JOIN tblpurchase_order po ON po.lrefno = pi.lrefno
WHERE po.lmain_id = ?
  AND pi.litemid IN ({$placeholders})
GROUP BY pi.litemid
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_merge([$mainId], $itemIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            $map[$itemId] = $this->normalizeDate((string) ($row['first_arrival_date'] ?? ''));
        }

        return $map;
    }

    private function getSalesStatsBySession(int $mainId, array $itemSessions, array $periods): array
    {
        if (count($itemSessions) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemSessions), '?'));
        $sql = <<<SQL
SELECT
    movement.item_session,
    SUM(movement.purchased_qty) AS total_purchased,
    SUM(movement.sold_qty) AS total_sold,
    SUM(CASE
        WHEN movement.sales_date >= ? AND movement.sales_date <= ?
            THEN movement.sold_qty
        ELSE 0
    END) AS month1_sales,
    SUM(CASE
        WHEN movement.sales_date >= ? AND movement.sales_date <= ?
            THEN movement.sold_qty
        ELSE 0
    END) AS month2_sales,
    SUM(CASE
        WHEN movement.sales_date >= ? AND movement.sales_date <= ?
            THEN movement.sold_qty
        ELSE 0
    END) AS month3_sales
FROM (
    SELECT
        lg.linvent_id AS item_session,
        CASE
            WHEN lg.ltransaction_type = 'Receiving' AND lg.lstatus_logs = '+'
                THEN COALESCE(lg.lin, 0)
            ELSE 0
        END AS purchased_qty,
        0 AS sold_qty,
        NULL AS sales_date
    FROM tblinventory_logs lg
    WHERE lg.linvent_id IN ({$placeholders})

    UNION ALL

    SELECT
        ii.linv_refno AS item_session,
        0 AS purchased_qty,
        COALESCE(ii.lqty, 0) AS sold_qty,
        inv.ldate AS sales_date
    FROM tblinvoice_list inv
    INNER JOIN tblinvoice_itemrec ii ON ii.linvoice_refno = inv.lrefno
    WHERE inv.lmain_id = ?
      AND ii.linv_refno IN ({$placeholders})
      AND LOWER(COALESCE(inv.lstatus, '')) = 'posted'
      AND COALESCE(inv.lcancel, '') = ''
      AND COALESCE(inv.lcancel_invoice, 0) = 0

    UNION ALL

    SELECT
        dri.linv_refno AS item_session,
        0 AS purchased_qty,
        COALESCE(dri.lqty, 0) AS sold_qty,
        dr.ldate AS sales_date
    FROM tbldelivery_receipt dr
    INNER JOIN tbldelivery_receipt_items dri ON dri.lor_refno = dr.lrefno
    WHERE dr.lmain_id = ?
      AND dri.linv_refno IN ({$placeholders})
      AND LOWER(COALESCE(dr.lstatus, '')) = 'posted'
      AND COALESCE(dr.lcancel, '') = ''
) movement
GROUP BY movement.item_session
SQL;

        $params = [
            $periods['month1']['start'],
            $periods['month1']['end'],
            $periods['month2']['start'],
            $periods['month2']['end'],
            $periods['month3']['start'],
            $periods['month3']['end'],
        ];
        $params = array_merge(
            $params,
            $itemSessions,
            [$mainId],
            $itemSessions,
            [$mainId],
            $itemSessions
        );

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $session = (string) ($row['item_session'] ?? '');
            if ($session === '') {
                continue;
            }
            $map[$session] = [
                'total_purchased' => (int) ($row['total_purchased'] ?? 0),
                'total_sold' => (int) ($row['total_sold'] ?? 0),
                'month1_sales' => (int) ($row['month1_sales'] ?? 0),
                'month2_sales' => (int) ($row['month2_sales'] ?? 0),
                'month3_sales' => (int) ($row['month3_sales'] ?? 0),
            ];
        }

        return $map;
    }

    private function getThreeMonthPeriods(): array
    {
        $now = new \DateTimeImmutable('now');
        $months = [2, 1, 0];
        $labels = ['month1', 'month2', 'month3'];
        $result = [];

        foreach ($months as $idx => $offset) {
            $monthDate = $now->modify("-{$offset} months");
            $start = $monthDate->modify('first day of this month')->setTime(0, 0, 1);
            $end = $monthDate->modify('last day of this month')->setTime(23, 59, 59);
            $key = $labels[$idx];
            $result[$key] = [
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
                'label' => $start->format('F'),
            ];
        }

        return $result;
    }

    private function normalizeDate(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '0000-00-00') {
            return null;
        }
        return preg_replace('/\s+/', ' ', substr($trimmed, 0, 10));
    }
}
