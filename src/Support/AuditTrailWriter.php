<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

final class AuditTrailWriter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function write(
        int $mainId,
        int $userId,
        string $page,
        string $action,
        string $refno,
        string $reason = '',
        string $oldStatus = '',
        string $newStatus = ''
    ): void {
        if ($mainId <= 0 || $userId <= 0) {
            return;
        }

        try {
            $auditRefno = $this->resolveAuditReference($mainId, $page, $refno);
            $stmt = $this->pdo->prepare(
                'INSERT INTO tblaudit_trail
                    (lmain_id, luser_id, lpage, laction, lrefno, lreason, lold_status, lnew_status, ldatetime)
                 VALUES
                    (:main_id, :user_id, :page, :action, :refno, :reason, :old_status, :new_status, NOW())'
            );
            $stmt->execute([
                'main_id' => $mainId,
                'user_id' => $userId,
                'page' => trim($page),
                'action' => trim($action),
                'refno' => $auditRefno,
                'reason' => trim($reason) !== '' ? trim($reason) : null,
                'old_status' => trim($oldStatus) !== '' ? trim($oldStatus) : null,
                'new_status' => trim($newStatus) !== '' ? trim($newStatus) : null,
            ]);

            $this->writeNotification($mainId, $userId, $page, $action, $refno, $newStatus);
        } catch (Throwable) {
            // Audit writes should not block the primary workflow.
        }
    }

    private function resolveAuditReference(int $mainId, string $page, string $refno): string
    {
        $pageKey = strtolower(trim($page));
        $refno = trim($refno);
        if ($refno === '') return '';

        [$sql, $params] = match ($pageKey) {
            'freight charges' => [
                'SELECT NULLIF(TRIM(ldm_no), \'\') FROM tbldebit_memo WHERE CAST(COALESCE(lmain_id, 0) AS SIGNED) = :main_id AND CAST(lrefno AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['main_id' => $mainId, 'refno' => $refno],
            ],
            'daily collection entry', 'collection report' => [
                'SELECT NULLIF(TRIM(lcolection_no), \'\') FROM tblcollection WHERE CAST(COALESCE(lmain_id, 0) AS SIGNED) = :main_id AND CAST(lrefno AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['main_id' => $mainId, 'refno' => $refno],
            ],
            'adjustment entry' => [
                'SELECT NULLIF(TRIM(lno), \'\') FROM tbladjustment WHERE CAST(lrefno AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['refno' => $refno],
            ],
            'order slip' => [
                'SELECT NULLIF(TRIM(linvoice_no), \'\') FROM tbldelivery_receipt WHERE CAST(lmain_id AS SIGNED) = :main_id AND CAST(lrefno AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['main_id' => $mainId, 'refno' => $refno],
            ],
            'sales inquiry' => [
                'SELECT NULLIF(TRIM(linqno), \'\') FROM tblinquiry WHERE CAST(lmain_id AS SIGNED) = :main_id AND CAST(lrefno AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['main_id' => $mainId, 'refno' => $refno],
            ],
            'sales order' => [
                'SELECT NULLIF(TRIM(lsaleno), \'\') FROM tbltransaction WHERE CAST(lmain_id AS SIGNED) = :main_id AND CAST(lrefno AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['main_id' => $mainId, 'refno' => $refno],
            ],
            'transfer product' => [
                'SELECT NULLIF(TRIM(ltransfer_no), \'\') FROM tblbranchinventory_transferlist WHERE CAST(lmain_id AS SIGNED) = :main_id AND CAST(lrefno AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['main_id' => $mainId, 'refno' => $refno],
            ],
            'customer database', 'daily call monitoring dashboard' => [
                'SELECT COALESCE(NULLIF(TRIM(lpatient_code), \'\'), NULLIF(TRIM(lcompany), \'\')) FROM tblpatient WHERE CAST(lmain_id AS SIGNED) = :main_id AND CAST(lsessionid AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['main_id' => $mainId, 'refno' => $refno],
            ],
            'invoice' => [
                'SELECT NULLIF(TRIM(linvoice_no), \'\') FROM tblinvoice_list WHERE CAST(lmain_id AS SIGNED) = :main_id AND CAST(lrefno AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['main_id' => $mainId, 'refno' => $refno],
            ],
            'product database' => [
                'SELECT COALESCE(NULLIF(TRIM(litemcode), \'\'), NULLIF(TRIM(lpartno), \'\')) FROM tblinventory_item WHERE CAST(lmain_id AS SIGNED) = :main_id AND CAST(lsession AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['main_id' => $mainId, 'refno' => $refno],
            ],
            'receiving report' => [
                'SELECT NULLIF(TRIM(lpurchaseno), \'\') FROM tblpurchase_order WHERE CAST(lmain_id AS SIGNED) = :main_id AND CAST(lrefno AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['main_id' => $mainId, 'refno' => $refno],
            ],
            'sales return entry' => [
                'SELECT NULLIF(TRIM(lcredit_no), \'\') FROM tblcredit_memo WHERE CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id AND CAST(lrefno AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['main_id' => $mainId, 'refno' => $refno],
            ],
            'purchase order' => [
                'SELECT COALESCE((SELECT NULLIF(TRIM(lpurchaseno), \'\') FROM tblpo_list WHERE CAST(lmain_id AS SIGNED) = :main_id_a AND (CAST(lrefno AS BINARY) = CAST(:refno_a AS BINARY) OR CAST(lpr_refno AS BINARY) = CAST(:refno_b AS BINARY)) ORDER BY lid DESC LIMIT 1), (SELECT NULLIF(TRIM(lpurchaseno), \'\') FROM tblpurchase_order WHERE CAST(lmain_id AS SIGNED) = :main_id_b AND CAST(lrefno AS BINARY) = CAST(:refno_c AS BINARY) ORDER BY lid DESC LIMIT 1))',
                ['main_id_a' => $mainId, 'refno_a' => $refno, 'refno_b' => $refno, 'main_id_b' => $mainId, 'refno_c' => $refno],
            ],
            'purchase request' => [
                'SELECT NULLIF(TRIM(lprno), \'\') FROM tblpr_list WHERE CAST(lrefno AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['refno' => $refno],
            ],
            'inventory audit' => [
                'SELECT NULLIF(TRIM(ladjustment_number), \'\') FROM tblstock_adjustment WHERE CAST(lmain_id AS SIGNED) = :main_id AND CAST(lrefno AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['main_id' => $mainId, 'refno' => $refno],
            ],
            'stock adjustment' => [
                'SELECT NULLIF(TRIM(ladjustment_number), \'\') FROM tblstock_adjustment WHERE CAST(lmain_id AS SIGNED) = :main_id AND CAST(lrefno AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['main_id' => $mainId, 'refno' => $refno],
            ],
            'return to supplier' => [
                'SELECT NULLIF(TRIM(lcredit_no), \'\') FROM tblreturn_supplier WHERE CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id AND CAST(lrefno AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['main_id' => $mainId, 'refno' => $refno],
            ],
            'transfer stock' => [
                'SELECT NULLIF(TRIM(ltransfer_no), \'\') FROM tblbranchinventory_transferlist WHERE CAST(lmain_id AS SIGNED) = :main_id AND CAST(lrefno AS BINARY) = CAST(:refno AS BINARY) ORDER BY lid DESC LIMIT 1',
                ['main_id' => $mainId, 'refno' => $refno],
            ],
            default => [null, []],
        };

        if (is_string($sql)) {
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $resolved = trim((string) ($stmt->fetchColumn() ?: ''));
                if ($resolved !== '') return $resolved;
            } catch (Throwable) {
                // Keep legacy activity recording available if a source table is absent.
            }
        }

        return $this->isFamiliarRecordNumber($refno) ? $refno : 'Reference unavailable';
    }

    private function isFamiliarRecordNumber(string $refno): bool
    {
        return preg_match('/^(DM|CM|INQ|NSO|N-D|RR|PO|PR|SA|TR|INV|DCR|COD|T|SO|OS|DR|RS|RTS|AJ|TT)[A-Z0-9-]*[0-9][A-Z0-9-]*$/i', $refno) === 1;
    }

    private function writeNotification(
        int $mainId,
        int $userId,
        string $page,
        string $action,
        string $refno,
        string $newStatus
    ): void {
        $page = trim($page);
        $action = trim($action);
        $refno = trim($refno);
        if ($page === '' || $action === '' || $refno === '') {
            return;
        }

        $target = $this->resolveNotificationTarget($mainId, $page, $refno);
        // Call report events have recipient-specific, detailed notifications in
        // CallReportRepository. Keep their audit row, but never add a generic
        // second notification for the same event.
        if ($target['entity_type'] === 'agent_sales_report') {
            return;
        }
        $entityType = $target['entity_type'];
        $actionKey = strtolower(trim((string) (preg_replace('/[^a-z0-9]+/i', '_', $action) ?? 'action'), '_'));
        $notificationRefno = $entityType . ':' . $target['record_id'] . ':' . $actionKey;
        $actorName = $this->actorName($userId);
        $metadata = json_encode([
            'e' => $entityType,
            'i' => $target['record_id'],
            'a' => $actionKey,
            's' => trim($newStatus) !== '' ? $newStatus : 'created',
            't' => 'info',
            'c' => 'notification',
            'ai' => (string) $userId,
            'ar' => 'User',
            'u' => $target['route'],
        ], JSON_UNESCAPED_SLASHES);

        $insert = $this->pdo->prepare(
            'INSERT INTO tblnotifications
                (ltitle, lmessage, ldatetime, lstatus, lmain_id, linv_session, lout_status, ltype, luserid, lrefno)
             SELECT :title, :message, NOW(), 1, :main_id, :metadata, \'0\', \'Notification\', :recipient_id, :refno
             WHERE NOT EXISTS (
                 SELECT 1 FROM tblnotifications
                 WHERE luserid = :existing_recipient_id
                   AND lrefno = :existing_refno
                   AND (lstatus IS NULL OR lstatus != -1)
             )'
        );
        $customerName = $target['customer_name'];
        $title = $page . ' ' . $action;
        $message = ($actorName !== '' ? $actorName : 'A user') . ' ' . strtolower($action) . ' ' . $page . '.';
        if ($target['entity_type'] === 'prospect' && $customerName !== '') {
            $title .= ' - ' . $customerName;
            $message = sprintf(
                '%s %s customer %s.',
                $actorName !== '' ? $actorName : 'A user',
                strtolower($action),
                $customerName
            );
        }

        $insert->execute([
            'title' => $title,
            'message' => $message,
            'main_id' => (string) $mainId,
            'metadata' => is_string($metadata) ? $metadata : null,
            'recipient_id' => (string) $mainId,
            'refno' => $notificationRefno,
            'existing_recipient_id' => (string) $mainId,
            'existing_refno' => $notificationRefno,
        ]);
    }

    /** @return array{entity_type:string,route:string,record_id:string,customer_name:string} */
    private function resolveNotificationTarget(int $mainId, string $page, string $refno): array
    {
        $recordId = trim($refno);
        [$entityType, $route] = match (strtolower(trim($page))) {
            'sales inquiry' => ['sales_inquiry', 'sales-transaction-sales-inquiry'],
            'sales order' => ['sales_order', 'sales-transaction-sales-order'],
            'order slip' => ['order_slip', 'sales-transaction-order-slip'],
            'invoice' => ['invoice', 'sales-transaction-invoice'],
            'purchase request' => ['purchase_request', 'warehouse-purchasing-purchase-request'],
            'purchase order' => ['purchase_order', 'warehouse-purchasing-purchase-order'],
            'receiving report' => ['receiving_report', 'warehouse-purchasing-receiving-stock'],
            'daily collection entry' => ['daily_collection', 'accounting-transactions-daily-collection-entry'],
            'stock adjustment' => ['stock_adjustment', 'warehouse-inventory-stock-adjustment'],
            'transfer stock' => ['transfer_stock', 'warehouse-inventory-stock-movement'],
            'inventory audit' => ['inventory_audit', 'warehouse-reports-inventory-audit-report'],
            'customer database', 'daily call monitoring dashboard' => ['prospect', 'maintenance-customer-customer-data'],
            'agent sales report' => ['agent_sales_report', 'maintenance-customer-customer-data'],
            default => [strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $page) ?? 'activity'), '_'), 'home'],
        };

        return [
            'entity_type' => $entityType,
            'route' => $route,
            'record_id' => $recordId,
            'customer_name' => $entityType === 'prospect' ? $this->customerName($mainId, $recordId) : '',
        ];
    }

    private function customerName(int $mainId, string $recordId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT TRIM(COALESCE(lcompany, \'\'))
             FROM tblpatient
             WHERE lmain_id = :main_id AND lsessionid = :record_id
             LIMIT 1'
        );
        $stmt->execute(['main_id' => $mainId, 'record_id' => $recordId]);

        return trim((string) ($stmt->fetchColumn() ?: ''));
    }

    private function actorName(int $userId): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT TRIM(CONCAT(COALESCE(lfname, ''), ' ', COALESCE(llname, ''))) FROM tblaccount WHERE lid = :user_id LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    }
}
