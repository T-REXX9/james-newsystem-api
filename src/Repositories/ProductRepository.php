<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class ProductRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{
     *   data: array<int, array<string, mixed>>,
     *   meta: array<string, mixed>
     * }
     */
    public function listProducts(
        int $mainId,
        string $search = '',
        string $status = 'all',
        int $page = 1,
        int $perPage = 100
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $warehouseLabels = $this->getWarehouseLabels($mainId);
        $params = [
            'main_id' => $mainId,
            'limit' => $perPage,
            'offset' => $offset,
            'warehouse_1' => $warehouseLabels[0],
            'warehouse_2' => $warehouseLabels[1],
            'warehouse_3' => $warehouseLabels[2],
            'warehouse_4' => $warehouseLabels[3],
            'warehouse_5' => $warehouseLabels[4],
            'warehouse_6' => $warehouseLabels[5],
        ];

        $where = [
            'itm.lmain_id = :main_id',
            'COALESCE(itm.lnot_inventory, 0) = 0',
        ];

        if ($status === 'active') {
            $where[] = 'COALESCE(itm.lstatus, 0) = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'COALESCE(itm.lstatus, 0) <> 1';
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $params['search'] = '%' . $trimmedSearch . '%';
            $where[] = "CONCAT_WS(' ', itm.lsession, itm.litemcode, itm.lpartno, itm.ldescription, itm.lbrand, itm.lnickname, itm.loem_number, itm.lopn_number, itm.lapplication, itm.lbarcode) LIKE :search";
        }

        $whereSql = implode(' AND ', $where);

        $sql = <<<SQL
SELECT
    CAST(itm.lsession AS CHAR) AS id,
    itm.lsession AS legacy_session,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.loem_number, '') AS oem_no,
    COALESCE(brnd.lname, itm.lbrand, '') AS brand,
    COALESCE(itm.lbarcode, '') AS barcode,
    CAST(COALESCE(itm.lpcsperbox, 0) AS SIGNED) AS no_of_pieces_per_box,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.ldescription, '') AS description,
    COALESCE(itm.lsize, '') AS size,
    CAST(COALESCE(itm.lreorder_amt, 0) AS SIGNED) AS reorder_quantity,
    CASE
        WHEN COALESCE(itm.lstatus, 0) = 1 THEN 'Active'
        WHEN COALESCE(itm.lstatus, 0) = 0 THEN 'Inactive'
        ELSE 'Discontinued'
    END AS status,
    COALESCE(cat.lname, itm.lproduct_group, '') AS category,
    COALESCE(itm.lnickname, '') AS descriptive_inquiry,
    COALESCE(itm.lholes, '') AS no_of_holes,
    CAST(COALESCE(itm.lreplenish, 0) AS SIGNED) AS replenish_quantity,
    COALESCE(itm.lopn_number, '') AS original_pn_no,
    COALESCE(itm.lapplication, '') AS application,
    COALESCE(itm.lcylinder, '') AS no_of_cylinder,
    CAST(COALESCE(itm.lcost, 0) AS DECIMAL(15,2)) AS cost,
    CAST(COALESCE((
        SELECT ip.lprice_amt
        FROM tblinventory_price ip
        WHERE ip.linv_refno = itm.lsession AND ip.lprice_name = 'AAA'
        ORDER BY ip.lid DESC
        LIMIT 1
    ), 0) AS DECIMAL(15,2)) AS price_aa,
    CAST(COALESCE((
        SELECT ip.lprice_amt
        FROM tblinventory_price ip
        WHERE ip.linv_refno = itm.lsession AND ip.lprice_name = 'ABB'
        ORDER BY ip.lid DESC
        LIMIT 1
    ), 0) AS DECIMAL(15,2)) AS price_bb,
    CAST(COALESCE((
        SELECT ip.lprice_amt
        FROM tblinventory_price ip
        WHERE ip.linv_refno = itm.lsession AND ip.lprice_name = 'ACC'
        ORDER BY ip.lid DESC
        LIMIT 1
    ), 0) AS DECIMAL(15,2)) AS price_cc,
    CAST(COALESCE((
        SELECT ip.lprice_amt
        FROM tblinventory_price ip
        WHERE ip.linv_refno = itm.lsession AND ip.lprice_name = 'ADD'
        ORDER BY ip.lid DESC
        LIMIT 1
    ), 0) AS DECIMAL(15,2)) AS price_dd,
    CAST(COALESCE((
        SELECT ip.lprice_amt
        FROM tblinventory_price ip
        WHERE ip.linv_refno = itm.lsession AND ip.lprice_name = 'VIP 1'
        ORDER BY ip.lid DESC
        LIMIT 1
    ), 0) AS DECIMAL(15,2)) AS price_vip1,
    CAST(COALESCE((
        SELECT ip.lprice_amt
        FROM tblinventory_price ip
        WHERE ip.linv_refno = itm.lsession AND ip.lprice_name = 'VIP2'
        ORDER BY ip.lid DESC
        LIMIT 1
    ), 0) AS DECIMAL(15,2)) AS price_vip2,
    CAST(COALESCE((
        SELECT SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0))
        FROM tblinventory_logs lg
        WHERE lg.linvent_id = itm.lsession
          AND lg.lwarehouse = :warehouse_1
    ), 0) AS DECIMAL(15,2)) AS stock_wh1,
    CAST(COALESCE((
        SELECT SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0))
        FROM tblinventory_logs lg
        WHERE lg.linvent_id = itm.lsession
          AND lg.lwarehouse = :warehouse_2
    ), 0) AS DECIMAL(15,2)) AS stock_wh2,
    CAST(COALESCE((
        SELECT SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0))
        FROM tblinventory_logs lg
        WHERE lg.linvent_id = itm.lsession
          AND lg.lwarehouse = :warehouse_3
    ), 0) AS DECIMAL(15,2)) AS stock_wh3,
    CAST(COALESCE((
        SELECT SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0))
        FROM tblinventory_logs lg
        WHERE lg.linvent_id = itm.lsession
          AND lg.lwarehouse = :warehouse_4
    ), 0) AS DECIMAL(15,2)) AS stock_wh4,
    CAST(COALESCE((
        SELECT SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0))
        FROM tblinventory_logs lg
        WHERE lg.linvent_id = itm.lsession
          AND lg.lwarehouse = :warehouse_5
    ), 0) AS DECIMAL(15,2)) AS stock_wh5,
    CAST(COALESCE((
        SELECT SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0))
        FROM tblinventory_logs lg
        WHERE lg.linvent_id = itm.lsession
          AND lg.lwarehouse = :warehouse_6
    ), 0) AS DECIMAL(15,2)) AS stock_wh6,
    CAST(COALESCE(itm.lstatus, 0) AS SIGNED) AS legacy_status,
    CAST(COALESCE(itm.lnot_inventory, 0) AS SIGNED) AS is_deleted
FROM tblinventory_item itm
LEFT JOIN tblcategory cat ON cat.lid = itm.lcategory
LEFT JOIN tblbrand brnd ON brnd.lid = itm.lbrand
WHERE {$whereSql}
ORDER BY itm.lpartno ASC, itm.litemcode ASC, itm.lid DESC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $params['main_id'], PDO::PARAM_INT);
        $stmt->bindValue('warehouse_1', $params['warehouse_1'], PDO::PARAM_STR);
        $stmt->bindValue('warehouse_2', $params['warehouse_2'], PDO::PARAM_STR);
        $stmt->bindValue('warehouse_3', $params['warehouse_3'], PDO::PARAM_STR);
        $stmt->bindValue('warehouse_4', $params['warehouse_4'], PDO::PARAM_STR);
        $stmt->bindValue('warehouse_5', $params['warehouse_5'], PDO::PARAM_STR);
        $stmt->bindValue('warehouse_6', $params['warehouse_6'], PDO::PARAM_STR);
        if (isset($params['search'])) {
            $stmt->bindValue('search', $params['search'], PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $params['limit'], PDO::PARAM_INT);
        $stmt->bindValue('offset', $params['offset'], PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countSql = <<<SQL
SELECT COUNT(*) AS total
FROM tblinventory_item itm
WHERE {$whereSql}
SQL;
        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        if (isset($params['search'])) {
            $countStmt->bindValue('search', $params['search'], PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        return [
            'items' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
                'warehouse_labels' => [
                    'wh1' => $warehouseLabels[0],
                    'wh2' => $warehouseLabels[1],
                    'wh3' => $warehouseLabels[2],
                    'wh4' => $warehouseLabels[3],
                    'wh5' => $warehouseLabels[4],
                    'wh6' => $warehouseLabels[5],
                ],
            ],
        ];
    }

    public function getProductBySession(int $mainId, string $productSession): ?array
    {
        $warehouseLabels = $this->getWarehouseLabels($mainId);

        $sql = <<<SQL
SELECT
    CAST(itm.lsession AS CHAR) AS id,
    itm.lsession AS legacy_session,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.loem_number, '') AS oem_no,
    COALESCE(brnd.lname, itm.lbrand, '') AS brand,
    COALESCE(itm.lbarcode, '') AS barcode,
    CAST(COALESCE(itm.lpcsperbox, 0) AS SIGNED) AS no_of_pieces_per_box,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.ldescription, '') AS description,
    COALESCE(itm.lsize, '') AS size,
    CAST(COALESCE(itm.lreorder_amt, 0) AS SIGNED) AS reorder_quantity,
    CASE
        WHEN COALESCE(itm.lstatus, 0) = 1 THEN 'Active'
        WHEN COALESCE(itm.lstatus, 0) = 0 THEN 'Inactive'
        ELSE 'Discontinued'
    END AS status,
    COALESCE(cat.lname, itm.lproduct_group, '') AS category,
    COALESCE(itm.lnickname, '') AS descriptive_inquiry,
    COALESCE(itm.lholes, '') AS no_of_holes,
    CAST(COALESCE(itm.lreplenish, 0) AS SIGNED) AS replenish_quantity,
    COALESCE(itm.lopn_number, '') AS original_pn_no,
    COALESCE(itm.lapplication, '') AS application,
    COALESCE(itm.lcylinder, '') AS no_of_cylinder,
    CAST(COALESCE(itm.lcost, 0) AS DECIMAL(15,2)) AS cost,
    CAST(COALESCE((
        SELECT ip.lprice_amt
        FROM tblinventory_price ip
        WHERE ip.linv_refno = itm.lsession AND ip.lprice_name = 'AAA'
        ORDER BY ip.lid DESC
        LIMIT 1
    ), 0) AS DECIMAL(15,2)) AS price_aa,
    CAST(COALESCE((
        SELECT ip.lprice_amt
        FROM tblinventory_price ip
        WHERE ip.linv_refno = itm.lsession AND ip.lprice_name = 'ABB'
        ORDER BY ip.lid DESC
        LIMIT 1
    ), 0) AS DECIMAL(15,2)) AS price_bb,
    CAST(COALESCE((
        SELECT ip.lprice_amt
        FROM tblinventory_price ip
        WHERE ip.linv_refno = itm.lsession AND ip.lprice_name = 'ACC'
        ORDER BY ip.lid DESC
        LIMIT 1
    ), 0) AS DECIMAL(15,2)) AS price_cc,
    CAST(COALESCE((
        SELECT ip.lprice_amt
        FROM tblinventory_price ip
        WHERE ip.linv_refno = itm.lsession AND ip.lprice_name = 'ADD'
        ORDER BY ip.lid DESC
        LIMIT 1
    ), 0) AS DECIMAL(15,2)) AS price_dd,
    CAST(COALESCE((
        SELECT ip.lprice_amt
        FROM tblinventory_price ip
        WHERE ip.linv_refno = itm.lsession AND ip.lprice_name = 'VIP 1'
        ORDER BY ip.lid DESC
        LIMIT 1
    ), 0) AS DECIMAL(15,2)) AS price_vip1,
    CAST(COALESCE((
        SELECT ip.lprice_amt
        FROM tblinventory_price ip
        WHERE ip.linv_refno = itm.lsession AND ip.lprice_name = 'VIP2'
        ORDER BY ip.lid DESC
        LIMIT 1
    ), 0) AS DECIMAL(15,2)) AS price_vip2,
    CAST(COALESCE((
        SELECT SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0))
        FROM tblinventory_logs lg
        WHERE lg.linvent_id = itm.lsession
          AND lg.lwarehouse = :warehouse_1
    ), 0) AS DECIMAL(15,2)) AS stock_wh1,
    CAST(COALESCE((
        SELECT SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0))
        FROM tblinventory_logs lg
        WHERE lg.linvent_id = itm.lsession
          AND lg.lwarehouse = :warehouse_2
    ), 0) AS DECIMAL(15,2)) AS stock_wh2,
    CAST(COALESCE((
        SELECT SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0))
        FROM tblinventory_logs lg
        WHERE lg.linvent_id = itm.lsession
          AND lg.lwarehouse = :warehouse_3
    ), 0) AS DECIMAL(15,2)) AS stock_wh3,
    CAST(COALESCE((
        SELECT SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0))
        FROM tblinventory_logs lg
        WHERE lg.linvent_id = itm.lsession
          AND lg.lwarehouse = :warehouse_4
    ), 0) AS DECIMAL(15,2)) AS stock_wh4,
    CAST(COALESCE((
        SELECT SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0))
        FROM tblinventory_logs lg
        WHERE lg.linvent_id = itm.lsession
          AND lg.lwarehouse = :warehouse_5
    ), 0) AS DECIMAL(15,2)) AS stock_wh5,
    CAST(COALESCE((
        SELECT SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0))
        FROM tblinventory_logs lg
        WHERE lg.linvent_id = itm.lsession
          AND lg.lwarehouse = :warehouse_6
    ), 0) AS DECIMAL(15,2)) AS stock_wh6,
    CAST(COALESCE(itm.lstatus, 0) AS SIGNED) AS legacy_status,
    CAST(COALESCE(itm.lnot_inventory, 0) AS SIGNED) AS is_deleted
FROM tblinventory_item itm
LEFT JOIN tblcategory cat ON cat.lid = itm.lcategory
LEFT JOIN tblbrand brnd ON brnd.lid = itm.lbrand
WHERE itm.lmain_id = :main_id
  AND itm.lsession = :session
  AND COALESCE(itm.lnot_inventory, 0) = 0
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('session', $productSession, PDO::PARAM_STR);
        $stmt->bindValue('warehouse_1', $warehouseLabels[0], PDO::PARAM_STR);
        $stmt->bindValue('warehouse_2', $warehouseLabels[1], PDO::PARAM_STR);
        $stmt->bindValue('warehouse_3', $warehouseLabels[2], PDO::PARAM_STR);
        $stmt->bindValue('warehouse_4', $warehouseLabels[3], PDO::PARAM_STR);
        $stmt->bindValue('warehouse_5', $warehouseLabels[4], PDO::PARAM_STR);
        $stmt->bindValue('warehouse_6', $warehouseLabels[5], PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function createProduct(int $mainId, int $userId, array $payload): array
    {
        $session = $this->generateSessionId();
        $status = $this->mapStatusToLegacy($payload['status'] ?? 'Active');
        $itemCode = trim((string) ($payload['item_code'] ?? ''));
        if ($itemCode === '') {
            $itemCode = 'API-' . substr(preg_replace('/\D+/', '', $session), -8);
        }

        $sql = <<<SQL
INSERT INTO tblinventory_item (
    lsession, lmain_id, litemcode, ldescription, lpartno, loem_number, lbrand, lbarcode,
    lpcsperbox, lsize, lreorder_amt, lstatus, lproduct_group, lnickname, lholes, lreplenish,
    lopn_number, lapplication, lcylinder, lcost, lnot_inventory, linv_stat, ltrackable, ldateadded, laddedby
) VALUES (
    :lsession, :lmain_id, :litemcode, :ldescription, :lpartno, :loem_number, :lbrand, :lbarcode,
    :lpcsperbox, :lsize, :lreorder_amt, :lstatus, :lproduct_group, :lnickname, :lholes, :lreplenish,
    :lopn_number, :lapplication, :lcylinder, :lcost, 0, '', 'Yes', CURDATE(), :laddedby
)
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'lsession' => $session,
            'lmain_id' => $mainId,
            'litemcode' => $itemCode,
            'ldescription' => $this->strVal($payload['description'] ?? ''),
            'lpartno' => $this->strVal($payload['part_no'] ?? ''),
            'loem_number' => $this->strVal($payload['oem_no'] ?? ''),
            'lbrand' => $this->strVal($payload['brand'] ?? ''),
            'lbarcode' => $this->strVal($payload['barcode'] ?? ''),
            'lpcsperbox' => (int) ($payload['no_of_pieces_per_box'] ?? 0),
            'lsize' => $this->strVal($payload['size'] ?? ''),
            'lreorder_amt' => (int) ($payload['reorder_quantity'] ?? 0),
            'lstatus' => $status,
            'lproduct_group' => $this->strVal($payload['category'] ?? ''),
            'lnickname' => $this->strVal($payload['descriptive_inquiry'] ?? ''),
            'lholes' => $this->strVal($payload['no_of_holes'] ?? ''),
            'lreplenish' => (int) ($payload['replenish_quantity'] ?? 0),
            'lopn_number' => $this->strVal($payload['original_pn_no'] ?? ''),
            'lapplication' => $this->strVal($payload['application'] ?? ''),
            'lcylinder' => $this->strVal($payload['no_of_cylinder'] ?? ''),
            'lcost' => (float) ($payload['cost'] ?? 0),
            'laddedby' => $userId > 0 ? $userId : null,
        ]);

        $this->syncPrices($session, $payload);
        $this->syncWarehouseStocks($mainId, $session, $payload, $userId);

        $item = $this->getProductBySession($mainId, $session);
        return $item ?? ['id' => $session];
    }

    public function updateProduct(int $mainId, string $productSession, array $payload): ?array
    {
        $existing = $this->getProductBySession($mainId, $productSession);
        if ($existing === null) {
            return null;
        }

        $sets = [];
        $params = [
            'main_id' => $mainId,
            'session' => $productSession,
        ];
        $map = [
            'part_no' => 'lpartno',
            'oem_no' => 'loem_number',
            'brand' => 'lbrand',
            'barcode' => 'lbarcode',
            'no_of_pieces_per_box' => 'lpcsperbox',
            'item_code' => 'litemcode',
            'description' => 'ldescription',
            'size' => 'lsize',
            'reorder_quantity' => 'lreorder_amt',
            'category' => 'lproduct_group',
            'descriptive_inquiry' => 'lnickname',
            'no_of_holes' => 'lholes',
            'replenish_quantity' => 'lreplenish',
            'original_pn_no' => 'lopn_number',
            'application' => 'lapplication',
            'no_of_cylinder' => 'lcylinder',
            'cost' => 'lcost',
        ];

        foreach ($map as $apiField => $dbField) {
            if (!array_key_exists($apiField, $payload)) {
                continue;
            }
            $paramName = 'p_' . $dbField;
            $sets[] = "{$dbField} = :{$paramName}";
            if (in_array($apiField, ['no_of_pieces_per_box', 'reorder_quantity', 'replenish_quantity'], true)) {
                $params[$paramName] = (int) $payload[$apiField];
            } elseif ($apiField === 'cost') {
                $params[$paramName] = (float) $payload[$apiField];
            } else {
                $params[$paramName] = $this->strVal($payload[$apiField]);
            }
        }

        if (array_key_exists('status', $payload)) {
            $sets[] = 'lstatus = :p_lstatus';
            $params['p_lstatus'] = $this->mapStatusToLegacy($payload['status']);
        }

        if (count($sets) > 0) {
            $sql = 'UPDATE tblinventory_item SET ' . implode(', ', $sets) . ' WHERE lmain_id = :main_id AND lsession = :session LIMIT 1';
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute($params);
        }

        $userId = (int) ($payload['user_id'] ?? 0);
        $this->syncPrices($productSession, $payload);
        $this->syncWarehouseStocks($mainId, $productSession, $payload, $userId);

        return $this->getProductBySession($mainId, $productSession);
    }

    public function bulkUpdateProducts(int $mainId, array $ids, array $updates): array
    {
        $updated = 0;
        foreach ($ids as $id) {
            $session = trim((string) $id);
            if ($session === '') {
                continue;
            }
            $row = $this->updateProduct($mainId, $session, $updates);
            if ($row !== null) {
                $updated++;
            }
        }

        return [
            'updated_count' => $updated,
            'requested_count' => count($ids),
        ];
    }

    public function deleteProduct(int $mainId, string $productSession): bool
    {
        $sql = <<<SQL
UPDATE tblinventory_item
SET lstatus = 0,
    lnot_inventory = 1
WHERE lmain_id = :main_id
  AND lsession = :session
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => $mainId,
            'session' => $productSession,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return string[]
     */
    private function getWarehouseLabels(int $mainId): array
    {
        $sql = <<<SQL
SELECT b.lname, MIN(b.lid) AS min_lid
FROM tblbranch b
WHERE COALESCE(b.lstatus, 1) = 1
  AND (
      CAST(COALESCE(b.lmain_id, '') AS CHAR) = CAST(:main_id AS CHAR)
      OR COALESCE(b.lmain_id, '') = ''
  )
GROUP BY b.lname
ORDER BY min_lid ASC
LIMIT 6
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['main_id' => $mainId]);
        $names = array_values(array_filter(array_map(
            static fn(array $row): string => trim((string) ($row['lname'] ?? '')),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        )));

        $defaults = ['WH1', 'WH2', 'WH3', 'WH4', 'WH5', 'WH6'];
        $labels = [];
        for ($i = 0; $i < 6; $i++) {
            $labels[] = $names[$i] ?? $defaults[$i];
        }
        return $labels;
    }

    private function mapStatusToLegacy(mixed $status): int
    {
        $value = strtolower(trim((string) $status));
        return match ($value) {
            'active' => 1,
            'inactive' => 0,
            'discontinued' => 2,
            default => 1,
        };
    }

    private function syncPrices(string $productSession, array $payload): void
    {
        $mapping = [
            'price_aa' => 'AAA',
            'price_bb' => 'ABB',
            'price_cc' => 'ACC',
            'price_dd' => 'ADD',
            'price_vip1' => 'VIP 1',
            'price_vip2' => 'VIP2',
        ];

        foreach ($mapping as $field => $groupName) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $amount = (float) ($payload[$field] ?? 0);
            $this->upsertPriceGroup($productSession, $groupName, $amount);
        }
    }

    private function upsertPriceGroup(string $productSession, string $groupName, float $amount): void
    {
        $pdo = $this->db->pdo();
        $find = $pdo->prepare(
            'SELECT lid, lprice_amt FROM tblinventory_price WHERE linv_refno = :session AND lprice_name = :group_name ORDER BY lid DESC LIMIT 1'
        );
        $find->execute([
            'session' => $productSession,
            'group_name' => $groupName,
        ]);
        $row = $find->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $update = $pdo->prepare(
                'UPDATE tblinventory_price SET lprice_amt_old = :old, lprice_amt = :new WHERE lid = :id'
            );
            $update->execute([
                'old' => (string) ($row['lprice_amt'] ?? '0'),
                'new' => (string) $amount,
                'id' => (int) $row['lid'],
            ]);
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO tblinventory_price (lrefno, linv_refno, lprice_name, lprice_amt, lprice_amt_old) VALUES (:refno, :session, :group_name, :amount, :old)'
        );
        $insert->execute([
            'refno' => $this->generateSessionId(),
            'session' => $productSession,
            'group_name' => $groupName,
            'amount' => (string) $amount,
            'old' => '0',
        ]);
    }

    private function syncWarehouseStocks(int $mainId, string $productSession, array $payload, int $userId): void
    {
        $labels = $this->getWarehouseLabels($mainId);
        $fieldMap = [
            'stock_wh1' => $labels[0],
            'stock_wh2' => $labels[1],
            'stock_wh3' => $labels[2],
            'stock_wh4' => $labels[3],
            'stock_wh5' => $labels[4],
            'stock_wh6' => $labels[5],
        ];

        foreach ($fieldMap as $field => $warehouse) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $target = (float) ($payload[$field] ?? 0);
            $this->syncSingleWarehouseStock($productSession, $warehouse, $target, $userId);
        }
    }

    private function syncSingleWarehouseStock(string $productSession, string $warehouse, float $targetStock, int $userId): void
    {
        $pdo = $this->db->pdo();

        $currStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(COALESCE(lin,0) - COALESCE(lout,0)), 0) AS qty FROM tblinventory_logs WHERE linvent_id = :session AND lwarehouse = :warehouse'
        );
        $currStmt->execute([
            'session' => $productSession,
            'warehouse' => $warehouse,
        ]);
        $current = (float) ($currStmt->fetchColumn() ?: 0);
        $diff = $targetStock - $current;

        if (abs($diff) < 0.0001) {
            return;
        }

        $itemStmt = $pdo->prepare(
            'SELECT litemcode, lpartno FROM tblinventory_item WHERE lsession = :session LIMIT 1'
        );
        $itemStmt->execute(['session' => $productSession]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $in = $diff > 0 ? (int) round(abs($diff)) : 0;
        $out = $diff < 0 ? (int) round(abs($diff)) : 0;
        $statusLog = $diff >= 0 ? '+' : '-';

        $insert = $pdo->prepare(
            'INSERT INTO tblinventory_logs (linvent_id, lin, lout, ltotal, ldateadded, lprocess_by, lstatus_logs, lnote, linventory_id, lrefno, lwarehouse, ltransaction_type, litemcode, lpartno)
             VALUES (:session, :lin, :lout, :ltotal, NOW(), :process_by, :status_logs, :note, :inventory_id, :refno, :warehouse, :transaction_type, :itemcode, :partno)'
        );
        $insert->execute([
            'session' => $productSession,
            'lin' => $in,
            'lout' => $out,
            'ltotal' => (int) round(abs($diff)),
            'process_by' => $userId > 0 ? (string) $userId : '',
            'status_logs' => $statusLog,
            'note' => 'API Product Stock Sync',
            'inventory_id' => $productSession,
            'refno' => $this->generateSessionId(),
            'warehouse' => $warehouse,
            'transaction_type' => 'API Product Sync',
            'itemcode' => (string) ($item['litemcode'] ?? ''),
            'partno' => (string) ($item['lpartno'] ?? ''),
        ]);
    }

    private function generateSessionId(): string
    {
        return (string) (time() . random_int(100000, 999999));
    }

    private function strVal(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }
}
