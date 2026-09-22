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
                'refno' => trim($refno),
                'reason' => trim($reason) !== '' ? trim($reason) : null,
                'old_status' => trim($oldStatus) !== '' ? trim($oldStatus) : null,
                'new_status' => trim($newStatus) !== '' ? trim($newStatus) : null,
            ]);

            $this->writeNotification($mainId, $userId, $page, $action, $refno, $newStatus);
        } catch (Throwable) {
            // Audit writes should not block the primary workflow.
        }
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

        $entityType = $this->notificationEntityType($page);
        $notificationRefno = $entityType . ':' . $refno;
        $actorName = $this->actorName($userId);
        $metadata = json_encode([
            'e' => $entityType,
            'i' => $refno,
            'a' => strtolower(str_replace(' ', '_', $action)),
            's' => trim($newStatus) !== '' ? $newStatus : 'created',
            't' => 'info',
            'c' => 'notification',
            'ai' => (string) $userId,
            'ar' => 'User',
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
        $insert->execute([
            'title' => $page . ' ' . $action,
            'message' => ($actorName !== '' ? $actorName : 'A user') . ' ' . strtolower($action) . ' ' . $page . '.',
            'main_id' => (string) $mainId,
            'metadata' => is_string($metadata) ? $metadata : null,
            'recipient_id' => (string) $mainId,
            'refno' => $notificationRefno,
            'existing_recipient_id' => (string) $mainId,
            'existing_refno' => $notificationRefno,
        ]);
    }

    private function notificationEntityType(string $page): string
    {
        return match (strtolower(trim($page))) {
            'sales inquiry' => 'sales_inquiry',
            'sales order' => 'sales_order',
            'order slip' => 'order_slip',
            'daily collection entry' => 'daily_collection',
            'purchase request' => 'purchase_request',
            'purchase order' => 'purchase_order',
            'stock adjustment' => 'stock_adjustment',
            'transfer stock' => 'transfer_stock',
            default => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $page) ?? 'activity'), '_'),
        };
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
