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

    public function write(int $mainId, int $userId, string $page, string $action, string $refno): void
    {
        if ($mainId <= 0 || $userId <= 0) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO tblaudit_trail (lmain_id, luser_id, lpage, laction, lrefno, ldatetime)
                 VALUES (:main_id, :user_id, :page, :action, :refno, NOW())'
            );
            $stmt->execute([
                'main_id' => $mainId,
                'user_id' => $userId,
                'page' => trim($page),
                'action' => trim($action),
                'refno' => trim($refno),
            ]);
        } catch (Throwable) {
            // Audit writes should not block the primary workflow.
        }
    }
}
