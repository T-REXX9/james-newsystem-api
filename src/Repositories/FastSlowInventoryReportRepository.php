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

    public function report(int $mainId, string $sortBy = 'sales_volume', string $sortDirection = 'desc'): array
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

        $salesStatsBySession = $this->getSalesStatsBySession($itemSessions, $periods);
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
                'first_arrival_date' => $arrivalByItemId[$itemId] ?? null,
                'total_purchased' => (int) ($stats['total_purchased'] ?? 0),
                'total_sold' => (int) ($stats['total_sold'] ?? 0),
                'month1_sales' => $month1Sales,
                'month2_sales' => $month2Sales,
                'month3_sales' => $month3Sales,
                'month1_label' => $periods['month1']['label'],
                'month2_label' => $periods['month2']['label'],
                'month3_label' => $periods['month3']['label'],
                'category' => $this->categorizeMovement($month1Sales, $month2Sales, $month3Sales),
            ];
        }

        usort($rows, function (array $a, array $b) use ($sortBy, $sortDirection): int {
            if ($sortBy === 'part_no') {
                $cmp = strcasecmp((string) $a['part_no'], (string) $b['part_no']);
                return $sortDirection === 'asc' ? $cmp : -$cmp;
            }

            $aVolume = (int) ($a['month1_sales'] ?? 0) + (int) ($a['month2_sales'] ?? 0) + (int) ($a['month3_sales'] ?? 0);
            $bVolume = (int) ($b['month1_sales'] ?? 0) + (int) ($b['month2_sales'] ?? 0) + (int) ($b['month3_sales'] ?? 0);
            $cmp = $aVolume <=> $bVolume;
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
        // Keep old-system conditions from Reportctl::inventory_moving_report_view.
        if ($month1Sales === 0 && $month2Sales === 0) {
            return 'slow';
        }
        if ($month2Sales > $month3Sales) {
            return 'slow';
        }
        if ($month2Sales < $month3Sales) {
            return 'fast';
        }
        return 'slow';
    }

    private function getItems(int $mainId): array
    {
        $sql = <<<SQL
SELECT
    itm.lid AS item_id,
    itm.lsession AS item_session,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.ldescription, '') AS description
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

    private function getSalesStatsBySession(array $itemSessions, array $periods): array
    {
        if (count($itemSessions) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemSessions), '?'));
        $sql = <<<SQL
SELECT
    lg.linvent_id AS item_session,
    SUM(CASE
        WHEN lg.ltransaction_type = 'Receiving' AND lg.lstatus_logs = '+'
            THEN COALESCE(lg.lin, 0)
        ELSE 0
    END) AS total_purchased,
    SUM(CASE
        WHEN lg.ltransaction_type IN ('Invoice', 'Order Slip') AND lg.lstatus_logs = '-'
            THEN COALESCE(lg.lout, 0)
        ELSE 0
    END) AS total_sold,
    SUM(CASE
        WHEN lg.ltransaction_type IN ('Invoice', 'Order Slip') AND lg.lstatus_logs = '-'
             AND lg.ldateadded >= ? AND lg.ldateadded <= ?
            THEN COALESCE(lg.lout, 0)
        ELSE 0
    END) AS month1_sales,
    SUM(CASE
        WHEN lg.ltransaction_type IN ('Invoice', 'Order Slip') AND lg.lstatus_logs = '-'
             AND lg.ldateadded >= ? AND lg.ldateadded <= ?
            THEN COALESCE(lg.lout, 0)
        ELSE 0
    END) AS month2_sales,
    SUM(CASE
        WHEN lg.ltransaction_type IN ('Invoice', 'Order Slip') AND lg.lstatus_logs = '-'
             AND lg.ldateadded >= ? AND lg.ldateadded <= ?
            THEN COALESCE(lg.lout, 0)
        ELSE 0
    END) AS month3_sales
FROM tblinventory_logs lg
WHERE lg.linvent_id IN ({$placeholders})
GROUP BY lg.linvent_id
SQL;

        $params = [
            $periods['month1']['start'],
            $periods['month1']['end'],
            $periods['month2']['start'],
            $periods['month2']['end'],
            $periods['month3']['start'],
            $periods['month3']['end'],
        ];
        $params = array_merge($params, $itemSessions);

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
        $months = [3, 2, 1];
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

