<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\InternalChatReactionStore;
use App\Support\InternalChatReplyStore;
use PDO;
use RuntimeException;

final class InternalChatRepository
{
    private const CHAT_TYPE = 'internal_chat';
    private const STATUS_UNREAD = 1;
    private const STATUS_READ = 0;

    public function __construct(
        private readonly Database $db,
        private readonly ?InternalChatReactionStore $reactionStore = null,
        private readonly ?InternalChatReplyStore $replyStore = null
    )
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
        $this->assertConversationAccess($mainId, $currentUserId, $conversationKey);

        $participantMap = $this->participantMapForMain($mainId);

        $stmt = $this->db->pdo()->prepare(
            'SELECT
                CAST(s.lid AS CHAR) AS id,
                COALESCE(s.lsource, \'\') AS conversation_key,
                COALESCE(s.lmessage, \'\') AS message,
                COALESCE(DATE_FORMAT(s.ldatetime, \'%Y-%m-%d %H:%i:%s\'), \'\') AS created_at,
                CAST(COALESCE(s.lsendfrom, \'\') AS CHAR) AS sender_id,
                CAST(COALESCE(s.lsendto, \'\') AS CHAR) AS recipient_id,
                CAST(COALESCE(ua.lstatus, -1) AS SIGNED) AS recipient_alert_status,
                CAST(COALESCE(ua.lid, 0) AS SIGNED) AS recipient_alert_id
             FROM tblsms s
             LEFT JOIN tblusers_alerts ua
               ON COALESCE(ua.ltype, \'\') = :alert_chat_type
              AND COALESCE(ua.lgenrefno, \'\') = CAST(s.lid AS CHAR)
              AND COALESCE(ua.luserid, \'\') = CAST(COALESCE(s.lsendto, \'\') AS CHAR)
             WHERE COALESCE(s.ltype, \'\') = :chat_type
               AND COALESCE(s.lsource, \'\') = :conversation_key
               AND (
                 CAST(COALESCE(s.lsendfrom, \'\') AS CHAR) = :current_user_id_sender
                 OR CAST(COALESCE(s.lsendto, \'\') AS CHAR) = :current_user_id_recipient
               )
             ORDER BY s.ldatetime ASC, s.lid ASC'
        );
        $stmt->execute([
            ':alert_chat_type' => self::CHAT_TYPE,
            ':chat_type' => self::CHAT_TYPE,
            ':conversation_key' => $conversationKey,
            ':current_user_id_sender' => (string) $currentUserId,
            ':current_user_id_recipient' => (string) $currentUserId,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $messages = array_map(function (array $row) use ($currentUserId, $participantMap): array {
            $senderId = trim((string) ($row['sender_id'] ?? ''));
            $recipientId = trim((string) ($row['recipient_id'] ?? ''));
            $sender = $participantMap[$senderId] ?? $this->fallbackParticipant($senderId);
            $recipient = $participantMap[$recipientId] ?? $this->fallbackParticipant($recipientId);
            $alertStatus = (int) ($row['recipient_alert_status'] ?? -1);
            $alertExists = (int) ($row['recipient_alert_id'] ?? 0) > 0;
            $isReadByRecipient = $alertExists && $alertStatus === self::STATUS_READ;
            $deliveryStatus = 'sent';

            if ($alertExists) {
                $deliveryStatus = $isReadByRecipient ? 'read' : 'delivered';
            }

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
                'delivery_status' => $deliveryStatus,
                'is_read_by_recipient' => $isReadByRecipient,
                'reactions' => [],
                'current_user_reaction' => null,
                'reply_to_message_id' => null,
                'reply_preview' => null,
            ];
        }, $rows);

        if ($this->reactionStore !== null && $messages !== []) {
            $reactionSummaries = $this->reactionStore->summarizeMessages($messages, (string) $currentUserId);
            foreach ($messages as &$message) {
                $summary = $reactionSummaries[(string) ($message['id'] ?? '')] ?? null;
                if (!is_array($summary)) {
                    continue;
                }

                $message['reactions'] = array_values($summary['reactions'] ?? []);
                $message['current_user_reaction'] = $summary['current_user_reaction'] ?? null;
            }
            unset($message);
        }

        return $this->attachReplyMetadata($messages, $currentUserId, $participantMap);
    }

    public function sendMessage(
        int $mainId,
        int $senderUserId,
        string $message,
        array $recipientIds,
        ?string $replyToMessageId = null
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

        $normalizedReplyToMessageId = trim((string) ($replyToMessageId ?? ''));
        if ($normalizedReplyToMessageId !== '') {
            if (count($normalizedRecipients) !== 1) {
                throw new RuntimeException('Replies are only supported in a single direct conversation');
            }

            $replyRecipientId = (string) array_key_first($normalizedRecipients);
            $replyConversationKey = self::buildConversationKey($senderUserId, (int) $replyRecipientId);
            $replyTarget = $this->getMessageForUser($mainId, $senderUserId, $normalizedReplyToMessageId);
            if ((string) ($replyTarget['conversation_key'] ?? '') !== $replyConversationKey) {
                throw new RuntimeException('Reply target must belong to the same conversation');
            }
        }

        $participantMap = $this->participantMapForMain($mainId);
        $sender = $participantMap[(string) $senderUserId] ?? $this->fallbackParticipant((string) $senderUserId);
        $sentAt = date('Y-m-d H:i:s');
        $preview = $this->buildPreview($cleanMessage);
        $created = [];
        $replyWrites = [];

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
                    'delivery_status' => 'delivered',
                    'is_read_by_recipient' => false,
                    'reactions' => [],
                    'current_user_reaction' => null,
                    'reply_to_message_id' => null,
                    'reply_preview' => null,
                ];

                if ($normalizedReplyToMessageId !== '' && $this->replyStore !== null) {
                    $this->replyStore->saveReply($conversationKey, $messageId, $normalizedReplyToMessageId);
                    $replyWrites[] = [
                        'conversation_key' => $conversationKey,
                        'message_id' => $messageId,
                    ];
                }
            }

            $pdo->commit();
            return $this->attachReplyMetadata($created, $senderUserId, $participantMap, true);
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($replyWrites !== [] && $this->replyStore !== null) {
                foreach ($replyWrites as $replyWrite) {
                    $this->replyStore->deleteReplies(
                        (string) ($replyWrite['conversation_key'] ?? ''),
                        [(string) ($replyWrite['message_id'] ?? '')]
                    );
                }
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

    public function assertConversationAccess(int $mainId, int $currentUserId, string $conversationKey): void
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
    }

    public function getMessageForUser(int $mainId, int $currentUserId, string $messageId): array
    {
        $normalizedId = trim($messageId);
        if ($normalizedId === '' || !ctype_digit($normalizedId)) {
            throw new RuntimeException('Message not found');
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT
                CAST(s.lid AS CHAR) AS id,
                COALESCE(s.lsource, \'\') AS conversation_key,
                CAST(COALESCE(s.lsendfrom, \'\') AS CHAR) AS sender_id,
                CAST(COALESCE(s.lsendto, \'\') AS CHAR) AS recipient_id
             FROM tblsms s
             WHERE COALESCE(s.ltype, \'\') = :chat_type
               AND s.lid = :message_id
               AND (
                 CAST(COALESCE(s.lsendfrom, \'\') AS CHAR) = :current_user_id_sender
                 OR CAST(COALESCE(s.lsendto, \'\') AS CHAR) = :current_user_id_recipient
               )
             LIMIT 1'
        );
        $stmt->execute([
            ':chat_type' => self::CHAT_TYPE,
            ':message_id' => (int) $normalizedId,
            ':current_user_id_sender' => (string) $currentUserId,
            ':current_user_id_recipient' => (string) $currentUserId,
        ]);

        $message = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($message)) {
            throw new RuntimeException('Message not found');
        }

        $conversationKey = trim((string) ($message['conversation_key'] ?? ''));
        $this->assertConversationAccess($mainId, $currentUserId, $conversationKey);

        return [
            'id' => (string) ($message['id'] ?? ''),
            'conversation_key' => $conversationKey,
            'sender_id' => trim((string) ($message['sender_id'] ?? '')),
            'recipient_id' => trim((string) ($message['recipient_id'] ?? '')),
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

    public function getRealtimeSnapshot(int $mainId, int $currentUserId): array
    {
        $latestMessageStmt = $this->db->pdo()->prepare(
            'SELECT
                CAST(s.lid AS CHAR) AS id,
                COALESCE(s.lsource, \'\') AS conversation_key,
                COALESCE(s.lmessage, \'\') AS message,
                COALESCE(DATE_FORMAT(s.ldatetime, \'%Y-%m-%d %H:%i:%s\'), \'\') AS created_at,
                CAST(COALESCE(s.lsendfrom, \'\') AS CHAR) AS sender_id,
                CAST(COALESCE(s.lsendto, \'\') AS CHAR) AS recipient_id,
                TRIM(CONCAT_WS(\' \', COALESCE(a.lfname, \'\'), COALESCE(a.llname, \'\'))) AS sender_name
             FROM tblsms s
             LEFT JOIN tblaccount a
               ON CAST(a.lid AS CHAR) = CAST(COALESCE(s.lsendfrom, \'\') AS CHAR)
             WHERE COALESCE(s.ltype, \'\') = :chat_type
               AND (
                 CAST(COALESCE(s.lsendfrom, \'\') AS CHAR) = :current_user_id_sender
                 OR CAST(COALESCE(s.lsendto, \'\') AS CHAR) = :current_user_id_recipient
               )
             ORDER BY s.ldatetime DESC, s.lid DESC
             LIMIT 1'
        );
        $latestMessageStmt->execute([
            ':chat_type' => self::CHAT_TYPE,
            ':current_user_id_sender' => (string) $currentUserId,
            ':current_user_id_recipient' => (string) $currentUserId,
        ]);
        $latestMessage = $latestMessageStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $alertStatsStmt = $this->db->pdo()->prepare(
            'SELECT
                CAST(COALESCE(MAX(lid), 0) AS CHAR) AS latest_alert_id,
                SUM(CASE WHEN COALESCE(lstatus, 0) = :unread_status THEN 1 ELSE 0 END) AS unread_count
             FROM tblusers_alerts
             WHERE COALESCE(ltype, \'\') = :chat_type
               AND COALESCE(luserid, \'\') = :current_user_id'
        );
        $alertStatsStmt->execute([
            ':chat_type' => self::CHAT_TYPE,
            ':current_user_id' => (string) $currentUserId,
            ':unread_status' => self::STATUS_UNREAD,
        ]);
        $alertStats = $alertStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'latest_message_id' => (string) ($latestMessage['id'] ?? '0'),
            'latest_message_at' => (string) ($latestMessage['created_at'] ?? ''),
            'latest_message_preview' => $this->buildPreview((string) ($latestMessage['message'] ?? '')),
            'latest_conversation_key' => (string) ($latestMessage['conversation_key'] ?? ''),
            'latest_sender_id' => trim((string) ($latestMessage['sender_id'] ?? '')),
            'latest_sender_name' => trim((string) ($latestMessage['sender_name'] ?? '')),
            'latest_alert_id' => (string) ($alertStats['latest_alert_id'] ?? '0'),
            'unread_count' => (int) ($alertStats['unread_count'] ?? 0),
        ];
    }

    public static function buildConversationKey(int $firstUserId, int $secondUserId): string
    {
        $users = [$firstUserId, $secondUserId];
        sort($users, SORT_NUMERIC);
        return sprintf('dm:%d:%d', $users[0], $users[1]);
    }

    private function attachReplyMetadata(
        array $messages,
        int $currentUserId,
        array $participantMap,
        bool $allowDatabaseLookup = true
    ): array {
        foreach ($messages as &$message) {
            $message['reply_to_message_id'] = $message['reply_to_message_id'] ?? null;
            $message['reply_preview'] = $message['reply_preview'] ?? null;
        }
        unset($message);

        if ($this->replyStore === null || $messages === []) {
            return $messages;
        }

        $messagePositions = [];
        $messageIndex = [];
        $messageIdsByConversation = [];
        foreach ($messages as $index => $message) {
            $conversationKey = trim((string) ($message['conversation_key'] ?? ''));
            $messageId = trim((string) ($message['id'] ?? ''));
            if ($conversationKey === '' || $messageId === '') {
                continue;
            }

            $messagePositions[$conversationKey][$messageId] = $index;
            $messageIndex[$conversationKey][$messageId] = $message;
            $messageIdsByConversation[$conversationKey][] = $messageId;
        }

        foreach ($messageIdsByConversation as $conversationKey => $messageIds) {
            $replyLinks = $this->replyStore->listReplies($conversationKey, $messageIds);
            $emptyReplyIds = [];

            foreach ($replyLinks as $messageId => $replyLink) {
                $position = $messagePositions[$conversationKey][$messageId] ?? null;
                if (!is_int($position)) {
                    continue;
                }

                $replyToMessageId = trim((string) ($replyLink['reply_to_message_id'] ?? ''));
                if ($replyToMessageId === '') {
                    $emptyReplyIds[] = $messageId;
                    continue;
                }

                $messages[$position]['reply_to_message_id'] = $replyToMessageId;
                $replySource = $messageIndex[$conversationKey][$replyToMessageId] ?? null;
                if (!is_array($replySource) && $allowDatabaseLookup) {
                    $replySource = $this->findReplySourceMessage(
                        $conversationKey,
                        $replyToMessageId,
                        $participantMap,
                        $currentUserId
                    );
                }

                $messages[$position]['reply_preview'] = is_array($replySource)
                    ? $this->buildReplyPreview($replySource, $currentUserId)
                    : $this->buildUnavailableReplyPreview($replyToMessageId);
            }

            if ($emptyReplyIds !== []) {
                $this->replyStore->deleteReplies($conversationKey, $emptyReplyIds);
            }
        }

        return $messages;
    }

    private function findReplySourceMessage(
        string $conversationKey,
        string $messageId,
        array $participantMap,
        int $currentUserId
    ): ?array {
        $normalizedMessageId = trim($messageId);
        if ($conversationKey === '' || $normalizedMessageId === '' || !ctype_digit($normalizedMessageId)) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT
                CAST(s.lid AS CHAR) AS id,
                COALESCE(s.lsource, \'\') AS conversation_key,
                COALESCE(s.lmessage, \'\') AS message,
                CAST(COALESCE(s.lsendfrom, \'\') AS CHAR) AS sender_id,
                CAST(COALESCE(s.lsendto, \'\') AS CHAR) AS recipient_id
             FROM tblsms s
             WHERE COALESCE(s.ltype, \'\') = :chat_type
               AND COALESCE(s.lsource, \'\') = :conversation_key
               AND s.lid = :message_id
             LIMIT 1'
        );
        $stmt->execute([
            ':chat_type' => self::CHAT_TYPE,
            ':conversation_key' => $conversationKey,
            ':message_id' => (int) $normalizedMessageId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

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
            'created_at' => '',
            'is_from_current_user' => $senderId === (string) $currentUserId,
            'sender_name' => (string) ($sender['full_name'] ?? ''),
            'recipient_name' => (string) ($recipient['full_name'] ?? ''),
            'sender_avatar_url' => (string) ($sender['avatar_url'] ?? ''),
            'recipient_avatar_url' => (string) ($recipient['avatar_url'] ?? ''),
            'delivery_status' => 'sent',
            'is_read_by_recipient' => false,
            'reactions' => [],
            'current_user_reaction' => null,
            'reply_to_message_id' => null,
            'reply_preview' => null,
        ];
    }

    private function buildReplyPreview(array $message, int $currentUserId): array
    {
        $senderId = trim((string) ($message['sender_id'] ?? ''));

        return [
            'message_id' => trim((string) ($message['id'] ?? '')),
            'sender_id' => $senderId,
            'sender_name' => trim((string) ($message['sender_name'] ?? '')) ?: ($senderId !== '' ? sprintf('User %s', $senderId) : 'Unknown User'),
            'message' => $this->buildReplySnippet((string) ($message['message'] ?? '')),
            'is_from_current_user' => $senderId === (string) $currentUserId,
            'is_available' => true,
        ];
    }

    private function buildUnavailableReplyPreview(string $messageId): array
    {
        return [
            'message_id' => trim($messageId),
            'sender_id' => '',
            'sender_name' => '',
            'message' => 'Original message unavailable',
            'is_from_current_user' => false,
            'is_available' => false,
        ];
    }

    private function buildReplySnippet(string $message): string
    {
        $preview = preg_replace('/\s+/', ' ', trim($message)) ?? '';
        if (function_exists('mb_substr')) {
            $snippet = (string) mb_substr($preview, 0, 140);
            return mb_strlen($preview) > 140 ? rtrim($snippet) . '…' : $snippet;
        }

        $snippet = substr($preview, 0, 140);
        return strlen($preview) > 140 ? rtrim($snippet) . '…' : $snippet;
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
