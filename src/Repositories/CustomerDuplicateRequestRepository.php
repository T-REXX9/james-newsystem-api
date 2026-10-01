<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use DateTime;
use DateInterval;

final class CustomerDuplicateRequestRepository
{
    private const BATCH_WINDOW_MINUTES = 5;
    private const MAX_BATCH_NOTIFICATIONS = 10;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Create a new duplicate-prospect approval request
     */
    public function create(
        int $mainId,
        string $sessionId,
        int $existingProspectId,
        int $newProspectId,
        string $companyName,
        string $contactPerson,
        string $phone,
        array $matchingFields,
        int $submittedBy,
        string $submittedByName
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblpatient_duplicate_request
             (lmain_id, lsessionid, lexisting_prospect_id, lnew_prospect_id, lcompany_name,
              lcontact_person, lphone, lmatching_fields, lsubmitted_by, lsubmitted_by_name, lstatus)
             VALUES (:main_id, :session_id, :existing_id, :new_id, :company_name,
                     :contact_person, :phone, :matching_fields, :submitted_by, :submitted_by_name, :status)'
        );

        $stmt->execute([
            'main_id' => $mainId,
            'session_id' => $sessionId,
            'existing_id' => $existingProspectId,
            'new_id' => $newProspectId,
            'company_name' => $companyName,
            'contact_person' => $contactPerson,
            'phone' => $phone,
            'matching_fields' => json_encode($matchingFields),
            'submitted_by' => $submittedBy,
            'submitted_by_name' => $submittedByName,
            'status' => 'pending',
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Get pending duplicate requests for a master user
     */
    public function getPendingDuplicates(int $masterUserId, int $limit = 50, ?int $mainId = null): array
    {
        $scope = $mainId === null ? '' : ' AND lmain_id = :main_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM tblpatient_duplicate_request
             WHERE lstatus = :status{$scope}
             AND (lsnoozed_until IS NULL OR lsnoozed_until < NOW())
             ORDER BY lcreated_at ASC
             LIMIT :limit"
        );

        $stmt->bindValue(':status', 'pending', PDO::PARAM_STR);
        if ($mainId !== null) {
            $stmt->bindValue(':main_id', $mainId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', min(500, max(1, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->normalizeDuplicateRequest($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get count of pending duplicates (considering snoozed items)
     */
    public function getPendingCount(?int $mainId = null): int
    {
        $scope = $mainId === null ? '' : ' AND lmain_id = :main_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM tblpatient_duplicate_request
             WHERE lstatus = :status{$scope}
             AND (lsnoozed_until IS NULL OR lsnoozed_until < NOW())"
        );

        $params = ['status' => 'pending'];
        if ($mainId !== null) {
            $params['main_id'] = $mainId;
        }
        $stmt->execute($params);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * Approve a duplicate request (merge the records)
     */
    public function approve(int $requestId, int $approvedBy, string $approvedByName): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tblpatient_duplicate_request
             SET lstatus = :status, lapproved_by = :approved_by, lapproved_by_name = :approved_by_name,
                 lapproved_at = NOW()
             WHERE lid = :id AND lstatus = :current_status'
        );

        $stmt->execute([
            'status' => 'approved',
            'approved_by' => $approvedBy,
            'approved_by_name' => $approvedByName,
            'id' => $requestId,
            'current_status' => 'pending',
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Reject a duplicate request (mark as rejected)
     */
    public function reject(int $requestId, int $rejectedBy, string $rejectedByName, ?int $mainId = null): bool
    {
        $scope = $mainId === null ? '' : ' AND lmain_id = :main_id';
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tblpatient_duplicate_request
             SET lstatus = :status, lapproved_by = :rejected_by, lapproved_by_name = :rejected_by_name,
                 lapproved_at = NOW()
             WHERE lid = :id AND lstatus = :current_status{$scope}"
        );

        $params = [
            'status' => 'rejected',
            'rejected_by' => $rejectedBy,
            'rejected_by_name' => $rejectedByName,
            'id' => $requestId,
            'current_status' => 'pending',
        ];
        if ($mainId !== null) {
            $params['main_id'] = $mainId;
        }
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Snooze a duplicate request (defer notification)
     */
    public function snooze(int $requestId, int $hours = 24, ?int $mainId = null): bool
    {
        $snoozeUntil = (new DateTime())->add(new DateInterval('PT' . $hours . 'H'))->format('Y-m-d H:i:s');

        $scope = $mainId === null ? '' : ' AND lmain_id = :main_id';
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tblpatient_duplicate_request
             SET lsnoozed_until = :snooze_until
             WHERE lid = :id AND lstatus = :status{$scope}"
        );

        $params = [
            'snooze_until' => $snoozeUntil,
            'id' => $requestId,
            'status' => 'pending',
        ];
        if ($mainId !== null) {
            $params['main_id'] = $mainId;
        }
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get a single duplicate request by ID
     */
    public function getById(int $requestId, ?int $mainId = null): ?array
    {
        $scope = $mainId === null ? '' : ' AND lmain_id = :main_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM tblpatient_duplicate_request WHERE lid = :id{$scope} LIMIT 1"
        );

        $params = ['id' => $requestId];
        if ($mainId !== null) {
            $params['main_id'] = $mainId;
        }
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeDuplicateRequest($row) : null;
    }

    public function markMerged(int $requestId, int $mainId, int $approvedBy, string $approvedByName, int $mergeId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tblpatient_duplicate_request
             SET lstatus = 'merged', lapproved_by = :approved_by, lapproved_by_name = :approved_by_name,
                 lapproved_at = NOW(), lmerge_id = :merge_id
             WHERE lid = :id AND lmain_id = :main_id AND lstatus = 'pending'"
        );
        $stmt->execute([
            'approved_by' => $approvedBy,
            'approved_by_name' => $approvedByName,
            'merge_id' => $mergeId,
            'id' => $requestId,
            'main_id' => $mainId,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Get pending count for notification batching
     */
    public function getPendingCountForNotification(int $masterUserId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM tblpatient_duplicate_request
             WHERE lstatus = :status
             AND (lsnoozed_until IS NULL OR lsnoozed_until < NOW())'
        );

        $stmt->execute(['status' => 'pending']);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * Check if notification batch window is active for a master user
     */
    public function shouldBatchNotification(int $masterUserId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM tblduplicates_notification_batch
             WHERE lmaster_user_id = :master_user_id
             AND lbatch_window_expires_at > NOW()'
        );

        $stmt->execute(['master_user_id' => $masterUserId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Update notification batch for a master user
     */
    public function updateNotificationBatch(int $masterUserId, int $pendingCount): void
    {
        $batchExpires = (new DateTime())->add(new DateInterval('PT' . self::BATCH_WINDOW_MINUTES . 'M'))->format('Y-m-d H:i:s');

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblduplicates_notification_batch
             (lmaster_user_id, lpending_count, lbatch_window_expires_at)
             VALUES (:master_user_id, :pending_count, :batch_expires)
             ON DUPLICATE KEY UPDATE
             lpending_count = lpending_count + VALUES(lpending_count),
             lbatch_window_expires_at = IF(lbatch_window_expires_at > NOW(), lbatch_window_expires_at, VALUES(lbatch_window_expires_at))'
        );

        $stmt->execute([
            'master_user_id' => $masterUserId,
            'pending_count' => $pendingCount,
            'batch_expires' => $batchExpires,
        ]);
    }

    /**
     * Get batch count for a master user
     */
    public function getBatchCount(int $masterUserId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(lpending_count, 0) FROM tblduplicates_notification_batch
             WHERE lmaster_user_id = :master_user_id
             AND lbatch_window_expires_at > NOW()'
        );

        $stmt->execute(['master_user_id' => $masterUserId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * Clear batch notification for a master user
     */
    public function clearBatch(int $masterUserId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM tblduplicates_notification_batch WHERE lmaster_user_id = :master_user_id'
        );

        $stmt->execute(['master_user_id' => $masterUserId]);
    }

    /**
     * Normalize duplicate request row data
     */
    private function normalizeDuplicateRequest(array $row): array
    {
        return [
            'id' => (int) $row['lid'],
            'mainId' => (int) $row['lmain_id'],
            'sessionId' => (string) $row['lsessionid'],
            'existingProspectId' => (int) $row['lexisting_prospect_id'],
            'newProspectId' => (int) $row['lnew_prospect_id'],
            'companyName' => (string) $row['lcompany_name'],
            'contactPerson' => (string) $row['lcontact_person'],
            'phone' => (string) $row['lphone'],
            'matchingFields' => json_decode($row['lmatching_fields'] ?? '[]', true),
            'status' => (string) $row['lstatus'],
            'submittedBy' => (int) $row['lsubmitted_by'],
            'submittedByName' => (string) $row['lsubmitted_by_name'],
            'approvedBy' => $row['lapproved_by'] ? (int) $row['lapproved_by'] : null,
            'approvedByName' => $row['lapproved_by_name'] ? (string) $row['lapproved_by_name'] : null,
            'approvedAt' => $row['lapproved_at'],
            'snoozedUntil' => $row['lsnoozed_until'],
            'createdAt' => (string) $row['lcreated_at'],
            'updatedAt' => (string) $row['lupdated_at'],
            'mergeId' => isset($row['lmerge_id']) && $row['lmerge_id'] !== null ? (int) $row['lmerge_id'] : null,
        ];
    }
}
