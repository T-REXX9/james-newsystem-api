<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\Exceptions\HttpException;
use App\Support\SalesReportAttachmentStore;
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
        bool $notifyMaster = true,
        ?string $concern = null,
        ?string $action = null
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
              concern, `action`, call_started_at, call_ended_at, duration_seconds, created_at)
             VALUES (:main_id, :contact_id, :call_log_entry_id, :call_log_refno, :agent_user_id, :agent_name, :outcome, :report_body,
                     :concern, :action, :call_started_at, :call_ended_at, :duration_seconds, :created_at)'
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
            'concern' => $concern,
            'action' => $action,
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

        $threads = array_values(array_filter(array_map(
            fn(array $row): ?array => $this->mapThreadRow($row, $viewerUserId),
            $rows
        )));

        $prospectStmt = $this->db->pdo()->prepare(
            'SELECT CONCAT(\'prospect_\', p.lid) AS synthetic_id,
                    p.lid AS contact_id,
                    p.lencoded_by AS agent_user_id,
                    TRIM(CONCAT(COALESCE(a.lfname, \'\'), \' \', COALESCE(a.llname, \'\'))) AS agent_name,
                    p.lnotes AS report_body,
                    p.ldatetime AS created_at
             FROM tblpatient p
             INNER JOIN tblaccount a ON a.lid = p.lencoded_by
             WHERE p.lmain_id = :main_id
               AND (CAST(p.lid AS CHAR) = :contact_id_lid OR p.lsessionid = :contact_id_session)
               AND TRIM(COALESCE(p.lnotes, \'\')) <> \'\'
               AND (COALESCE(p.lstatus, 1) = 3 OR LOWER(COALESCE(p.lprofile_type, \'\')) LIKE \'%prospect%\')
             LIMIT 1'
        );
        $prospectStmt->execute([
            'main_id' => $mainId,
            'contact_id_lid' => $contactId,
            'contact_id_session' => $contactId,
        ]);
        $prospect = $prospectStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($prospect)) {
            $threads[] = [
                'id' => (string) ($prospect['synthetic_id'] ?? ''),
                'contact_id' => (string) ($prospect['contact_id'] ?? $contactId),
                'call_log_entry_id' => '',
                'call_log_refno' => '',
                'agent_user_id' => (string) ($prospect['agent_user_id'] ?? ''),
                'agent_name' => trim((string) ($prospect['agent_name'] ?? '')) ?: 'Sales Agent',
                'outcome' => 'note',
                'report_body' => (string) ($prospect['report_body'] ?? ''),
                'created_at' => (string) ($prospect['created_at'] ?? ''),
                'call_started_at' => '',
                'call_ended_at' => (string) ($prospect['created_at'] ?? ''),
                'duration_seconds' => 0,
                'last_activity_at' => (string) ($prospect['created_at'] ?? ''),
                'unread_count' => 0,
                'messages' => [],
                'replyable' => false,
            ];
        }

        usort($threads, static fn(array $left, array $right): int => strcmp(
            (string) ($right['created_at'] ?? ''),
            (string) ($left['created_at'] ?? '')
        ));
        return $threads;
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
        string $body,
        ?string $attachmentUrl = null,
        ?string $attachmentMime = null
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
            $assignedAgentId = $this->resolveAssignedAgentId($mainId, (string) ($thread['contact_id'] ?? ''));
            if ($assignedAgentId !== $senderUserId) {
                throw new HttpException(403, 'You can only follow up on your own call reports.');
            }
        }

        $trimmedBody = trim($body);
        $normalizedAttachmentUrl = trim((string) $attachmentUrl);
        $normalizedAttachmentMime = trim((string) $attachmentMime);
        if ($normalizedAttachmentUrl !== '') {
            $this->assertImageAttachment($normalizedAttachmentUrl, $normalizedAttachmentMime);
        }
        if ($trimmedBody === '' && $normalizedAttachmentUrl === '') {
            throw new HttpException(422, 'Reply message or picture attachment is required.');
        }

        $hasAttachmentColumns = $this->hasColumn('call_report_messages', 'attachment_url');
        if ($hasAttachmentColumns) {
            $insert = $this->db->pdo()->prepare(
                'INSERT INTO call_report_messages
                 (thread_id, sender_user_id, sender_name, sender_role, body, attachment_url, attachment_mime)
                 VALUES (:thread_id, :sender_user_id, :sender_name, :sender_role, :body, :attachment_url, :attachment_mime)'
            );
            $insert->execute([
                'thread_id' => $threadId,
                'sender_user_id' => $senderUserId,
                'sender_name' => $senderName !== '' ? $senderName : ('User ' . $senderUserId),
                'sender_role' => $senderRole,
                'body' => $trimmedBody !== '' ? $trimmedBody : ($normalizedAttachmentUrl !== '' ? '[Picture]' : ''),
                'attachment_url' => $normalizedAttachmentUrl !== '' ? $normalizedAttachmentUrl : null,
                'attachment_mime' => $normalizedAttachmentUrl !== '' ? ($normalizedAttachmentMime !== '' ? $normalizedAttachmentMime : 'image/jpeg') : null,
            ]);
        } else {
            if ($normalizedAttachmentUrl !== '') {
                throw new HttpException(503, 'Picture attachments are not available until the database migration is applied.');
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
        }

        $messageId = (int) $this->db->pdo()->lastInsertId();
        $message = $this->getMessageById($messageId, $senderUserId);
        if ($message === null) {
            throw new HttpException(500, 'Reply could not be loaded after saving.');
        }

        if ($senderRole === 'master') {
            $this->notifyAgentOnReply($mainId, $thread, $message, $senderName);
        } else {
            $this->notifyMasterOnAgentMessage($mainId, $thread, $message, $senderName);
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

    /**
     * One chronological Agent Sales Report conversation per customer/prospect.
     * Merges call-report threads, replies, and legacy management-instruction / staff-comment logs.
     *
     * @return array{contact_id: string, messages: list<array<string, mixed>>, unread_count: int}
     */
    public function getUnifiedConversation(int $mainId, string $contactId, int $viewerUserId): array
    {
        $this->backfillThreadsFromCallLogs($mainId, $contactId, $viewerUserId);
        $threads = $this->getThreadsByContact($mainId, $contactId, $viewerUserId);
        $messages = [];

        foreach ($threads as $thread) {
            $threadId = (string) ($thread['id'] ?? '');
            $isSynthetic = str_starts_with($threadId, 'prospect_') || (($thread['replyable'] ?? true) === false && ($thread['call_log_entry_id'] ?? '') === '');
            $reportBody = trim((string) ($thread['report_body'] ?? ''));
            if ($reportBody !== '') {
                $agentUserId = (string) ($thread['agent_user_id'] ?? '');
                $messages[] = [
                    'id' => 'report:' . $threadId,
                    'thread_id' => $threadId,
                    'contact_id' => $contactId,
                    'kind' => 'agent_report',
                    'sender_user_id' => $agentUserId,
                    'sender_name' => (string) ($thread['agent_name'] ?? 'Sales Agent'),
                    'sender_role' => 'agent',
                    'body' => $reportBody,
                    'attachment_url' => null,
                    'attachment_mime' => null,
                    'created_at' => (string) ($thread['created_at'] ?? ''),
                    'is_from_current_user' => $agentUserId !== '' && (int) $agentUserId === $viewerUserId,
                    'is_from_master' => false,
                    'outcome' => (string) ($thread['outcome'] ?? 'note'),
                    'call_started_at' => (string) ($thread['call_started_at'] ?? ''),
                    'call_ended_at' => (string) ($thread['call_ended_at'] ?? ''),
                    'duration_seconds' => (int) ($thread['duration_seconds'] ?? 0),
                    'replyable' => !$isSynthetic && (($thread['replyable'] ?? true) !== false),
                ];
            }

            foreach (($thread['messages'] ?? []) as $message) {
                $messages[] = array_merge($message, [
                    'contact_id' => $contactId,
                    'kind' => 'reply',
                    'attachment_url' => $message['attachment_url'] ?? null,
                    'attachment_mime' => $message['attachment_mime'] ?? null,
                ]);
            }
        }

        foreach ($this->fetchLegacyConversationLogs($mainId, $contactId) as $log) {
            $status = trim((string) ($log['status'] ?? ''));
            $kind = strcasecmp($status, 'Management Instruction') === 0
                ? 'management_instruction'
                : 'staff_comment';
            $authorId = (string) ($log['created_by'] ?? '');
            $isMasterAuthor = $kind === 'management_instruction' || $this->isMasterUser((int) $authorId, $mainId);
            $messages[] = [
                'id' => 'legacy:' . (string) ($log['id'] ?? ''),
                'thread_id' => '',
                'contact_id' => $contactId,
                'kind' => $kind,
                'sender_user_id' => $authorId,
                'sender_name' => (string) ($log['created_by_name'] ?? 'Staff'),
                'sender_role' => $isMasterAuthor ? 'master' : 'agent',
                'body' => trim((string) ($log['note'] ?? $log['comments'] ?? '')),
                'attachment_url' => $this->normalizeLegacyAttachmentUrl((string) ($log['attachment'] ?? '')),
                'attachment_mime' => null,
                'created_at' => (string) ($log['occurred_at'] ?? ''),
                'is_from_current_user' => $authorId !== '' && (int) $authorId === $viewerUserId,
                'is_from_master' => $isMasterAuthor,
                'replyable' => false,
            ];
        }

        usort($messages, static function (array $left, array $right): int {
            $timeCmp = strcmp((string) ($left['created_at'] ?? ''), (string) ($right['created_at'] ?? ''));
            if ($timeCmp !== 0) {
                return $timeCmp;
            }
            return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
        });

        $readAt = $this->getContactLastReadAt($mainId, $contactId, $viewerUserId);
        $unreadCount = 0;
        foreach ($messages as $message) {
            if ((bool) ($message['is_from_current_user'] ?? false)) {
                continue;
            }
            if ($readAt === null || strcmp((string) ($message['created_at'] ?? ''), $readAt) > 0) {
                $unreadCount++;
            }
        }

        return [
            'contact_id' => $contactId,
            'messages' => array_values($messages),
            'unread_count' => $unreadCount,
        ];
    }

    public function addContactMessage(
        int $mainId,
        string $contactId,
        int $senderUserId,
        string $senderName,
        string $senderRole,
        string $body,
        ?string $attachmentUrl = null,
        ?string $attachmentMime = null
    ): array {
        if ($senderRole !== 'master' && $senderRole !== 'agent') {
            throw new HttpException(422, 'sender_role must be agent or master');
        }
        if ($senderRole === 'master' && !$this->isMasterUser($senderUserId, $mainId)) {
            throw new HttpException(403, 'Only the Master User can send management messages.');
        }

        $threadId = $this->resolveOrCreateConversationThreadId($mainId, $contactId, $senderUserId, $senderName, $senderRole);
        $message = $this->addReply(
            $mainId,
            $threadId,
            $senderUserId,
            $senderName,
            $senderRole,
            $body,
            $attachmentUrl,
            $attachmentMime
        );

        return array_merge($message, [
            'contact_id' => $contactId,
            'kind' => 'reply',
        ]);
    }

    public function markContactConversationRead(int $mainId, string $contactId, int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO call_report_contact_read_states (main_id, contact_id, user_id, last_read_at)
             VALUES (:main_id, :contact_id, :user_id, NOW())
             ON DUPLICATE KEY UPDATE last_read_at = NOW()'
        );
        $stmt->execute([
            'main_id' => $mainId,
            'contact_id' => $contactId,
            'user_id' => $userId,
        ]);

        $threads = $this->getThreadsByContact($mainId, $contactId, $userId);
        foreach ($threads as $thread) {
            $threadId = (int) ($thread['id'] ?? 0);
            if ($threadId > 0) {
                $this->markThreadRead($mainId, $threadId, $userId);
            }
        }

        return true;
    }

    /**
     * @return array{url: string, mime: string}
     */
    /**
     * @return array{url: string, mime: string, filename: string}
     */
    public function storeConversationImage(string $imageData, string $contactId): array
    {
        $decoded = SalesReportAttachmentStore::decodeImageData($imageData);
        $uploadsDir = SalesReportAttachmentStore::ensureStorageDirectory();
        $safeContact = preg_replace('/[^a-zA-Z0-9_-]/', '', $contactId) ?: 'contact';
        $filename = $safeContact . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $decoded['ext'];
        $filepath = $uploadsDir . '/' . $filename;
        if (file_put_contents($filepath, $decoded['binary']) === false) {
            throw new HttpException(500, 'Unable to save the picture attachment.');
        }

        return [
            'url' => SalesReportAttachmentStore::buildApiPath($contactId, $filename),
            'mime' => $decoded['mime'],
            'filename' => $filename,
        ];
    }

    /**
     * @return array{path: string, mime: string, filename: string}
     */
    public function resolveAttachmentForDownload(string $contactId, string $filename): array
    {
        SalesReportAttachmentStore::assertBelongsToContact($filename, $contactId);
        $safeName = SalesReportAttachmentStore::sanitizeFilename($filename);
        $path = SalesReportAttachmentStore::absolutePath($safeName);
        if (!is_file($path)) {
            throw new HttpException(404, 'Attachment was not found.');
        }

        return [
            'path' => $path,
            'mime' => SalesReportAttachmentStore::mimeFromFilename($safeName),
            'filename' => $safeName,
        ];
    }

    /**
     * @param list<string> $contactIds
     * @return array<string, int>
     */
    public function getUnreadCountsForContacts(int $mainId, array $contactIds, int $viewerUserId): array
    {
        $normalized = [];
        foreach ($contactIds as $contactId) {
            $id = trim((string) $contactId);
            if ($id !== '') {
                $normalized[$id] = 0;
            }
        }
        if ($normalized === []) {
            return [];
        }

        $ids = array_keys($normalized);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$viewerUserId, $mainId], $ids, [$viewerUserId]);

        $messageSql = "SELECT t.contact_id AS contact_id, COUNT(m.id) AS unread_count
FROM call_report_messages m
INNER JOIN call_report_threads t ON t.id = m.thread_id
LEFT JOIN call_report_contact_read_states r
  ON r.main_id = t.main_id AND r.contact_id = t.contact_id AND r.user_id = ?
WHERE t.main_id = ?
  AND t.contact_id IN ($placeholders)
  AND m.sender_user_id <> ?
  AND (r.last_read_at IS NULL OR m.created_at > r.last_read_at)
GROUP BY t.contact_id";
        try {
            $stmt = $this->db->pdo()->prepare($messageSql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $contactId = (string) ($row['contact_id'] ?? '');
                if ($contactId !== '' && array_key_exists($contactId, $normalized)) {
                    $normalized[$contactId] += (int) ($row['unread_count'] ?? 0);
                }
            }
        } catch (\Throwable) {
        }

        $reportSql = "SELECT t.contact_id AS contact_id, COUNT(t.id) AS unread_count
FROM call_report_threads t
LEFT JOIN call_report_contact_read_states r
  ON r.main_id = t.main_id AND r.contact_id = t.contact_id AND r.user_id = ?
WHERE t.main_id = ?
  AND t.contact_id IN ($placeholders)
  AND TRIM(COALESCE(t.report_body, '')) <> ''
  AND t.agent_user_id <> ?
  AND (r.last_read_at IS NULL OR t.created_at > r.last_read_at)
GROUP BY t.contact_id";
        try {
            $stmt = $this->db->pdo()->prepare($reportSql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $contactId = (string) ($row['contact_id'] ?? '');
                if ($contactId !== '' && array_key_exists($contactId, $normalized)) {
                    $normalized[$contactId] += (int) ($row['unread_count'] ?? 0);
                }
            }
        } catch (\Throwable) {
        }

        return $normalized;
    }

    /**
     * Return the contacts that have any Agent Sales Report activity. This is
     * deliberately independent of read state: a manager must still be able to
     * find a report after opening its notification.
     *
     * @param list<string> $contactIds
     * @return list<string>
     */
    public function getReportedContactIds(int $mainId, array $contactIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($contactId): string => trim((string) $contactId),
            $contactIds
        ))));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $reported = [];
        $queries = [
            "SELECT DISTINCT contact_id FROM call_report_threads WHERE main_id = ? AND contact_id IN ($placeholders)",
            "SELECT DISTINCT CAST(lcustomer_id AS CHAR) AS contact_id
             FROM tblcustomer_logs
             WHERE lmain_id = ? AND CAST(lcustomer_id AS CHAR) IN ($placeholders)
               AND LOWER(COALESCE(ltopic, '')) = 'comment'
               AND LOWER(COALESCE(ltype, 'note')) <> 'status'",
            "SELECT DISTINCT lsessionid AS contact_id
             FROM tblpatient
             WHERE lmain_id = ? AND lsessionid IN ($placeholders)
               AND TRIM(COALESCE(lnotes, '')) <> ''
               AND (COALESCE(lstatus, 1) = 3 OR LOWER(COALESCE(lprofile_type, '')) LIKE '%prospect%')",
        ];

        foreach ($queries as $sql) {
            try {
                $stmt = $this->db->pdo()->prepare($sql);
                $stmt->execute(array_merge([$mainId], $ids));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $contactId = trim((string) ($row['contact_id'] ?? ''));
                    if ($contactId !== '') {
                        $reported[$contactId] = true;
                    }
                }
            } catch (\Throwable) {
                // Older installations may not yet have the conversation tables.
            }
        }

        return array_keys($reported);
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
        $attachmentUrl = trim((string) ($row['attachment_url'] ?? ''));
        $attachmentMime = trim((string) ($row['attachment_mime'] ?? ''));

        return [
            'id' => (string) ($row['id'] ?? ''),
            'thread_id' => (string) ($row['thread_id'] ?? ''),
            'sender_user_id' => (string) $senderUserId,
            'sender_name' => (string) ($row['sender_name'] ?? ''),
            'sender_role' => $senderRole,
            'body' => (string) ($row['body'] ?? ''),
            'attachment_url' => $attachmentUrl !== '' ? $attachmentUrl : null,
            'attachment_mime' => $attachmentUrl !== '' ? ($attachmentMime !== '' ? $attachmentMime : null) : null,
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
                $assigned = $this->resolveAssignedAgentId($mainId, (string) ($thread['contact_id'] ?? ''));
                $agentUserId = $assigned > 0 ? (string) $assigned : '';
            }
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

    private function notifyMasterOnAgentMessage(int $mainId, array $thread, array $message, string $senderName): void
    {
        try {
            $notifications = new NotificationsRepository($this->db);
            $customerName = $this->getCustomerName($mainId, (string) ($thread['contact_id'] ?? ''));
            $snippet = $this->messageSnippet((string) ($message['body'] ?? ''));
            $messageId = (string) ($message['id'] ?? '');
            $contactId = (string) ($thread['contact_id'] ?? '');

            $notifications->create([
                'recipient_id' => (string) $mainId,
                'title' => 'New Agent Sales Report message',
                'message' => sprintf(
                    '%s sent a message about %s. %s',
                    $senderName !== '' ? $senderName : 'A sales agent',
                    $customerName,
                    $snippet
                ),
                'type' => 'info',
                'category' => 'notification',
                'main_id' => (string) $mainId,
                'action_url' => 'sales-transaction-daily-call-monitoring',
                'metadata' => [
                    'entity_type' => 'call_report_reply',
                    'entity_id' => $messageId,
                    'contact_id' => $contactId,
                    'thread_id' => (string) ($thread['id'] ?? ''),
                    'action' => 'agent_message',
                    'status' => 'unread',
                    'action_url' => 'sales-transaction-daily-call-monitoring',
                    'refno' => 'call-report-agent-message:' . $messageId,
                    'idempotency_key' => 'call-report-agent-message:' . $messageId . ':master',
                    'category' => 'notification',
                    'actor_role' => 'Sales Agent',
                ],
            ]);
        } catch (\Throwable $error) {
            error_log('Call report master follow-up notification failed: ' . $error->getMessage());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchLegacyConversationLogs(int $mainId, string $contactId): array
    {
        $sql = <<<SQL
SELECT
    CAST(cl.lid AS CHAR) AS id,
    CAST(cl.lcustomer_id AS CHAR) AS contact_id,
    COALESCE(NULLIF(cl.lstatus, ''), 'Note') AS status,
    cl.lnotes AS note,
    cl.comments AS comments,
    NULLIF(cl.lfile, '') AS attachment,
    cl.ldatetime AS occurred_at,
    CAST(cl.luser AS CHAR) AS created_by,
    COALESCE(
        NULLIF(TRIM(CONCAT(COALESCE(ua.lfname, ''), ' ', COALESCE(ua.llname, ''))), ''),
        CAST(cl.luser AS CHAR)
    ) AS created_by_name
FROM tblcustomer_logs cl
LEFT JOIN tblaccount ua ON CAST(ua.lid AS CHAR) = CAST(cl.luser AS CHAR)
WHERE cl.lmain_id = :main_id
  AND CAST(cl.lcustomer_id AS CHAR) = :contact_id
  AND LOWER(COALESCE(cl.ltopic, '')) = 'comment'
  AND LOWER(COALESCE(cl.ltype, 'note')) <> 'status'
ORDER BY cl.ldatetime ASC, cl.lid ASC
LIMIT 300
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['main_id' => $mainId, 'contact_id' => $contactId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function normalizeLegacyAttachmentUrl(string $attachment): ?string
    {
        $value = trim($attachment);
        if ($value === '') {
            return null;
        }
        if (str_starts_with($value, 'data:image/') || str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, '/')) {
            return $value;
        }
        return null;
    }

    private function getContactLastReadAt(int $mainId, string $contactId, int $userId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT last_read_at FROM call_report_contact_read_states
             WHERE main_id = :main_id AND contact_id = :contact_id AND user_id = :user_id
             LIMIT 1'
        );
        try {
            $stmt->execute(['main_id' => $mainId, 'contact_id' => $contactId, 'user_id' => $userId]);
            $value = $stmt->fetchColumn();
            if ($value !== false && $value !== null && trim((string) $value) !== '') {
                return (string) $value;
            }
        } catch (\Throwable) {
            // Table may not exist until migration 048 is applied.
        }
        return null;
    }

    private function resolveAssignedAgentId(int $mainId, string $contactId): int
    {
        if ($contactId === '') {
            return 0;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT CAST(COALESCE(lsales_person, 0) AS SIGNED)
             FROM tblpatient
             WHERE lmain_id = :main_id
               AND (CAST(lid AS CHAR) = :contact_id OR lsessionid = :contact_session)
               AND COALESCE(ldeleted, 0) = 0
             LIMIT 1'
        );
        $stmt->execute([
            'main_id' => $mainId,
            'contact_id' => $contactId,
            'contact_session' => $contactId,
        ]);
        return (int) $stmt->fetchColumn();
    }

    private function resolveOrCreateConversationThreadId(
        int $mainId,
        string $contactId,
        int $senderUserId,
        string $senderName,
        string $senderRole
    ): int {
        $threads = $this->getThreadsByContact($mainId, $contactId, $senderUserId);
        foreach ($threads as $thread) {
            if (($thread['replyable'] ?? true) === false) {
                continue;
            }
            $threadId = (int) ($thread['id'] ?? 0);
            if ($threadId > 0) {
                return $threadId;
            }
        }

        $assignedAgentId = $this->resolveAssignedAgentId($mainId, $contactId);
        $agentUserId = $assignedAgentId > 0
            ? $assignedAgentId
            : ($senderRole === 'agent' ? $senderUserId : $senderUserId);
        $agentName = $senderRole === 'agent'
            ? ($senderName !== '' ? $senderName : ('User ' . $senderUserId))
            : 'Sales Agent';

        if ($assignedAgentId > 0 && $senderRole === 'master') {
            $nameStmt = $this->db->pdo()->prepare(
                'SELECT TRIM(CONCAT(COALESCE(lfname, \'\'), \' \', COALESCE(llname, \'\'))) FROM tblaccount WHERE lid = :id LIMIT 1'
            );
            $nameStmt->execute(['id' => $assignedAgentId]);
            $resolvedName = trim((string) $nameStmt->fetchColumn());
            if ($resolvedName !== '') {
                $agentName = $resolvedName;
            }
            $agentUserId = $assignedAgentId;
        }

        $hasDirectColumn = $this->hasColumn('call_report_threads', 'is_direct');
        $columns = 'main_id, contact_id, call_log_entry_id, call_log_refno, agent_user_id, agent_name, outcome, report_body, created_at';
        $values = ':main_id, :contact_id, NULL, :call_log_refno, :agent_user_id, :agent_name, \'note\', \'\', NOW()';
        $params = [
            'main_id' => $mainId,
            'contact_id' => $contactId,
            'call_log_refno' => 'direct-' . $contactId,
            'agent_user_id' => $agentUserId > 0 ? $agentUserId : $senderUserId,
            'agent_name' => $agentName,
        ];
        if ($hasDirectColumn) {
            $columns = 'main_id, contact_id, is_direct, call_log_entry_id, call_log_refno, agent_user_id, agent_name, outcome, report_body, created_at';
            $values = ':main_id, :contact_id, 1, NULL, :call_log_refno, :agent_user_id, :agent_name, \'note\', \'\', NOW()';
        }

        $insert = $this->db->pdo()->prepare(
            "INSERT INTO call_report_threads ($columns) VALUES ($values)"
        );
        $insert->execute($params);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function assertImageAttachment(string $url, string $mime): void
    {
        SalesReportAttachmentStore::assertImageMime($mime);
        if ($url === '') {
            throw new HttpException(422, 'Picture attachment URL is required.');
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
        );
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);
        return (int) $stmt->fetchColumn() > 0;
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
