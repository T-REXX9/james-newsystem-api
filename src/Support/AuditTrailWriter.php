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
        } catch (Throwable) {
            // Audit writes should not block the primary workflow.
        }
    }
}
