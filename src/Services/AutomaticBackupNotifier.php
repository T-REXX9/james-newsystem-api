<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Repositories\NotificationsRepository;
use PDO;

final class AutomaticBackupNotifier
{
    public function __construct(private readonly Database $db)
    {
    }

    public function notifyMasters(string $title, string $message): void
    {
        $ids = $this->masterUserIds();
        if ($ids === []) {
            return;
        }

        $notifications = new NotificationsRepository($this->db);
        $stamp = date('YmdHi');
        foreach ($ids as $userId) {
            try {
                $notifications->create([
                    'recipient_id' => (string) $userId,
                    'title' => $title,
                    'message' => $message,
                    'type' => 'warning',
                    'category' => 'alert',
                    'main_id' => (string) $userId,
                    'action_url' => 'maintenance-profile-server-maintenance',
                    'metadata' => [
                        'entity_type' => 'automatic_backup',
                        'entity_id' => $stamp,
                        'action' => 'backup_failed',
                        'status' => 'unread',
                        'action_url' => 'maintenance-profile-server-maintenance',
                        'refno' => 'automatic-backup:' . $stamp,
                        'idempotency_key' => 'automatic-backup:' . $stamp . ':' . $userId,
                        'category' => 'alert',
                        'actor_role' => 'System',
                    ],
                ]);
            } catch (\Throwable $error) {
                error_log('Automatic Backup master notification failed: ' . $error->getMessage());
            }
        }
    }

    /**
     * @return list<int>
     */
    private function masterUserIds(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT lid FROM tblaccount WHERE CAST(COALESCE(ltype, 0) AS SIGNED) = 1 AND CAST(COALESCE(lstatus, 0) AS SIGNED) = 1'
        );
        if ($stmt === false) {
            return [];
        }

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) ($row['lid'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
