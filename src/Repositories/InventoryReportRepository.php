<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class InventoryReportRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function options(int $mainId): array
    {
        $categoriesStmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(lname, "") AS name
             FROM tblproduct_group
             WHERE COALESCE(lstatus, 1) = 1
             ORDER BY lname ASC'
        );
        $categoriesStmt->execute();
        $categories = array_values(array_filter(array_map(
            static fn(array $row): string => trim((string) ($row['name'] ?? '')),
            $categoriesStmt->fetchAll(PDO::FETCH_ASSOC)
        )));

        $partsStmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT COALESCE(lpartno, "") AS part_no
             FROM tblinventory_item
             WHERE lmain_id = :main_id
               AND COALESCE(lstatus, 1) = 1
               AND COALESCE(lpartno, "") <> ""
             ORDER BY part_no ASC'
        );
        $partsStmt->execute(['main_id' => $mainId]);
        $partNumbers = array_values(array_filter(array_map(
            static fn(array $row): string => trim((string) ($row['part_no'] ?? '')),
            $partsStmt->fetchAll(PDO::FETCH_ASSOC)
        )));

        $codesStmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT COALESCE(litemcode, "") AS item_code
             FROM tblinventory_item
             WHERE lmain_id = :main_id
               AND COALESCE(lstatus, 1) = 1
               AND COALESCE(litemcode, "") <> ""
             ORDER BY item_code ASC'
        );
        $codesStmt->execute(['main_id' => $mainId]);
        $itemCodes = array_values(array_filter(array_map(
            static fn(array $row): string => trim((string) ($row['item_code'] ?? '')),
            $codesStmt->fetchAll(PDO::FETCH_ASSOC)
        )));

        return [
            'categories' => $categories,
            'part_numbers' => $partNumbers,
            'item_codes' => $itemCodes,
            'warehouses' => $this->listWarehouses(),
        ];
    }

    /**
     * @param array<string, string> $filters
     */
    public function report(int $mainId, array $filters): array
    {
        $items = $this->listItems($mainId, $filters);
        if (count($items) === 0) {
            return [
                'items' => [],
                'warehouses' => $this->listWarehouses(),
            ];
        }

        $warehouses = $this->listWarehouses();
        $itemSessions = array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['item_session'] ?? ''),
            $items
        )));
        $stocksByItem = $this->stocksByWarehouse($itemSessions, $filters['date_from'], $filters['date_to']);

        $rows = [];
        foreach ($items as $item) {
            $session = (string) ($item['item_session'] ?? '');
            $warehouseStock = $stocksByItem[$session] ?? [];
            $totalStock = array_sum(array_map(static fn($v): float => (float) $v, $warehouseStock));

            if ($filters['stock_status'] === 'with_stock' && $totalStock <= 0) {
                continue;
            }
            if ($filters['stock_status'] === 'without_stock' && $totalStock > 0) {
                continue;
            }

            $rows[] = [
                'id' => $session,
                'part_no' => (string) ($item['part_no'] ?? ''),
                'item_code' => (string) ($item['item_code'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'category' => (string) ($item['category'] ?? ''),
                'location' => (string) ($item['location'] ?? ''),
                'cost' => (float) ($item['cost'] ?? 0),
                'total_stock' => $totalStock,
                'warehouse_stock' => $warehouseStock,
                'value' => round($totalStock * (float) ($item['cost'] ?? 0), 2),
            ];
        }

        return [
            'items' => $rows,
            'warehouses' => $warehouses,
        ];
    }

    /**
     * @return array<int, array{id:string,name:string}>
     */
    private function listWarehouses(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT CAST(lid AS CHAR) AS id, COALESCE(lname, "") AS name
             FROM tblbranch
             ORDER BY lname ASC'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_filter(array_map(static function (array $row): ?array {
            $id = trim((string) ($row['id'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($id === '' || $name === '') {
                return null;
            }
            return ['id' => $id, 'name' => $name];
        }, $rows)));
    }

    /**
     * @param array<string, string> $filters
     * @return array<int, array<string, mixed>>
     */
    private function listItems(int $mainId, array $filters): array
    {
        $sql = <<<SQL
SELECT
    itm.lid AS item_id,
    itm.lsession AS item_session,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.ldescription, '') AS description,
    COALESCE(itm.lproduct_group, '') AS category,
    COALESCE(itm.llocation, '') AS location,
    COALESCE((
        SELECT ip.lprice_amt
        FROM tblinventory_price ip
        WHERE ip.linv_refno = itm.lsession
          AND ip.lprice_name = 'AAA'
        ORDER BY ip.lid DESC
        LIMIT 1
    ), 0) AS cost,
    COALESCE((
        SELECT SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0))
        FROM tblinventory_logs lg
        WHERE lg.linvent_id = itm.lsession
    ), 0) AS total_stock
FROM tblinventory_item itm
WHERE itm.lmain_id = :main_id
  AND COALESCE(itm.lstatus, 1) = 1
SQL;

        $params = ['main_id' => $mainId];

        if ($filters['category'] !== '') {
            $sql .= ' AND itm.lproduct_group = :category';
            $params['category'] = $filters['category'];
        }
        if ($filters['part_number'] !== '') {
            $sql .= ' AND itm.lpartno LIKE :part_number';
            $params['part_number'] = '%' . $filters['part_number'] . '%';
        }
        if ($filters['item_code'] !== '') {
            $sql .= ' AND itm.litemcode LIKE :item_code';
            $params['item_code'] = '%' . $filters['item_code'] . '%';
        }

        $sql .= ' ORDER BY itm.lpartno ASC, itm.lid ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param string[] $itemSessions
     * @return array<string, array<string, float>>
     */
    private function stocksByWarehouse(array $itemSessions, string $dateFrom = '', string $dateTo = ''): array
    {
        if (count($itemSessions) === 0) {
            return [];
        }

        // Fetch all warehouses to ensure every item has a complete warehouse stock record
        $warehouses = $this->listWarehouses();
        $warehouseNames = array_map(
            static fn(array $wh): string => $wh['name'],
            $warehouses
        );

        // Pre-populate result with all warehouses set to 0 for each item session
        $result = [];
        foreach ($itemSessions as $session) {
            $result[$session] = [];
            foreach ($warehouseNames as $name) {
                $result[$session][$name] = 0.0;
            }
        }

        // Execute query to get actual stock values
        $placeholders = implode(',', array_fill(0, count($itemSessions), '?'));
        $sql = <<<SQL
SELECT
    lg.linvent_id AS item_session,
    COALESCE(lg.lwarehouse, '') AS warehouse_name,
    SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0)) AS stock
FROM tblinventory_logs lg
WHERE lg.linvent_id IN ({$placeholders})
SQL;

        $params = $itemSessions;
        if ($dateFrom !== '' && $dateTo !== '') {
            $from = $dateFrom . ' 00:00:01';
            $to = $dateTo . ' 23:59:59';
            $sql .= ' AND lg.ldateadded >= ? AND lg.ldateadded <= ?';
            $params[] = $from;
            $params[] = $to;
        }

        $sql .= ' GROUP BY lg.linvent_id, lg.lwarehouse';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Merge actual stock values into the pre-initialized structure
        foreach ($rows as $row) {
            $session = (string) ($row['item_session'] ?? '');
            $warehouseName = trim((string) ($row['warehouse_name'] ?? ''));
            if ($session === '' || $warehouseName === '') {
                continue;
            }
            if (isset($result[$session])) {
                $result[$session][$warehouseName] = (float) ($row['stock'] ?? 0);
            }
        }

        return $result;
    }
}
