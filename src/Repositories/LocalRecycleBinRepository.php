<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class LocalRecycleBinRepository
{
    private const RESTORABLE_TYPES = [
        'contact',
        'product',
        'purchase_request',
        'purchase_order',
        'receiving_report',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $mainId): array
    {
        $rows = array_merge(
            $this->listDeletedCustomers($mainId),
            $this->listDeletedProducts($mainId),
            $this->listDeletedProcurementRecords($mainId)
        );

        usort($rows, static function (array $a, array $b): int {
            $dateCompare = strcmp((string) ($b['deleted_at'] ?? ''), (string) ($a['deleted_at'] ?? ''));
            if ($dateCompare !== 0) return $dateCompare;
            return strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
        });

        return $rows;
    }

    public function restore(int $mainId, string $type, string $itemId): bool
    {
        $normalizedType = strtolower(trim($type));
        $normalizedItemId = trim($itemId);
        if ($mainId <= 0 || $normalizedItemId === '' || !in_array($normalizedType, self::RESTORABLE_TYPES, true)) {
            return false;
        }

        return match ($normalizedType) {
            'contact' => $this->restoreCustomer($mainId, $normalizedItemId),
            'product' => $this->restoreProduct($mainId, $normalizedItemId),
            'purchase_request' => $this->restorePurchaseRequest($mainId, $normalizedItemId),
            'purchase_order' => $this->restorePurchaseOrder($mainId, $normalizedItemId),
            'receiving_report' => $this->restoreReceivingReport($mainId, $normalizedItemId),
            default => false,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listDeletedCustomers(int $mainId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                COALESCE(lsessionid, "") AS item_id,
                COALESCE(NULLIF(lcompany, ""), lsessionid, "") AS record_number,
                CASE WHEN COALESCE(lstatus, 1) = 0 THEN "Deleted" ELSE CAST(COALESCE(lstatus, "") AS CHAR) END AS status,
                COALESCE(ldelete_reason, "") AS delete_reason,
                ldeleted_at AS deleted_at,
                ldeleted_by AS deleted_by
             FROM tblpatient
             WHERE lmain_id = :main_id
               AND COALESCE(ldeleted, 0) = 1
             ORDER BY COALESCE(ldeleted_at, ldatetime, ldatereg) DESC, lid DESC
             LIMIT 500'
        );
        $stmt->execute(['main_id' => $mainId]);
        return array_map(
            fn (array $row): array => $this->formatSoftDeletedRecord('contact', 'Customer', $row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listDeletedProducts(int $mainId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                COALESCE(lsession, "") AS item_id,
                COALESCE(NULLIF(litemcode, ""), lsession, "") AS record_number,
                CASE WHEN COALESCE(lstatus, 0) = 0 THEN "Deleted" ELSE CAST(COALESCE(lstatus, "") AS CHAR) END AS status,
                COALESCE(ldelete_reason, "") AS delete_reason,
                ldeleted_at AS deleted_at,
                ldeleted_by AS deleted_by
             FROM tblinventory_item
             WHERE lmain_id = :main_id
               AND COALESCE(ldeleted, 0) = 1
             ORDER BY COALESCE(ldeleted_at, ldateadded) DESC, lid DESC
             LIMIT 500'
        );
        $stmt->execute(['main_id' => $mainId]);
        return array_map(
            fn (array $row): array => $this->formatSoftDeletedRecord('product', 'Product', $row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listDeletedProcurementRecords(int $mainId): array
    {
        $records = [];

        $prStmt = $this->db->pdo()->query(
            'SELECT
                COALESCE(lrefno, "") AS item_id,
                COALESCE(lprno, lrefno, "") AS record_number,
                COALESCE(lstatus, "Deleted") AS status,
                COALESCE(ldelete_reason, "") AS delete_reason,
                ldeleted_at AS deleted_at,
                ldeleted_by AS deleted_by
             FROM tblpr_list
             WHERE COALESCE(ldeleted, 0) = 1
                OR LOWER(COALESCE(lstatus, "")) = "deleted"
             ORDER BY COALESCE(ldeleted_at, ldatetime) DESC, lid DESC
             LIMIT 500'
        );
        foreach ($prStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $records[] = $this->formatSoftDeletedRecord('purchase_request', 'Purchase Request', $row);
        }

        $poStmt = $this->db->pdo()->prepare(
            'SELECT
                COALESCE(lrefno, "") AS item_id,
                COALESCE(lpurchaseno, lrefno, "") AS record_number,
                COALESCE(ltransaction_status, "Deleted") AS status,
                COALESCE(ldelete_reason, "") AS delete_reason,
                ldeleted_at AS deleted_at,
                ldeleted_by AS deleted_by
             FROM tblpo_list
             WHERE lmain_id = :main_id
               AND (COALESCE(ldeleted, 0) = 1
                    OR LOWER(COALESCE(ltransaction_status, "")) = "deleted")
             ORDER BY COALESCE(ldeleted_at, ldate) DESC, lid DESC
             LIMIT 500'
        );
        $poStmt->execute(['main_id' => $mainId]);
        foreach ($poStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $records[] = $this->formatSoftDeletedRecord('purchase_order', 'Purchase Order', $row);
        }

        $rrStmt = $this->db->pdo()->prepare(
            'SELECT
                COALESCE(lrefno, "") AS item_id,
                COALESCE(lpurchaseno, lrefno, "") AS record_number,
                COALESCE(ltransaction_status, "Deleted") AS status,
                COALESCE(ldelete_reason, "") AS delete_reason,
                ldeleted_at AS deleted_at,
                ldeleted_by AS deleted_by
             FROM tblpurchase_order
             WHERE lmain_id = :main_id
               AND (COALESCE(ldeleted, 0) = 1
                    OR LOWER(COALESCE(ltransaction_status, "")) = "deleted")
             ORDER BY COALESCE(ldeleted_at, ldate) DESC, lid DESC
             LIMIT 500'
        );
        $rrStmt->execute(['main_id' => $mainId]);
        foreach ($rrStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $records[] = $this->formatSoftDeletedRecord('receiving_report', 'Receiving Report', $row);
        }

        return $records;
    }

    private function restoreCustomer(int $mainId, string $sessionId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tblpatient
             SET ldeleted = 0,
                 ldeleted_at = NULL,
                 ldeleted_by = NULL,
                 ldelete_reason = "",
                 lstatus = 1
             WHERE lmain_id = :main_id
               AND lsessionid = :session_id
               AND COALESCE(ldeleted, 0) = 1
             LIMIT 1'
        );
        $stmt->execute(['main_id' => $mainId, 'session_id' => $sessionId]);
        return $stmt->rowCount() > 0;
    }

    private function restoreProduct(int $mainId, string $sessionId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tblinventory_item
             SET ldeleted = 0,
                 ldeleted_at = NULL,
                 ldeleted_by = NULL,
                 ldelete_reason = "",
                 lnot_inventory = 0,
                 lstatus = 1
             WHERE lmain_id = :main_id
               AND lsession = :session_id
               AND COALESCE(ldeleted, 0) = 1
             LIMIT 1'
        );
        $stmt->execute(['main_id' => $mainId, 'session_id' => $sessionId]);
        return $stmt->rowCount() > 0;
    }

    private function restorePurchaseRequest(int $mainId, string $refno): bool
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE tblpr_list
                 SET ldeleted = 0,
                     ldeleted_at = NULL,
                     ldeleted_by = NULL,
                     ldelete_reason = "",
                     lstatus = CASE WHEN LOWER(COALESCE(lstatus, "")) = "deleted" THEN "Pending" ELSE lstatus END
                 WHERE lrefno = :refno
                   AND (COALESCE(ldeleted, 0) = 1 OR LOWER(COALESCE(lstatus, "")) = "deleted")
                 LIMIT 1'
            );
            $stmt->execute(['refno' => $refno]);
            $updated = $stmt->rowCount() > 0;
            if ($updated) {
                (new SuggestedStockReportRepository($this->db))->syncCoverageForPurchaseRequest($mainId, $refno);
            }
            $pdo->commit();
            return $updated;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function restorePurchaseOrder(int $mainId, string $refno): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tblpo_list
             SET ldeleted = 0,
                 ldeleted_at = NULL,
                 ldeleted_by = NULL,
                 ldelete_reason = "",
                 ltransaction_status = CASE WHEN LOWER(COALESCE(ltransaction_status, "")) = "deleted" THEN "Pending" ELSE ltransaction_status END
             WHERE lmain_id = :main_id
               AND lrefno = :refno
               AND (COALESCE(ldeleted, 0) = 1 OR LOWER(COALESCE(ltransaction_status, "")) = "deleted")
             LIMIT 1'
        );
        $stmt->execute(['main_id' => $mainId, 'refno' => $refno]);
        return $stmt->rowCount() > 0;
    }

    private function restoreReceivingReport(int $mainId, string $refno): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tblpurchase_order
             SET ldeleted = 0,
                 ldeleted_at = NULL,
                 ldeleted_by = NULL,
                 ldelete_reason = "",
                 ltransaction_status = CASE WHEN LOWER(COALESCE(ltransaction_status, "")) = "deleted" THEN "Pending" ELSE ltransaction_status END
             WHERE lmain_id = :main_id
               AND lrefno = :refno
               AND (COALESCE(ldeleted, 0) = 1 OR LOWER(COALESCE(ltransaction_status, "")) = "deleted")
             LIMIT 1'
        );
        $stmt->execute(['main_id' => $mainId, 'refno' => $refno]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatSoftDeletedRecord(string $type, string $module, array $row): array
    {
        $recordNumber = trim((string) ($row['record_number'] ?? ''));
        $itemId = trim((string) ($row['item_id'] ?? ''));
        return [
            'id' => $type . ':' . $itemId,
            'item_type' => $type,
            'record_type' => $type,
            'item_id' => $itemId,
            'label' => $recordNumber !== '' ? $recordNumber : $itemId,
            'record_number' => $recordNumber !== '' ? $recordNumber : $itemId,
            'module' => $module,
            'status' => (string) ($row['status'] ?? 'Deleted'),
            'delete_reason' => (string) ($row['delete_reason'] ?? ''),
            'deleted_at' => (string) ($row['deleted_at'] ?? ''),
            'deleted_by' => $row['deleted_by'] ?? null,
        ];
    }
}
