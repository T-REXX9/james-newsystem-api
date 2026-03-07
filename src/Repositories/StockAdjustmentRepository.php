<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class StockAdjustmentRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(int $mainId): array
    {
        $sql = <<<SQL
SELECT
    sa.lrefno,
    sa.ladjustment_number,
    sa.ldatetime,
    MAX(sa.lid) AS sort_id,
    COALESCE(sa.lstatus, 'Pending') AS lstatus,
    COALESCE(sa.ladjustment_type, 'physical_count') AS ladjustment_type,
    COALESCE(sa.lnotes, '') AS lnotes,
    COALESCE(MIN(sai.lwarehouse), 'WH1') AS warehouse_id,
    COUNT(sai.lid) AS item_count
FROM tblstock_adjustment sa
LEFT JOIN tblstock_adjustment_item sai ON sai.ladjustment_refno = sa.lrefno
WHERE CAST(COALESCE(sa.lmain_id, 0) AS SIGNED) = :main_id
GROUP BY sa.lrefno, sa.ladjustment_number, sa.ldatetime, sa.lstatus, sa.ladjustment_type, sa.lnotes
ORDER BY sort_id DESC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => array_map(fn (array $row): array => $this->normalizeHeaderRow($row), $rows),
            'meta' => [
                'total' => count($rows),
            ],
        ];
    }

    public function getByRefno(int $mainId, string $refno): ?array
    {
        $headerSql = <<<SQL
SELECT
    sa.lrefno,
    sa.ladjustment_number,
    sa.ldatetime,
    COALESCE(sa.luser_id, '') AS luser_id,
    COALESCE(sa.lstatus, 'Pending') AS lstatus,
    COALESCE(sa.ladjustment_type, 'physical_count') AS ladjustment_type,
    COALESCE(sa.lnotes, '') AS lnotes,
    COALESCE(MIN(sai.lwarehouse), 'WH1') AS warehouse_id
FROM tblstock_adjustment sa
LEFT JOIN tblstock_adjustment_item sai ON sai.ladjustment_refno = sa.lrefno
WHERE CAST(COALESCE(sa.lmain_id, 0) AS SIGNED) = :main_id
  AND sa.lrefno = :refno
GROUP BY sa.lrefno, sa.ladjustment_number, sa.ldatetime, sa.luser_id, sa.lstatus, sa.ladjustment_type, sa.lnotes
LIMIT 1
SQL;
        $headerStmt = $this->db->pdo()->prepare($headerSql);
        $headerStmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $headerStmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $headerStmt->execute();
        $header = $headerStmt->fetch(PDO::FETCH_ASSOC);
        if ($header === false) {
            return null;
        }

        $itemsSql = <<<SQL
SELECT
    sai.lid AS id,
    sai.ladjustment_refno AS adjustment_id,
    sai.litemsession AS item_id,
    CAST(COALESCE(sai.lold_qty, 0) AS SIGNED) AS system_qty,
    CAST(COALESCE(sai.ladjust_qty, 0) AS SIGNED) AS physical_qty,
    CAST(COALESCE(sai.ladjust_qty, 0) AS SIGNED) - CAST(COALESCE(sai.lold_qty, 0) AS SIGNED) AS difference,
    COALESCE(sai.lremarks, '') AS reason,
    COALESCE(sai.llocation, '') AS location,
    COALESCE(sai.lwarehouse, 'WH1') AS warehouse_id,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.ldescription, '') AS description
FROM tblstock_adjustment_item sai
LEFT JOIN tblinventory_item itm ON itm.lsession = sai.litemsession
WHERE sai.ladjustment_refno = :refno
ORDER BY sai.lid ASC
SQL;
        $itemStmt = $this->db->pdo()->prepare($itemsSql);
        $itemStmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $itemStmt->execute();
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $normalized = $this->normalizeHeaderRow($header);
        $normalized['processed_by'] = (string) ($header['luser_id'] ?? '');
        $normalized['items'] = array_map(
            static fn (array $item): array => [
                'id' => (string) ($item['id'] ?? ''),
                'adjustment_id' => (string) ($item['adjustment_id'] ?? ''),
                'item_id' => (string) ($item['item_id'] ?? ''),
                'system_qty' => (int) ($item['system_qty'] ?? 0),
                'physical_qty' => (int) ($item['physical_qty'] ?? 0),
                'difference' => (int) ($item['difference'] ?? 0),
                'reason' => (string) ($item['reason'] ?? ''),
                'location' => (string) ($item['location'] ?? ''),
                'warehouse_id' => (string) ($item['warehouse_id'] ?? 'WH1'),
                'part_no' => (string) ($item['part_no'] ?? ''),
                'item_code' => (string) ($item['item_code'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
            ],
            $items
        );

        return $normalized;
    }

    public function create(int $mainId, string $userId, array $payload): array
    {
        $adjustmentDate = $this->normalizeDateTime((string) ($payload['adjustment_date'] ?? 'now'));
        $warehouseId = trim((string) ($payload['warehouse_id'] ?? 'WH1'));
        $adjustmentType = trim((string) ($payload['adjustment_type'] ?? 'physical_count'));
        if (!in_array($adjustmentType, ['physical_count', 'damage', 'correction'], true)) {
            throw new RuntimeException('adjustment_type must be one of: physical_count, damage, correction');
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if ($items === []) {
            throw new RuntimeException('items are required');
        }

        $refno = date('YmdHis') . random_int(1000, 9999);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $adjustmentNumber = $this->nextAdjustmentNumber($pdo);

            $headerStmt = $pdo->prepare(
                'INSERT INTO tblstock_adjustment
                (lrefno, ldatetime, ladjustment_number, luser_id, lmain_id, lstatus, ladjustment_type, lnotes)
                VALUES
                (:refno, :datetime, :adjustment_number, :user_id, :main_id, :status, :adjustment_type, :notes)'
            );
            $headerStmt->execute([
                'refno' => $refno,
                'datetime' => $adjustmentDate,
                'adjustment_number' => $adjustmentNumber,
                'user_id' => $userId,
                'main_id' => (string) $mainId,
                'status' => 'Pending',
                'adjustment_type' => $adjustmentType,
                'notes' => trim((string) ($payload['notes'] ?? '')),
            ]);

            $counterStmt = $pdo->prepare(
                'INSERT INTO tblnumber_generator (lmax_no, ltransaction_type) VALUES (:max_no, :transaction_type)'
            );
            $counterStmt->execute([
                'max_no' => (int) substr($adjustmentNumber, -4),
                'transaction_type' => 'Stock Adjustment',
            ]);

            $itemStmt = $pdo->prepare(
                'INSERT INTO tblstock_adjustment_item
                (litemsession, lwarehouse, lold_qty, ladjust_qty, llocation, ldatetime, lremarks, linv_value, ladjustment_refno)
                VALUES
                (:item_session, :warehouse, :old_qty, :adjust_qty, :location, :datetime, :remarks, :inv_value, :refno)'
            );

            foreach ($items as $item) {
                $itemSession = trim((string) ($item['item_id'] ?? ''));
                if ($itemSession === '') {
                    throw new RuntimeException('item_id is required for each item');
                }

                $product = $this->findItem($mainId, $itemSession);
                if ($product === null) {
                    throw new RuntimeException('Inventory item not found: ' . $itemSession);
                }

                $systemQty = (int) ($item['system_qty'] ?? 0);
                $physicalQty = (int) ($item['physical_qty'] ?? 0);
                $difference = $physicalQty - $systemQty;
                $value = abs($difference) * (float) ($product['cost'] ?? 0);

                $itemStmt->execute([
                    'item_session' => $itemSession,
                    'warehouse' => $warehouseId,
                    'old_qty' => $systemQty,
                    'adjust_qty' => $physicalQty,
                    'location' => trim((string) ($item['location'] ?? '')),
                    'datetime' => $adjustmentDate,
                    'remarks' => trim((string) ($item['reason'] ?? '')),
                    'inv_value' => (string) $value,
                    'refno' => $refno,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $created = $this->getByRefno($mainId, $refno);
        if ($created === null) {
            throw new RuntimeException('Failed to load created stock adjustment');
        }

        return $created;
    }

    public function finalize(int $mainId, string $userId, string $refno): ?array
    {
        $existing = $this->getByRefno($mainId, $refno);
        if ($existing === null) {
            return null;
        }

        if (($existing['status'] ?? '') !== 'draft') {
            throw new RuntimeException('Only draft stock adjustments can be finalized');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $headerStmt = $pdo->prepare(
                'UPDATE tblstock_adjustment SET lstatus = :status WHERE lrefno = :refno AND CAST(COALESCE(lmain_id, 0) AS SIGNED) = :main_id'
            );
            $headerStmt->execute([
                'status' => 'Posted',
                'refno' => $refno,
                'main_id' => $mainId,
            ]);

            $deleteLogs = $pdo->prepare('DELETE FROM tblinventory_logs WHERE lrefno = :refno AND ltransaction_type = :transaction_type');
            $deleteLogs->execute([
                'refno' => $refno,
                'transaction_type' => 'Stock Adjustment',
            ]);

            $insertLog = $pdo->prepare(
                'INSERT INTO tblinventory_logs
                (linvent_id, lin, lout, ltotal, ldateadded, lprocess_by, lstatus_logs, lnote, linventory_id, lprice, lrefno, llocation, lwarehouse, lphysical_count, ltransaction_type, litemcode, lpartno)
                VALUES
                (:item_session, :qty_in, :qty_out, :total, :date_added, :process_by, :status_indicator, :note, :inventory_id, :price, :refno, :location, :warehouse, :physical_count, :transaction_type, :item_code, :part_no)'
            );

            foreach (($existing['items'] ?? []) as $item) {
                $difference = (int) ($item['difference'] ?? 0);
                if ($difference === 0) {
                    continue;
                }

                $product = $this->findItem($mainId, (string) ($item['item_id'] ?? ''));
                if ($product === null) {
                    continue;
                }

                $qtyIn = $difference > 0 ? $difference : 0;
                $qtyOut = $difference < 0 ? abs($difference) : 0;
                $statusIndicator = $difference > 0 ? '+' : '-';
                $note = 'STOCK ADJUSTMENT ' . strtoupper((string) ($existing['adjustment_type'] ?? 'physical_count')) . ': ' . (string) ($item['physical_qty'] ?? 0);

                $insertLog->execute([
                    'item_session' => (string) ($item['item_id'] ?? ''),
                    'qty_in' => $qtyIn,
                    'qty_out' => $qtyOut,
                    'total' => abs($difference),
                    'date_added' => $existing['adjustment_date'] . ' 00:00:00',
                    'process_by' => (string) ($existing['adjustment_no'] ?? ''),
                    'status_indicator' => $statusIndicator,
                    'note' => $note,
                    'inventory_id' => (string) ($product['inventory_id'] ?? $item['item_id'] ?? ''),
                    'price' => (string) ((float) ($product['cost'] ?? 0)),
                    'refno' => $refno,
                    'location' => trim((string) ($item['location'] ?? '')),
                    'warehouse' => (string) ($item['warehouse_id'] ?? 'WH1'),
                    'physical_count' => (int) ($item['physical_qty'] ?? 0),
                    'transaction_type' => 'Stock Adjustment',
                    'item_code' => (string) ($product['item_code'] ?? ''),
                    'part_no' => (string) ($product['part_no'] ?? ''),
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $this->getByRefno($mainId, $refno);
    }

    private function normalizeHeaderRow(array $row): array
    {
        return [
            'id' => (string) ($row['lrefno'] ?? ''),
            'adjustment_no' => (string) ($row['ladjustment_number'] ?? ''),
            'adjustment_date' => $this->toDate((string) ($row['ldatetime'] ?? '')),
            'warehouse_id' => (string) ($row['warehouse_id'] ?? 'WH1'),
            'adjustment_type' => (string) ($row['ladjustment_type'] ?? 'physical_count'),
            'notes' => (string) ($row['lnotes'] ?? ''),
            'status' => $this->mapStatus((string) ($row['lstatus'] ?? 'Pending')),
            'created_at' => (string) ($row['ldatetime'] ?? ''),
            'updated_at' => (string) ($row['ldatetime'] ?? ''),
        ];
    }

    private function mapStatus(string $status): string
    {
        return strcasecmp(trim($status), 'Posted') === 0 ? 'finalized' : 'draft';
    }

    private function toDate(string $value): string
    {
        $ts = strtotime($value);
        if ($ts === false) {
            return date('Y-m-d');
        }

        return date('Y-m-d', $ts);
    }

    private function nextAdjustmentNumber(PDO $pdo): string
    {
        $stmt = $pdo->prepare(
            'SELECT MAX(CAST(lmax_no AS UNSIGNED)) AS max_no
             FROM tblnumber_generator
             WHERE ltransaction_type = :transaction_type'
        );
        $stmt->execute(['transaction_type' => 'Stock Adjustment']);
        $max = (int) ($stmt->fetchColumn() ?: 0);
        $next = $max + 1;

        return 'SA' . date('y') . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function findItem(int $mainId, string $itemSession): ?array
    {
        $sql = <<<SQL
SELECT
    itm.lsession AS item_session,
    itm.lid AS inventory_id,
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

    private function normalizeDateTime(string $value): string
    {
        $raw = trim($value);
        $ts = strtotime($raw === '' ? 'now' : $raw);
        if ($ts === false) {
            throw new RuntimeException('Invalid adjustment_date value');
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
