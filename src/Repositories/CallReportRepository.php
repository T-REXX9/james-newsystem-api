<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\Exceptions\HttpException;
use PDO;

final class CallReportRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function createThreadFromCallLog(
        int $mainId,
        array $callLog,
        int $agentUserId,
        string $agentName,
        string $reportBody,
        string $outcome,
        bool $notifyMaster = true
    ): array {
        $contactId = trim((string) ($callLog['contact_id'] ?? ''));
        $callLogEntryId = (int) preg_replace('/^legacy_/', '', (string) ($callLog['id'] ?? '0'));
        $callLogRefno = trim((string) ($callLog['refno'] ?? ''));

        if ($contactId === '' || $callLogEntryId <= 0) {
            throw new HttpException(500, 'Call log entry could not be linked to a report thread.');
        }

        if ($callLogRefno === '') {
            $refStmt = $this->db->pdo()->prepare(
                'SELECT cl.lrefno
                 FROM tblcall_logs_entry cle
                 INNER JOIN tblcall_logs cl ON cl.lrefno = cle.lrefno
                 WHERE cle.lid = :entry_id AND CAST(cl.lmain_id AS CHAR) = :main_id
                 LIMIT 1'
            );
            $refStmt->execute(['entry_id' => $callLogEntryId, 'main_id' => (string) $mainId]);
            $callLogRefno = trim((string) $refStmt->fetchColumn());
        }

        $pdo = $this->db->pdo();
        $existing = $pdo->prepare('SELECT id FROM call_report_threads WHERE call_log_entry_id = :entry_id LIMIT 1');
        $existing->execute(['entry_id' => $callLogEntryId]);
        $existingId = (int) $existing->fetchColumn();
        if ($existingId > 0) {
            return $this->getThreadById($mainId, $existingId, $agentUserId) ?? [];
        }

        $reportSubmittedAt = trim((string) ($callLog['occurred_at'] ?? '')) ?: date('Y-m-d H:i:s');
        $reportedDurationSeconds = max(0, (int) ($callLog['duration_seconds'] ?? 0));
        $callDetails = $this->resolveCallDetails(
            $mainId,
            $contactId,
            $agentUserId,
            $reportSubmittedAt,
            $reportedDurationSeconds
        );

        $insert = $this->db->pdo()->prepare(
            'INSERT INTO call_report_threads
             (main_id, contact_id, call_log_entry_id, call_log_refno, agent_user_id, agent_name, outcome, report_body,
              call_started_at, call_ended_at, duration_seconds, created_at)
             VALUES (:main_id, :contact_id, :call_log_entry_id, :call_log_refno, :agent_user_id, :agent_name, :outcome, :report_body,
                     :call_started_at, :call_ended_at, :duration_seconds, :created_at)'
        );
        $insert->execute([
            'main_id' => $mainId,
            'contact_id' => $contactId,
            'call_log_entry_id' => $callLogEntryId,
            'call_log_refno' => $callLogRefno !== '' ? $callLogRefno : ('entry-' . $callLogEntryId),
            'agent_user_id' => $agentUserId,
            'agent_name' => $agentName !== '' ? $agentName : ('User ' . $agentUserId),
            'outcome' => $outcome !== '' ? $outcome : 'note',
            'report_body' => $reportBody,
            'call_started_at' => $callDetails['call_started_at'],
            'call_ended_at' => $callDetails['call_ended_at'],
            'duration_seconds' => $callDetails['duration_seconds'],
            'created_at' => $reportSubmittedAt,
        ]);

        $threadId = (int) $pdo->lastInsertId();
        $thread = $this->getThreadById($mainId, $threadId, $agentUserId);
        if ($thread === null) {
            throw new HttpException(500, 'Report thread could not be loaded after creation.');
        }

        if ($notifyMaster) {
            $this->notifyMasterOnReport($mainId, $thread);
        }

        return $thread;
    }

    public function getThreadsByContact(int $mainId, string $contactId, int $viewerUserId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
             FROM call_report_threads
             WHERE main_id = :main_id AND contact_id = :contact_id
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['main_id' => $mainId, 'contact_id' => $contactId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_values(array_filter(array_map(
            fn(array $row): ?array => $this->mapThreadRow($row, $viewerUserId),
            $rows
        )));
    }

    public function getThreadById(int $mainId, int $threadId, int $viewerUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM call_report_threads WHERE id = :id AND main_id = :main_id LIMIT 1'
        );
        $stmt->execute(['id' => $threadId, 'main_id' => $mainId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return $this->mapThreadRow($row, $viewerUserId);
    }

    public function addReply(
        int $mainId,
        int $threadId,
        int $senderUserId,
        string $senderName,
        string $senderRole,
        string $body
    ): array {
        $thread = $this->getThreadById($mainId, $threadId, $senderUserId);
        if ($thread === null) {
            throw new HttpException(404, 'Report thread was not found.');
        }

        if ($senderRole !== 'master' && $senderRole !== 'agent') {
            throw new HttpException(422, 'sender_role must be agent or master');
        }

        if ($senderRole === 'master' && !$this->isMasterUser($senderUserId, $mainId)) {
            throw new HttpException(403, 'Only the Master User can reply to sales agent reports.');
        }

        if ($senderRole === 'agent' && (int) ($thread['agent_user_id'] ?? 0) !== $senderUserId) {
            throw new HttpException(403, 'You can only follow up on your own call reports.');
        }

        $trimmedBody = trim($body);
        if ($trimmedBody === '') {
            throw new HttpException(422, 'Reply message is required.');
        }

        $insert = $this->db->pdo()->prepare(
            'INSERT INTO call_report_messages
             (thread_id, sender_user_id, sender_name, sender_role, body)
             VALUES (:thread_id, :sender_user_id, :sender_name, :sender_role, :body)'
        );
        $insert->execute([
            'thread_id' => $threadId,
            'sender_user_id' => $senderUserId,
            'sender_name' => $senderName !== '' ? $senderName : ('User ' . $senderUserId),
            'sender_role' => $senderRole,
            'body' => $trimmedBody,
        ]);

        $messageId = (int) $this->db->pdo()->lastInsertId();
        $message = $this->getMessageById($messageId, $senderUserId);
        if ($message === null) {
            throw new HttpException(500, 'Reply could not be loaded after saving.');
        }

        if ($senderRole === 'master') {
            $this->notifyAgentOnReply($mainId, $thread, $message, $senderName);
        }

        return $message;
    }

    public function markThreadRead(int $mainId, int $threadId, int $userId): bool
    {
        $thread = $this->getThreadById($mainId, $threadId, $userId);
        if ($thread === null) {
            throw new HttpException(404, 'Report thread was not found.');
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO call_report_read_states (thread_id, user_id, last_read_at)
             VALUES (:thread_id, :user_id, NOW())
             ON DUPLICATE KEY UPDATE last_read_at = NOW()'
        );
        $stmt->execute(['thread_id' => $threadId, 'user_id' => $userId]);

        return true;
    }

    public function backfillThreadsFromCallLogs(int $mainId, string $contactId, int $viewerUserId): void
    {
        $sql = <<<SQL
SELECT
    cle.lid AS call_log_entry_id,
    cl.lrefno AS call_log_refno,
    CAST(cle.lcustomer_id AS CHAR) AS contact_id,
    CAST(cl.lsalesman_id AS SIGNED) AS agent_user_id,
    COALESCE(
        NULLIF(TRIM(CONCAT(COALESCE(ua.lfname, ''), ' ', COALESCE(ua.llname, ''))), ''),
        CONCAT('User ', cl.lsalesman_id)
    ) AS agent_name,
    COALESCE(NULLIF(cle.lstatus, ''), NULLIF(cle.lremarks, ''), 'note') AS outcome,
    TRIM(REPLACE(cle.lnotes, '[Sales Agent Report]', '')) AS report_body,
    CONCAT(cl.lcall_date, ' 00:00:00') AS created_at
FROM tblcall_logs_entry cle
INNER JOIN tblcall_logs cl ON cl.lrefno = cle.lrefno
LEFT JOIN tblaccount ua ON ua.lid = cl.lsalesman_id
WHERE CAST(cl.lmain_id AS CHAR) = :main_id
  AND CAST(cle.lcustomer_id AS CHAR) = :contact_id
  AND cle.lnotes LIKE '[Sales Agent Report]%'
  AND NOT EXISTS (
      SELECT 1 FROM call_report_threads crt WHERE crt.call_log_entry_id = cle.lid
  )
ORDER BY cl.lcall_date DESC, cle.lid DESC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['main_id' => (string) $mainId, 'contact_id' => $contactId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $agentUserId = (int) ($row['agent_user_id'] ?? 0);
            $createdAt = (string) ($row['created_at'] ?? date('Y-m-d H:i:s'));
            $callDetails = $this->resolveCallDetails(
                $mainId,
                $contactId,
                $agentUserId,
                $createdAt,
                0
            );
            $insert = $this->db->pdo()->prepare(
                'INSERT INTO call_report_threads
                 (main_id, contact_id, call_log_entry_id, call_log_refno, agent_user_id, agent_name, outcome, report_body,
                  call_started_at, call_ended_at, duration_seconds, created_at)
                 VALUES (:main_id, :contact_id, :call_log_entry_id, :call_log_refno, :agent_user_id, :agent_name, :outcome, :report_body,
                         :call_started_at, :call_ended_at, :duration_seconds, :created_at)'
            );
            $insert->execute([
                'main_id' => $mainId,
                'contact_id' => (string) ($row['contact_id'] ?? $contactId),
                'call_log_entry_id' => (int) ($row['call_log_entry_id'] ?? 0),
                'call_log_refno' => (string) ($row['call_log_refno'] ?? ''),
                'agent_user_id' => $agentUserId,
                'agent_name' => trim((string) ($row['agent_name'] ?? 'Sales Agent')) ?: 'Sales Agent',
                'outcome' => trim((string) ($row['outcome'] ?? 'note')) ?: 'note',
                'report_body' => trim((string) ($row['report_body'] ?? '')),
                'call_started_at' => $callDetails['call_started_at'],
                'call_ended_at' => $callDetails['call_ended_at'],
                'duration_seconds' => $callDetails['duration_seconds'],
                'created_at' => $createdAt,
            ]);
        }
    }

    private function mapThreadRow(array $row, int $viewerUserId): ?array
    {
        $threadId = (int) ($row['id'] ?? 0);
        if ($threadId <= 0) {
            return null;
        }

        $messages = $this->getMessagesForThread($threadId, $viewerUserId);
        $lastActivityAt = (string) ($row['created_at'] ?? '');
        if (count($messages) > 0) {
            $lastActivityAt = (string) ($messages[count($messages) - 1]['created_at'] ?? $lastActivityAt);
        }

        $readAt = $this->getLastReadAt($threadId, $viewerUserId);
        $unreadCount = 0;
        foreach ($messages as $message) {
            if ((bool) ($message['is_from_current_user'] ?? false)) {
                continue;
            }
            if ($readAt === null || strcmp((string) $message['created_at'], $readAt) > 0) {
                $unreadCount++;
            }
        }

        return [
            'id' => (string) $threadId,
            'contact_id' => (string) ($row['contact_id'] ?? ''),
            'call_log_entry_id' => (string) ($row['call_log_entry_id'] ?? ''),
            'call_log_refno' => (string) ($row['call_log_refno'] ?? ''),
            'agent_user_id' => (string) ($row['agent_user_id'] ?? ''),
            'agent_name' => (string) ($row['agent_name'] ?? ''),
            'outcome' => (string) ($row['outcome'] ?? 'note'),
            'report_body' => (string) ($row['report_body'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'call_started_at' => (string) ($row['call_started_at'] ?? ''),
            'call_ended_at' => (string) ($row['call_ended_at'] ?? ''),
            'duration_seconds' => (int) ($row['duration_seconds'] ?? 0),
            'last_activity_at' => $lastActivityAt,
            'unread_count' => $unreadCount,
            'messages' => $messages,
        ];
    }

    private function getMessagesForThread(int $threadId, int $viewerUserId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM call_report_messages WHERE thread_id = :thread_id ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['thread_id' => $threadId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_values(array_map(
            fn(array $row): array => $this->mapMessageRow($row, $viewerUserId),
            $rows
        ));
    }

    private function getMessageById(int $messageId, int $viewerUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM call_report_messages WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $messageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return $this->mapMessageRow($row, $viewerUserId);
    }

    private function mapMessageRow(array $row, int $viewerUserId): array
    {
        $senderUserId = (int) ($row['sender_user_id'] ?? 0);
        $senderRole = (string) ($row['sender_role'] ?? 'agent');

        return [
            'id' => (string) ($row['id'] ?? ''),
            'thread_id' => (string) ($row['thread_id'] ?? ''),
            'sender_user_id' => (string) $senderUserId,
            'sender_name' => (string) ($row['sender_name'] ?? ''),
            'sender_role' => $senderRole,
            'body' => (string) ($row['body'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'is_from_current_user' => $senderUserId === $viewerUserId,
            'is_from_master' => $senderRole === 'master',
        ];
    }

    private function getLastReadAt(int $threadId, int $userId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT last_read_at FROM call_report_read_states WHERE thread_id = :thread_id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['thread_id' => $threadId, 'user_id' => $userId]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null && trim((string) $value) !== ''
            ? (string) $value
            : null;
    }

    private function isMasterUser(int $userId, int $mainId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT CAST(COALESCE(ltype, 0) AS SIGNED) AS user_type
             FROM tblaccount
             WHERE lid = :user_id AND (lid = :main_id OR lmother_id = :main_id_2)
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'main_id' => $mainId, 'main_id_2' => $mainId]);
        $userType = (int) $stmt->fetchColumn();

        return $userType === 1;
    }

    /**
     * @return array{call_started_at: ?string, call_ended_at: string, duration_seconds: int}
     */
    private function resolveCallDetails(
        int $mainId,
        string $contactId,
        int $agentUserId,
        string $reportSubmittedAt,
        int $reportedDurationSeconds = 0
    ): array {
        $endedAt = trim($reportSubmittedAt) !== '' ? $reportSubmittedAt : date('Y-m-d H:i:s');
        $endedTimestamp = strtotime($endedAt) ?: time();

        $hardwareDuration = 0;
        $hardwareStartedAt = null;
        $hardwareStmt = $this->db->pdo()->prepare(
            'SELECT nc.lcall_timestamp, nc.lduration_seconds
             FROM tblcall_logs_v2 nc
             WHERE CAST(nc.lcustomer_id AS CHAR) = :contact_id
               AND nc.lagent_id = :agent_id
               AND nc.lcall_timestamp <= :ended_at
             ORDER BY nc.lcall_timestamp DESC
             LIMIT 1'
        );
        $hardwareStmt->execute([
            'contact_id' => $contactId,
            'agent_id' => $agentUserId,
            'ended_at' => date('Y-m-d H:i:s', $endedTimestamp),
        ]);
        $hardwareRow = $hardwareStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($hardwareRow !== null) {
            $hardwareDuration = max(0, (int) ($hardwareRow['lduration_seconds'] ?? 0));
            $hardwareTimestamp = trim((string) ($hardwareRow['lcall_timestamp'] ?? ''));
            if ($hardwareTimestamp !== '') {
                $hardwareStartedAt = $hardwareTimestamp;
            }
        }

        $claimStartedAt = null;
        $claimStmt = $this->db->pdo()->prepare(
            "SELECT claimed_at FROM daily_call_claims
             WHERE main_id = :main_id AND contact_id = :contact_id AND agent_user_id = :agent_user_id
               AND claim_date = DATE(:report_date)
             ORDER BY claimed_at DESC
             LIMIT 1"
        );
        $claimStmt->execute([
            'main_id' => $mainId,
            'contact_id' => $contactId,
            'agent_user_id' => $agentUserId,
            'report_date' => date('Y-m-d', $endedTimestamp),
        ]);
        $claimStartedAtValue = trim((string) $claimStmt->fetchColumn());
        if ($claimStartedAtValue !== '') {
            $claimStartedAt = $claimStartedAtValue;
        }

        $durationSeconds = max($reportedDurationSeconds, $hardwareDuration);
        $startedAt = $hardwareStartedAt ?: $claimStartedAt;

        if ($startedAt === null && $durationSeconds > 0) {
            $startedAt = date('Y-m-d H:i:s', $endedTimestamp - $durationSeconds);
        }

        if ($durationSeconds <= 0 && $startedAt !== null) {
            $startedTimestamp = strtotime($startedAt);
            if ($startedTimestamp !== false) {
                $durationSeconds = max(0, $endedTimestamp - $startedTimestamp);
            }
        }

        return [
            'call_started_at' => $startedAt,
            'call_ended_at' => date('Y-m-d H:i:s', $endedTimestamp),
            'duration_seconds' => $durationSeconds,
        ];
    }

    private function notifyMasterOnReport(int $mainId, array $thread): void
    {
        try {
            $notifications = new NotificationsRepository($this->db);
            $customerName = $this->getCustomerName($mainId, (string) ($thread['contact_id'] ?? ''));
            $callDetailsLine = $this->formatCallDetailsLine($thread);
            $snippet = $this->messageSnippet((string) ($thread['report_body'] ?? ''));
            $threadId = (string) ($thread['id'] ?? '');

            $message = sprintf(
                '%s submitted a call report for %s.',
                (string) ($thread['agent_name'] ?? 'A sales agent'),
                $customerName
            );
            if ($callDetailsLine !== '') {
                $message .= ' ' . $callDetailsLine;
            }
            $message .= ' Report: ' . $snippet;

            $notifications->create([
                'recipient_id' => (string) $mainId,
                'title' => 'New sales agent call report',
                'message' => $message,
                'type' => 'info',
                'category' => 'notification',
                'main_id' => (string) $mainId,
                'action_url' => 'sales-transaction-daily-call-monitoring',
                'metadata' => [
                    'entity_type' => 'call_report',
                    'entity_id' => $threadId,
                    'contact_id' => (string) ($thread['contact_id'] ?? ''),
                    'action' => 'report_submitted',
                    'status' => 'unread',
                    'action_url' => 'sales-transaction-daily-call-monitoring',
                    'refno' => 'call-report-thread:' . $threadId,
                    'idempotency_key' => 'call-report-thread:' . $threadId . ':master',
                    'category' => 'notification',
                    'actor_id' => (string) ($thread['agent_user_id'] ?? ''),
                    'actor_role' => 'Sales Agent',
                ],
            ]);
        } catch (\Throwable $error) {
            error_log('Call report master notification failed: ' . $error->getMessage());
        }
    }

    private function notifyAgentOnReply(int $mainId, array $thread, array $message, string $senderName): void
    {
        try {
            $agentUserId = trim((string) ($thread['agent_user_id'] ?? ''));
            if ($agentUserId === '') {
                return;
            }

            $notifications = new NotificationsRepository($this->db);
            $customerName = $this->getCustomerName($mainId, (string) ($thread['contact_id'] ?? ''));
            $callDetailsLine = $this->formatCallDetailsLine($thread);
            $snippet = $this->messageSnippet((string) ($message['body'] ?? ''));
            $threadId = (string) ($thread['id'] ?? '');
            $messageId = (string) ($message['id'] ?? '');

            $notificationMessage = sprintf(
                '%s replied to your call report for %s.',
                $senderName !== '' ? $senderName : 'Master User',
                $customerName
            );
            if ($callDetailsLine !== '') {
                $notificationMessage .= ' ' . $callDetailsLine;
            }
            $notificationMessage .= ' Reply: ' . $snippet;

            $notifications->create([
                'recipient_id' => $agentUserId,
                'title' => 'Master User replied to your call report',
                'message' => $notificationMessage,
                'type' => 'info',
                'category' => 'notification',
                'main_id' => (string) $mainId,
                'action_url' => 'sales-transaction-daily-call-monitoring',
                'metadata' => [
                    'entity_type' => 'call_report_reply',
                    'entity_id' => $messageId,
                    'contact_id' => (string) ($thread['contact_id'] ?? ''),
                    'thread_id' => $threadId,
                    'action' => 'reply_received',
                    'status' => 'unread',
                    'action_url' => 'sales-transaction-daily-call-monitoring',
                    'refno' => 'call-report-reply:' . $messageId,
                    'idempotency_key' => 'call-report-reply:' . $messageId . ':' . $agentUserId,
                    'category' => 'notification',
                    'actor_role' => 'Master User',
                ],
            ]);
        } catch (\Throwable $error) {
            error_log('Call report agent notification failed: ' . $error->getMessage());
        }
    }

    private function getCustomerName(int $mainId, string $contactId): string
    {
        if ($contactId === '') {
            return 'a customer';
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(NULLIF(TRIM(lcompany), \'\'), NULLIF(TRIM(lname), \'\'), \'Unnamed customer\') AS customer_name
             FROM tblpatient
             WHERE CAST(lid AS CHAR) = :contact_id
               AND (CAST(lmain_id AS CHAR) = :main_id OR lmain_id IS NULL)
             LIMIT 1'
        );
        $stmt->execute(['contact_id' => $contactId, 'main_id' => (string) $mainId]);
        $name = trim((string) $stmt->fetchColumn());

        return $name !== '' ? $name : 'a customer';
    }

    private function messageSnippet(string $body, int $maxLength = 120): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $body) ?? '');
        if ($normalized === '') {
            return 'No report details were provided.';
        }

        if (strlen($normalized) <= $maxLength) {
            return $normalized;
        }

        return substr($normalized, 0, $maxLength - 3) . '...';
    }

    private function formatDurationLabel(int $durationSeconds): string
    {
        if ($durationSeconds <= 0) {
            return '';
        }

        $minutes = intdiv($durationSeconds, 60);
        $seconds = $durationSeconds % 60;
        if ($minutes > 0 && $seconds > 0) {
            return sprintf('%d min %d sec', $minutes, $seconds);
        }
        if ($minutes > 0) {
            return sprintf('%d min', $minutes);
        }

        return sprintf('%d sec', $seconds);
    }

    private function formatNotificationTimestamp(?string $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        $timestamp = strtotime($normalized);
        if ($timestamp === false) {
            return $normalized;
        }

        return date('M j, Y g:i A', $timestamp);
    }

    private function formatCallDetailsLine(array $thread): string
    {
        $startedLabel = $this->formatNotificationTimestamp((string) ($thread['call_started_at'] ?? ''));
        $endedLabel = $this->formatNotificationTimestamp((string) ($thread['call_ended_at'] ?? ''));
        $durationLabel = $this->formatDurationLabel((int) ($thread['duration_seconds'] ?? 0));

        $parts = [];
        if ($startedLabel !== '' && $endedLabel !== '') {
            $parts[] = sprintf('Call %s – %s', $startedLabel, $endedLabel);
        } elseif ($startedLabel !== '') {
            $parts[] = 'Call started ' . $startedLabel;
        } elseif ($endedLabel !== '') {
            $parts[] = 'Call ended ' . $endedLabel;
        }

        if ($durationLabel !== '') {
            $parts[] = 'Duration ' . $durationLabel;
        }

        return implode('. ', $parts);
    }
}
