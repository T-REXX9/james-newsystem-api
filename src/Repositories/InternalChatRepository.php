<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class InternalChatRepository
{
    private const CHAT_TYPE = 'internal_chat';
    private const STATUS_UNREAD = 1;
    private const STATUS_READ = 0;

    public function __construct(private readonly Database $db)
    {
    }

    public function listParticipants(int $mainId, int $currentUserId): array
    {
        $sql = <<<SQL
SELECT
    CAST(a.lid AS CHAR) AS id,
    CAST(COALESCE(
        CASE
            WHEN CAST(COALESCE(a.ltype, 0) AS SIGNED) = 1 THEN a.lid
            ELSE a.lmother_id
        END,
        a.lid
    ) AS CHAR) AS main_id,
    TRIM(CONCAT_WS(' ', COALESCE(a.lfname, ''), COALESCE(a.llname, ''))) AS full_name,
    COALESCE(a.lemail, '') AS email,
    COALESCE(
        (SELECT ut.ltype_name FROM tblusertype ut WHERE ut.lid = a.ltype LIMIT 1),
        'Staff'
    ) AS role,
    COALESCE(a.lavatar, '') AS avatar_url,
    CAST(COALESCE(a.ltype, 0) AS SIGNED) = 1 AS is_owner
FROM tblaccount a
WHERE COALESCE(a.lstatus, 0) = 1
  AND COALESCE(a.lactivation, 1) <> 0
  AND (
    a.lid = :main_id_self
    OR a.lmother_id = :main_id_child
  )
  AND a.lid <> :current_user_id
ORDER BY is_owner DESC, full_name ASC, a.lid ASC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':main_id_self', $mainId, PDO::PARAM_INT);
        $stmt->bindValue(':main_id_child', $mainId, PDO::PARAM_INT);
        $stmt->bindValue(':current_user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();

        return $this->dedupeParticipants(
            array_map(fn (array $row): array => $this->normalizeParticipant($row), $stmt->fetchAll(PDO::FETCH_ASSOC))
        );
    }

    public function listConversations(int $mainId, int $currentUserId): array
    {
        $participantMap = $this->participantMapForMain($mainId);
        $unreadByConversation = $this->unreadCountsByConversation($currentUserId);

        $stmt = $this->db->pdo()->prepare(
            'SELECT
                CAST(lid AS CHAR) AS id,
                COALESCE(lsource, \'\') AS conversation_key,
                COALESCE(lmessage, \'\') AS message,
                COALESCE(DATE_FORMAT(ldatetime, \'%Y-%m-%d %H:%i:%s\'), \'\') AS created_at,
                CAST(COALESCE(lsendfrom, \'\') AS CHAR) AS sender_id,
                CAST(COALESCE(lsendto, \'\') AS CHAR) AS recipient_id
             FROM tblsms
             WHERE COALESCE(ltype, \'\') = :chat_type
               AND (
                 CAST(COALESCE(lsendfrom, \'\') AS CHAR) = :current_user_id_sender
                 OR CAST(COALESCE(lsendto, \'\') AS CHAR) = :current_user_id_recipient
               )
             ORDER BY ldatetime DESC, lid DESC'
        );
        $stmt->execute([
            ':chat_type' => self::CHAT_TYPE,
            ':current_user_id_sender' => (string) $currentUserId,
            ':current_user_id_recipient' => (string) $currentUserId,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $conversations = [];

        foreach ($rows as $row) {
            $conversationKey = trim((string) ($row['conversation_key'] ?? ''));
            if ($conversationKey === '') {
                continue;
            }

            if (isset($conversations[$conversationKey])) {
                continue;
            }

            $senderId = trim((string) ($row['sender_id'] ?? ''));
            $recipientId = trim((string) ($row['recipient_id'] ?? ''));
            $otherParticipantId = $senderId === (string) $currentUserId ? $recipientId : $senderId;

            if ($otherParticipantId === '' || !isset($participantMap[$otherParticipantId])) {
                continue;
            }

            $conversations[$conversationKey] = [
                'conversation_key' => $conversationKey,
                'other_participant' => $participantMap[$otherParticipantId],
                'last_message_preview' => $this->buildPreview((string) ($row['message'] ?? '')),
                'last_message_at' => (string) ($row['created_at'] ?? ''),
                'unread_count' => $unreadByConversation[$conversationKey] ?? 0,
            ];
        }

        return array_values($conversations);
    }

    public function listMessages(int $mainId, int $currentUserId, string $conversationKey): array
    {
        $participantIds = $this->participantIdsForConversationKey($conversationKey);
        if ($participantIds === null || !in_array((string) $currentUserId, $participantIds, true)) {
            throw new RuntimeException('Conversation not found');
        }

        $allowedParticipants = array_fill_keys(array_map('strval', $this->participantIdsForMain($mainId)), true);
        foreach ($participantIds as $participantId) {
            if (!isset($allowedParticipants[$participantId]) && $participantId !== (string) $currentUserId) {
                throw new RuntimeException('Conversation not found');
            }
        }

        $participantMap = $this->participantMapForMain($mainId);

        $stmt = $this->db->pdo()->prepare(
            'SELECT
                CAST(lid AS CHAR) AS id,
                COALESCE(lsource, \'\') AS conversation_key,
                COALESCE(lmessage, \'\') AS message,
                COALESCE(DATE_FORMAT(ldatetime, \'%Y-%m-%d %H:%i:%s\'), \'\') AS created_at,
                CAST(COALESCE(lsendfrom, \'\') AS CHAR) AS sender_id,
                CAST(COALESCE(lsendto, \'\') AS CHAR) AS recipient_id
             FROM tblsms
             WHERE COALESCE(ltype, \'\') = :chat_type
               AND COALESCE(lsource, \'\') = :conversation_key
               AND (
                 CAST(COALESCE(lsendfrom, \'\') AS CHAR) = :current_user_id_sender
                 OR CAST(COALESCE(lsendto, \'\') AS CHAR) = :current_user_id_recipient
               )
             ORDER BY ldatetime ASC, lid ASC'
        );
        $stmt->execute([
            ':chat_type' => self::CHAT_TYPE,
            ':conversation_key' => $conversationKey,
            ':current_user_id_sender' => (string) $currentUserId,
            ':current_user_id_recipient' => (string) $currentUserId,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row) use ($currentUserId, $participantMap): array {
            $senderId = trim((string) ($row['sender_id'] ?? ''));
            $recipientId = trim((string) ($row['recipient_id'] ?? ''));
            $sender = $participantMap[$senderId] ?? $this->fallbackParticipant($senderId);
            $recipient = $participantMap[$recipientId] ?? $this->fallbackParticipant($recipientId);

            return [
                'id' => (string) ($row['id'] ?? ''),
                'conversation_key' => (string) ($row['conversation_key'] ?? ''),
                'sender_id' => $senderId,
                'recipient_id' => $recipientId,
                'message' => (string) ($row['message'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'is_from_current_user' => $senderId === (string) $currentUserId,
                'sender_name' => (string) ($sender['full_name'] ?? ''),
                'recipient_name' => (string) ($recipient['full_name'] ?? ''),
                'sender_avatar_url' => (string) ($sender['avatar_url'] ?? ''),
                'recipient_avatar_url' => (string) ($recipient['avatar_url'] ?? ''),
            ];
        }, $rows);
    }

    public function sendMessage(
        int $mainId,
        int $senderUserId,
        string $message,
        array $recipientIds
    ): array {
        $cleanMessage = trim($message);
        if ($cleanMessage === '') {
            throw new RuntimeException('Message is required');
        }

        $allowedParticipants = array_fill_keys(array_map('strval', $this->participantIdsForMain($mainId)), true);
        $allowedParticipants[(string) $senderUserId] = true;

        $normalizedRecipients = [];
        foreach ($recipientIds as $recipientId) {
            $normalized = trim((string) $recipientId);
            if ($normalized === '' || $normalized === (string) $senderUserId) {
                continue;
            }
            if (!isset($allowedParticipants[$normalized])) {
                continue;
            }
            $normalizedRecipients[$normalized] = true;
        }

        if ($normalizedRecipients === []) {
            throw new RuntimeException('At least one valid recipient is required');
        }

        $participantMap = $this->participantMapForMain($mainId);
        $sender = $participantMap[(string) $senderUserId] ?? $this->fallbackParticipant((string) $senderUserId);
        $sentAt = date('Y-m-d H:i:s');
        $preview = $this->buildPreview($cleanMessage);
        $created = [];

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $smsStmt = $pdo->prepare(
                'INSERT INTO tblsms
                    (lto, lmessage, lmodem, ldatetime, lstatus, ltype, lgateway, lsource, lpriority, lsendfrom, lsendto, ldatetime_pickup, lresend_ctr)
                 VALUES
                    (:recipient_id_to, :message, NULL, :sent_at, :status, :chat_type, NULL, :conversation_key, NULL, :sender_id, :recipient_id_sendto, NULL, NULL)'
            );
            $alertStmt = $pdo->prepare(
                'INSERT INTO tblusers_alerts
                    (lmainid, lgenrefno, lrefno, luserid, lstatus, lmessage, lutype, ldatetime, ltype)
                 VALUES
                    (:main_id, :message_id, :conversation_key, :recipient_id, :unread_status, :preview, :sender_id, :sent_at, :chat_type)'
            );

            foreach (array_keys($normalizedRecipients) as $recipientId) {
                $conversationKey = self::buildConversationKey($senderUserId, (int) $recipientId);

                $smsStmt->execute([
                    ':recipient_id_to' => $recipientId,
                    ':message' => $cleanMessage,
                    ':sent_at' => $sentAt,
                    ':status' => 'sent',
                    ':chat_type' => self::CHAT_TYPE,
                    ':conversation_key' => $conversationKey,
                    ':sender_id' => (string) $senderUserId,
                    ':recipient_id_sendto' => $recipientId,
                ]);

                $messageId = (string) $pdo->lastInsertId();

                $alertStmt->execute([
                    ':main_id' => (string) $mainId,
                    ':message_id' => $messageId,
                    ':conversation_key' => $conversationKey,
                    ':recipient_id' => $recipientId,
                    ':unread_status' => self::STATUS_UNREAD,
                    ':preview' => $preview,
                    ':sender_id' => (string) $senderUserId,
                    ':sent_at' => $sentAt,
                    ':chat_type' => self::CHAT_TYPE,
                ]);

                $created[] = [
                    'id' => $messageId,
                    'conversation_key' => $conversationKey,
                    'sender_id' => (string) $senderUserId,
                    'recipient_id' => $recipientId,
                    'message' => $cleanMessage,
                    'created_at' => $sentAt,
                    'is_from_current_user' => true,
                    'sender_name' => (string) ($sender['full_name'] ?? ''),
                    'recipient_name' => (string) (($participantMap[$recipientId]['full_name'] ?? $recipientId)),
                    'sender_avatar_url' => (string) ($sender['avatar_url'] ?? ''),
                    'recipient_avatar_url' => (string) (($participantMap[$recipientId]['avatar_url'] ?? '')),
                ];
            }

            $pdo->commit();
            return $created;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function markConversationRead(int $currentUserId, string $conversationKey): array
    {
        $select = $this->db->pdo()->prepare(
            'SELECT CAST(lid AS CHAR) AS id
             FROM tblusers_alerts
             WHERE COALESCE(ltype, \'\') = :chat_type
               AND COALESCE(lrefno, \'\') = :conversation_key
               AND COALESCE(luserid, \'\') = :current_user_id
               AND COALESCE(lstatus, 0) = :unread_status'
        );
        $select->execute([
            ':chat_type' => self::CHAT_TYPE,
            ':conversation_key' => $conversationKey,
            ':current_user_id' => (string) $currentUserId,
            ':unread_status' => self::STATUS_UNREAD,
        ]);

        $ids = array_map(
            static fn (array $row): string => (string) ($row['id'] ?? ''),
            $select->fetchAll(PDO::FETCH_ASSOC)
        );

        if ($ids !== []) {
            $update = $this->db->pdo()->prepare(
                'UPDATE tblusers_alerts
                 SET lstatus = :read_status
                 WHERE COALESCE(ltype, \'\') = :chat_type
                   AND COALESCE(lrefno, \'\') = :conversation_key
                   AND COALESCE(luserid, \'\') = :current_user_id
                   AND COALESCE(lstatus, 0) = :unread_status'
            );
            $update->execute([
                ':read_status' => self::STATUS_READ,
                ':chat_type' => self::CHAT_TYPE,
                ':conversation_key' => $conversationKey,
                ':current_user_id' => (string) $currentUserId,
                ':unread_status' => self::STATUS_UNREAD,
            ]);
        }

        return [
            'success' => true,
            'updated_count' => count($ids),
            'conversation_key' => $conversationKey,
            'updated_ids' => $ids,
        ];
    }

    public function getUnreadCount(int $currentUserId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
             FROM tblusers_alerts
             WHERE COALESCE(ltype, \'\') = :chat_type
               AND COALESCE(luserid, \'\') = :current_user_id
               AND COALESCE(lstatus, 0) = :unread_status'
        );
        $stmt->execute([
            ':chat_type' => self::CHAT_TYPE,
            ':current_user_id' => (string) $currentUserId,
            ':unread_status' => self::STATUS_UNREAD,
        ]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public static function buildConversationKey(int $firstUserId, int $secondUserId): string
    {
        $users = [$firstUserId, $secondUserId];
        sort($users, SORT_NUMERIC);
        return sprintf('dm:%d:%d', $users[0], $users[1]);
    }

    private function participantIdsForMain(int $mainId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT CAST(lid AS CHAR) AS id
             FROM tblaccount
             WHERE COALESCE(lstatus, 0) = 1
               AND COALESCE(lactivation, 1) <> 0
               AND (lid = :main_id_self OR lmother_id = :main_id_child)'
        );
        $stmt->bindValue(':main_id_self', $mainId, PDO::PARAM_INT);
        $stmt->bindValue(':main_id_child', $mainId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row): string => (string) ($row['id'] ?? ''),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private function participantMapForMain(int $mainId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                CAST(a.lid AS CHAR) AS id,
                CAST(COALESCE(
                    CASE
                        WHEN CAST(COALESCE(a.ltype, 0) AS SIGNED) = 1 THEN a.lid
                        ELSE a.lmother_id
                    END,
                    a.lid
                ) AS CHAR) AS main_id,
                TRIM(CONCAT_WS(\' \', COALESCE(a.lfname, \'\'), COALESCE(a.llname, \'\'))) AS full_name,
                COALESCE(a.lemail, \'\') AS email,
                COALESCE(
                    (SELECT ut.ltype_name FROM tblusertype ut WHERE ut.lid = a.ltype LIMIT 1),
                    \'Staff\'
                ) AS role,
                COALESCE(a.lavatar, \'\') AS avatar_url,
                CAST(COALESCE(a.ltype, 0) AS SIGNED) = 1 AS is_owner
             FROM tblaccount a
             WHERE COALESCE(a.lstatus, 0) = 1
               AND COALESCE(a.lactivation, 1) <> 0
               AND (a.lid = :main_id_self OR a.lmother_id = :main_id_child)'
        );
        $stmt->bindValue(':main_id_self', $mainId, PDO::PARAM_INT);
        $stmt->bindValue(':main_id_child', $mainId, PDO::PARAM_INT);
        $stmt->execute();

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $normalized = $this->normalizeParticipant($row);
            $map[$normalized['id']] = $normalized;
        }

        return $map;
    }

    private function unreadCountsByConversation(int $currentUserId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                COALESCE(lrefno, \'\') AS conversation_key,
                COUNT(*) AS unread_count
             FROM tblusers_alerts
             WHERE COALESCE(ltype, \'\') = :chat_type
               AND COALESCE(luserid, \'\') = :current_user_id
               AND COALESCE(lstatus, 0) = :unread_status
             GROUP BY COALESCE(lrefno, \'\')'
        );
        $stmt->execute([
            ':chat_type' => self::CHAT_TYPE,
            ':current_user_id' => (string) $currentUserId,
            ':unread_status' => self::STATUS_UNREAD,
        ]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = trim((string) ($row['conversation_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $result[$key] = (int) ($row['unread_count'] ?? 0);
        }

        return $result;
    }

    private function participantIdsForConversationKey(string $conversationKey): ?array
    {
        if (!preg_match('/^dm:(\d+):(\d+)$/', $conversationKey, $matches)) {
            return null;
        }

        return [
            (string) $matches[1],
            (string) $matches[2],
        ];
    }

    private function buildPreview(string $message): string
    {
        $preview = preg_replace('/\s+/', ' ', trim($message)) ?? '';
        if (function_exists('mb_substr')) {
            return (string) mb_substr($preview, 0, 225);
        }

        return substr($preview, 0, 225);
    }

    private function normalizeParticipant(array $row): array
    {
        $fullName = trim((string) ($row['full_name'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));

        return [
            'id' => trim((string) ($row['id'] ?? '')),
            'main_id' => trim((string) ($row['main_id'] ?? '')),
            'full_name' => $fullName !== '' ? $fullName : ($email !== '' ? $email : 'Unknown User'),
            'email' => $email,
            'role' => trim((string) ($row['role'] ?? 'Staff')),
            'avatar_url' => trim((string) ($row['avatar_url'] ?? '')),
            'is_owner' => (bool) ($row['is_owner'] ?? false),
        ];
    }

    private function fallbackParticipant(string $id): array
    {
        return [
            'id' => $id,
            'main_id' => '',
            'full_name' => $id !== '' ? sprintf('User %s', $id) : 'Unknown User',
            'email' => '',
            'role' => 'Staff',
            'avatar_url' => '',
            'is_owner' => false,
        ];
    }

    private function dedupeParticipants(array $participants): array
    {
        $seen = [];
        $deduped = [];

        foreach ($participants as $participant) {
            $emailKey = strtolower(trim((string) ($participant['email'] ?? '')));
            $nameKey = strtolower(trim((string) ($participant['full_name'] ?? '')));
            $key = $emailKey !== '' ? 'email:' . $emailKey : 'name:' . $nameKey;

            if ($key !== 'name:' && isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $participant;
        }

        return $deduped;
    }
}
