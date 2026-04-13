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
    private const CONVERSATION_TYPE_DIRECT = 'direct';
    private const CONVERSATION_TYPE_GROUP = 'group';

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

        $conversations = array_merge(
            $this->listDirectConversationSummaries($mainId, $currentUserId, $participantMap, $unreadByConversation),
            $this->listGroupConversationSummaries($mainId, $currentUserId, $participantMap, $unreadByConversation)
        );

        usort($conversations, function (array $left, array $right): int {
            $leftUnread = (int) ($left['unread_count'] ?? 0);
            $rightUnread = (int) ($right['unread_count'] ?? 0);
            if ($leftUnread !== $rightUnread) {
                return $rightUnread <=> $leftUnread;
            }

            $leftTime = strtotime((string) ($left['last_message_at'] ?? '')) ?: 0;
            $rightTime = strtotime((string) ($right['last_message_at'] ?? '')) ?: 0;
            if ($leftTime !== $rightTime) {
                return $rightTime <=> $leftTime;
            }

            return strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        });

        return array_values($conversations);
    }

    public function listMessages(int $mainId, int $currentUserId, string $conversationKey): array
    {
        $this->assertConversationAccess($mainId, $currentUserId, $conversationKey);

        $participantMap = $this->participantMapForMain($mainId);

        if ($this->isGroupConversationKey($conversationKey)) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT
                    CAST(s.lid AS CHAR) AS id,
                    COALESCE(s.lsource, \'\') AS conversation_key,
                    COALESCE(s.lmessage, \'\') AS message,
                    COALESCE(DATE_FORMAT(s.ldatetime, \'%Y-%m-%d %H:%i:%s\'), \'\') AS created_at,
                    CAST(COALESCE(s.lsendfrom, \'\') AS CHAR) AS sender_id,
                    CAST(COALESCE(s.lsendto, \'\') AS CHAR) AS recipient_id
                 FROM tblsms s
                 WHERE COALESCE(s.ltype, \'\') = :chat_type
                   AND COALESCE(s.lsource, \'\') = :conversation_key
                 ORDER BY s.ldatetime ASC, s.lid ASC'
            );
            $stmt->execute([
                ':chat_type' => self::CHAT_TYPE,
                ':conversation_key' => $conversationKey,
            ]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $messages = array_map(function (array $row) use ($currentUserId, $participantMap): array {
                $senderId = trim((string) ($row['sender_id'] ?? ''));
                $recipientId = trim((string) ($row['recipient_id'] ?? ''));
                $sender = $participantMap[$senderId] ?? $this->fallbackParticipant($senderId);
                $recipient = $participantMap[$recipientId] ?? $this->fallbackParticipant($recipientId);

                return [
                    'id' => (string) ($row['id'] ?? ''),
                    'conversation_key' => (string) ($row['conversation_key'] ?? ''),
                    'conversation_type' => self::CONVERSATION_TYPE_GROUP,
                    'sender_id' => $senderId,
                    'recipient_id' => $recipientId,
                    'message' => (string) ($row['message'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'is_from_current_user' => $senderId === (string) $currentUserId,
                    'sender_name' => (string) ($sender['full_name'] ?? ''),
                    'recipient_name' => (string) ($recipient['full_name'] ?? ''),
                    'sender_avatar_url' => (string) ($sender['avatar_url'] ?? ''),
                    'recipient_avatar_url' => (string) ($recipient['avatar_url'] ?? ''),
                    'delivery_status' => 'delivered',
                    'is_read_by_recipient' => false,
                    'reactions' => [],
                    'current_user_reaction' => null,
                    'reply_to_message_id' => null,
                    'reply_preview' => null,
                ];
            }, $rows);
        } else {
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
                    'conversation_type' => self::CONVERSATION_TYPE_DIRECT,
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
        }

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
        ?string $replyToMessageId = null,
        ?string $conversationKey = null
    ): array {
        $cleanMessage = trim($message);
        if ($cleanMessage === '') {
            throw new RuntimeException('Message is required');
        }

        $normalizedReplyToMessageId = trim((string) ($replyToMessageId ?? ''));
        $participantMap = $this->participantMapForMain($mainId);
        $sender = $participantMap[(string) $senderUserId] ?? $this->fallbackParticipant((string) $senderUserId);
        $sentAt = date('Y-m-d H:i:s');
        $preview = $this->buildPreview($cleanMessage);
        $created = [];
        $replyWrites = [];
        $pdo = $this->db->pdo();

        if ($conversationKey !== null && $this->isGroupConversationKey($conversationKey)) {
            $groupMeta = $this->requireGroupAccess($mainId, $senderUserId, $conversationKey);
            $groupParticipantIds = $this->activeGroupMemberIds((int) ($groupMeta['id'] ?? 0));

            if ($normalizedReplyToMessageId !== '') {
                $replyTarget = $this->getMessageForUser($mainId, $senderUserId, $normalizedReplyToMessageId);
                if ((string) ($replyTarget['conversation_key'] ?? '') !== $conversationKey) {
                    throw new RuntimeException('Reply target must belong to the same conversation');
                }
            }

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

                $smsStmt->execute([
                    ':recipient_id_to' => '',
                    ':message' => $cleanMessage,
                    ':sent_at' => $sentAt,
                    ':status' => 'sent',
                    ':chat_type' => self::CHAT_TYPE,
                    ':conversation_key' => $conversationKey,
                    ':sender_id' => (string) $senderUserId,
                    ':recipient_id_sendto' => '',
                ]);

                $messageId = (string) $pdo->lastInsertId();
                foreach ($groupParticipantIds as $recipientId) {
                    if ($recipientId === (string) $senderUserId) {
                        continue;
                    }

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
                }

                $pdo->prepare(
                    'UPDATE internal_chat_groups
                     SET updated_at = :updated_at
                     WHERE id = :group_id'
                )->execute([
                    ':updated_at' => $sentAt,
                    ':group_id' => (int) ($groupMeta['id'] ?? 0),
                ]);

                $created[] = [
                    'id' => $messageId,
                    'conversation_key' => $conversationKey,
                    'conversation_type' => self::CONVERSATION_TYPE_GROUP,
                    'sender_id' => (string) $senderUserId,
                    'recipient_id' => '',
                    'message' => $cleanMessage,
                    'created_at' => $sentAt,
                    'is_from_current_user' => true,
                    'sender_name' => (string) ($sender['full_name'] ?? ''),
                    'recipient_name' => '',
                    'sender_avatar_url' => (string) ($sender['avatar_url'] ?? ''),
                    'recipient_avatar_url' => '',
                    'delivery_status' => 'delivered',
                    'is_read_by_recipient' => false,
                    'reactions' => [],
                    'current_user_reaction' => null,
                    'reply_to_message_id' => null,
                    'reply_preview' => null,
                    'target_user_ids' => $groupParticipantIds,
                ];

                if ($normalizedReplyToMessageId !== '' && $this->replyStore !== null) {
                    $this->replyStore->saveReply($conversationKey, $messageId, $normalizedReplyToMessageId);
                    $replyWrites[] = [
                        'conversation_key' => $conversationKey,
                        'message_id' => $messageId,
                    ];
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
                $directConversationKey = self::buildConversationKey($senderUserId, (int) $recipientId);

                $smsStmt->execute([
                    ':recipient_id_to' => $recipientId,
                    ':message' => $cleanMessage,
                    ':sent_at' => $sentAt,
                    ':status' => 'sent',
                    ':chat_type' => self::CHAT_TYPE,
                    ':conversation_key' => $directConversationKey,
                    ':sender_id' => (string) $senderUserId,
                    ':recipient_id_sendto' => $recipientId,
                ]);

                $messageId = (string) $pdo->lastInsertId();

                $alertStmt->execute([
                    ':main_id' => (string) $mainId,
                    ':message_id' => $messageId,
                    ':conversation_key' => $directConversationKey,
                    ':recipient_id' => $recipientId,
                    ':unread_status' => self::STATUS_UNREAD,
                    ':preview' => $preview,
                    ':sender_id' => (string) $senderUserId,
                    ':sent_at' => $sentAt,
                    ':chat_type' => self::CHAT_TYPE,
                ]);

                $created[] = [
                    'id' => $messageId,
                    'conversation_key' => $directConversationKey,
                    'conversation_type' => self::CONVERSATION_TYPE_DIRECT,
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
                    'target_user_ids' => [(string) $senderUserId, $recipientId],
                ];

                if ($normalizedReplyToMessageId !== '' && $this->replyStore !== null) {
                    $this->replyStore->saveReply($directConversationKey, $messageId, $normalizedReplyToMessageId);
                    $replyWrites[] = [
                        'conversation_key' => $directConversationKey,
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

    public function createGroup(int $mainId, int $currentUserId, string $name, array $memberIds): array
    {
        $normalizedName = trim($name);
        if ($normalizedName === '') {
            throw new RuntimeException('Group name is required');
        }

        $normalizedMembers = $this->normalizeGroupMemberIds($mainId, $currentUserId, $memberIds);
        if (count($normalizedMembers) < 2) {
            throw new RuntimeException('Add at least 2 staff members to create a group');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $groupStmt = $pdo->prepare(
                'INSERT INTO internal_chat_groups
                    (main_id, name, created_by_user_id, created_at, updated_at)
                 VALUES
                    (:main_id, :name, :created_by_user_id, :created_at, :updated_at)'
            );
            $memberStmt = $pdo->prepare(
                'INSERT INTO internal_chat_group_members
                    (group_id, user_id, added_by_user_id, created_at, removed_at)
                 VALUES
                    (:group_id, :user_id, :added_by_user_id, :created_at, NULL)'
            );

            $timestamp = date('Y-m-d H:i:s');
            $groupStmt->execute([
                ':main_id' => $mainId,
                ':name' => $normalizedName,
                ':created_by_user_id' => $currentUserId,
                ':created_at' => $timestamp,
                ':updated_at' => $timestamp,
            ]);

            $groupId = (int) $pdo->lastInsertId();
            $allMemberIds = array_merge([(string) $currentUserId], $normalizedMembers);
            foreach ($allMemberIds as $memberId) {
                $memberStmt->execute([
                    ':group_id' => $groupId,
                    ':user_id' => (int) $memberId,
                    ':added_by_user_id' => $currentUserId,
                    ':created_at' => $timestamp,
                ]);
            }

            $pdo->commit();
            return $this->getGroupDetails($mainId, $currentUserId, $groupId);
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function getGroupDetails(int $mainId, int $currentUserId, int $groupId): array
    {
        $groupMeta = $this->requireGroupAccess($mainId, $currentUserId, self::buildGroupConversationKey($groupId));
        $participantMap = $this->participantMapForMain($mainId);
        $members = $this->activeGroupMembers((int) ($groupMeta['id'] ?? 0), $participantMap);

        return [
            'id' => (string) ($groupMeta['id'] ?? ''),
            'conversation_key' => self::buildGroupConversationKey((int) ($groupMeta['id'] ?? 0)),
            'conversation_type' => self::CONVERSATION_TYPE_GROUP,
            'name' => (string) ($groupMeta['name'] ?? ''),
            'title' => (string) ($groupMeta['name'] ?? ''),
            'subtitle' => $this->buildGroupSubtitle(count($members)),
            'avatar_label' => $this->buildAvatarLabel((string) ($groupMeta['name'] ?? ''), count($members)),
            'member_count' => count($members),
            'created_by_user_id' => (string) ($groupMeta['created_by_user_id'] ?? ''),
            'can_manage' => (string) ($groupMeta['created_by_user_id'] ?? '') === (string) $currentUserId,
            'members' => $members,
        ];
    }

    public function renameGroup(int $mainId, int $currentUserId, int $groupId, string $name): array
    {
        $normalizedName = trim($name);
        if ($normalizedName === '') {
            throw new RuntimeException('Group name is required');
        }

        $this->requireGroupCreator($mainId, $currentUserId, $groupId);
        $this->db->pdo()->prepare(
            'UPDATE internal_chat_groups
             SET name = :name, updated_at = :updated_at
             WHERE id = :group_id'
        )->execute([
            ':name' => $normalizedName,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':group_id' => $groupId,
        ]);

        return $this->getGroupDetails($mainId, $currentUserId, $groupId);
    }

    public function addGroupMembers(int $mainId, int $currentUserId, int $groupId, array $memberIds): array
    {
        $groupMeta = $this->requireGroupCreator($mainId, $currentUserId, $groupId);
        $normalizedMembers = $this->normalizeGroupMemberIds($mainId, $currentUserId, $memberIds);
        if ($normalizedMembers === []) {
            throw new RuntimeException('At least one valid staff member is required');
        }

        $activeMembers = array_fill_keys($this->activeGroupMemberIds($groupId), true);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO internal_chat_group_members
                (group_id, user_id, added_by_user_id, created_at, removed_at)
             VALUES
                (:group_id, :user_id, :added_by_user_id, :created_at, NULL)'
        );

        $timestamp = date('Y-m-d H:i:s');
        $added = 0;
        foreach ($normalizedMembers as $memberId) {
            if (isset($activeMembers[$memberId])) {
                continue;
            }

            $stmt->execute([
                ':group_id' => $groupId,
                ':user_id' => (int) $memberId,
                ':added_by_user_id' => $currentUserId,
                ':created_at' => $timestamp,
            ]);
            $added++;
        }

        if ($added === 0) {
            throw new RuntimeException('Selected staff members are already in the group');
        }

        $this->touchGroupUpdatedAt((int) ($groupMeta['id'] ?? $groupId));
        return $this->getGroupDetails($mainId, $currentUserId, $groupId);
    }

    public function removeGroupMember(int $mainId, int $currentUserId, int $groupId, string $memberUserId): array
    {
        $groupMeta = $this->requireGroupCreator($mainId, $currentUserId, $groupId);
        $normalizedMemberUserId = trim($memberUserId);
        if ($normalizedMemberUserId === '' || !ctype_digit($normalizedMemberUserId)) {
            throw new RuntimeException('Member user is required');
        }
        if ($normalizedMemberUserId === (string) $currentUserId) {
            throw new RuntimeException('Group creator cannot remove themselves');
        }

        $allowedParticipants = array_fill_keys($this->participantIdsForMain($mainId), true);
        if (!isset($allowedParticipants[$normalizedMemberUserId])) {
            throw new RuntimeException('Staff member is not part of this account');
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE internal_chat_group_members
             SET removed_at = :removed_at
             WHERE group_id = :group_id
               AND user_id = :user_id
               AND removed_at IS NULL'
        );
        $stmt->execute([
            ':removed_at' => date('Y-m-d H:i:s'),
            ':group_id' => $groupId,
            ':user_id' => (int) $normalizedMemberUserId,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Member not found in this group');
        }

        $this->clearConversationAlertsForUser(
            self::buildGroupConversationKey($groupId),
            $normalizedMemberUserId
        );
        $this->touchGroupUpdatedAt((int) ($groupMeta['id'] ?? $groupId));
        return $this->getGroupDetails($mainId, $currentUserId, $groupId);
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
        $this->conversationParticipantIds($mainId, $currentUserId, $conversationKey);
    }

    public function conversationParticipantIds(int $mainId, int $currentUserId, string $conversationKey): array
    {
        if ($this->isGroupConversationKey($conversationKey)) {
            return $this->activeGroupMemberIdsFromMeta(
                $this->requireGroupAccess($mainId, $currentUserId, $conversationKey)
            );
        }

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

        return $participantIds;
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
             LIMIT 1'
        );
        $stmt->execute([
            ':chat_type' => self::CHAT_TYPE,
            ':message_id' => (int) $normalizedId,
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
             FROM tblusers_alerts ua
             WHERE COALESCE(ua.ltype, \'\') = :chat_type
               AND COALESCE(ua.luserid, \'\') = :current_user_id
               AND COALESCE(ua.lstatus, 0) = :unread_status
               AND (
                 COALESCE(ua.lrefno, \'\') NOT REGEXP :group_key_pattern
                 OR EXISTS (
                   SELECT 1
                   FROM internal_chat_group_members gm
                   WHERE gm.group_id = CAST(SUBSTRING_INDEX(COALESCE(ua.lrefno, \'\'), \':\', -1) AS UNSIGNED)
                     AND gm.user_id = :current_user_id_int
                     AND gm.removed_at IS NULL
                 )
               )'
        );
        $stmt->execute([
            ':chat_type' => self::CHAT_TYPE,
            ':current_user_id' => (string) $currentUserId,
            ':unread_status' => self::STATUS_UNREAD,
            ':group_key_pattern' => '^grp:[0-9]+$',
            ':current_user_id_int' => $currentUserId,
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

    public static function buildGroupConversationKey(int $groupId): string
    {
        return sprintf('grp:%d', $groupId);
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
            'conversation_type' => $this->isGroupConversationKey((string) ($row['conversation_key'] ?? ''))
                ? self::CONVERSATION_TYPE_GROUP
                : self::CONVERSATION_TYPE_DIRECT,
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

    private function listDirectConversationSummaries(
        int $mainId,
        int $currentUserId,
        array $participantMap,
        array $unreadByConversation
    ): array {
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
            if ($conversationKey === '' || isset($conversations[$conversationKey])) {
                continue;
            }

            $senderId = trim((string) ($row['sender_id'] ?? ''));
            $recipientId = trim((string) ($row['recipient_id'] ?? ''));
            $otherParticipantId = $senderId === (string) $currentUserId ? $recipientId : $senderId;
            if ($otherParticipantId === '' || !isset($participantMap[$otherParticipantId])) {
                continue;
            }

            $otherParticipant = $participantMap[$otherParticipantId];
            $conversations[$conversationKey] = [
                'conversation_key' => $conversationKey,
                'conversation_type' => self::CONVERSATION_TYPE_DIRECT,
                'title' => (string) ($otherParticipant['full_name'] ?? ''),
                'subtitle' => $this->buildDirectSubtitle($otherParticipant),
                'avatar_label' => $this->buildAvatarLabel((string) ($otherParticipant['full_name'] ?? '')),
                'member_count' => 2,
                'can_manage' => false,
                'other_participant' => $otherParticipant,
                'last_message_preview' => $this->buildPreview((string) ($row['message'] ?? '')),
                'last_message_at' => (string) ($row['created_at'] ?? ''),
                'unread_count' => $unreadByConversation[$conversationKey] ?? 0,
            ];
        }

        return array_values($conversations);
    }

    private function listGroupConversationSummaries(
        int $mainId,
        int $currentUserId,
        array $participantMap,
        array $unreadByConversation
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                CAST(g.id AS CHAR) AS id,
                g.name,
                CAST(g.created_by_user_id AS CHAR) AS created_by_user_id,
                COALESCE(DATE_FORMAT(g.created_at, \'%Y-%m-%d %H:%i:%s\'), \'\') AS created_at,
                COUNT(gm_all.id) AS member_count
             FROM internal_chat_groups g
             INNER JOIN internal_chat_group_members gm_self
               ON gm_self.group_id = g.id
              AND gm_self.user_id = :current_user_id
              AND gm_self.removed_at IS NULL
             INNER JOIN internal_chat_group_members gm_all
               ON gm_all.group_id = g.id
              AND gm_all.removed_at IS NULL
             WHERE g.main_id = :main_id
             GROUP BY g.id, g.name, g.created_by_user_id, g.created_at
             ORDER BY g.updated_at DESC, g.id DESC'
        );
        $stmt->execute([
            ':current_user_id' => $currentUserId,
            ':main_id' => $mainId,
        ]);

        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($groups === []) {
            return [];
        }

        $conversationKeys = array_map(
            static fn (array $group): string => self::buildGroupConversationKey((int) ($group['id'] ?? 0)),
            $groups
        );
        $latestMessages = $this->latestMessagesForConversationKeys($conversationKeys);
        $summaries = [];

        foreach ($groups as $group) {
            $groupId = (int) ($group['id'] ?? 0);
            $conversationKey = self::buildGroupConversationKey($groupId);
            $memberCount = (int) ($group['member_count'] ?? 0);
            $latestMessage = $latestMessages[$conversationKey] ?? null;

            $summaries[] = [
                'conversation_key' => $conversationKey,
                'conversation_type' => self::CONVERSATION_TYPE_GROUP,
                'title' => trim((string) ($group['name'] ?? '')) ?: sprintf('Group %d', $groupId),
                'subtitle' => $this->buildGroupSubtitle($memberCount),
                'avatar_label' => $this->buildAvatarLabel((string) ($group['name'] ?? ''), $memberCount),
                'member_count' => $memberCount,
                'can_manage' => trim((string) ($group['created_by_user_id'] ?? '')) === (string) $currentUserId,
                'other_participant' => null,
                'last_message_preview' => $this->buildPreview((string) ($latestMessage['message'] ?? '')),
                'last_message_at' => (string) ($latestMessage['created_at'] ?? $group['created_at'] ?? ''),
                'unread_count' => $unreadByConversation[$conversationKey] ?? 0,
            ];
        }

        return $summaries;
    }

    private function latestMessagesForConversationKeys(array $conversationKeys): array
    {
        $normalizedKeys = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $conversationKeys
        )));

        if ($normalizedKeys === []) {
            return [];
        }

        $placeholders = [];
        $params = [
            ':chat_type' => self::CHAT_TYPE,
        ];

        foreach ($normalizedKeys as $index => $conversationKey) {
            $placeholder = ':conversation_key_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $conversationKey;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT
                CAST(lid AS CHAR) AS id,
                COALESCE(lsource, \'\') AS conversation_key,
                COALESCE(lmessage, \'\') AS message,
                COALESCE(DATE_FORMAT(ldatetime, \'%Y-%m-%d %H:%i:%s\'), \'\') AS created_at
             FROM tblsms
             WHERE COALESCE(ltype, \'\') = :chat_type
               AND COALESCE(lsource, \'\') IN (' . implode(', ', $placeholders) . ')
             ORDER BY ldatetime DESC, lid DESC'
        );
        $stmt->execute($params);

        $latest = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $conversationKey = trim((string) ($row['conversation_key'] ?? ''));
            if ($conversationKey === '' || isset($latest[$conversationKey])) {
                continue;
            }

            $latest[$conversationKey] = $row;
        }

        return $latest;
    }

    private function unreadCountsByConversation(int $currentUserId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                COALESCE(ua.lrefno, \'\') AS conversation_key,
                COUNT(*) AS unread_count
             FROM tblusers_alerts ua
             WHERE COALESCE(ua.ltype, \'\') = :chat_type
               AND COALESCE(ua.luserid, \'\') = :current_user_id
               AND COALESCE(ua.lstatus, 0) = :unread_status
               AND (
                 COALESCE(ua.lrefno, \'\') NOT REGEXP :group_key_pattern
                 OR EXISTS (
                   SELECT 1
                   FROM internal_chat_group_members gm
                   WHERE gm.group_id = CAST(SUBSTRING_INDEX(COALESCE(ua.lrefno, \'\'), \':\', -1) AS UNSIGNED)
                     AND gm.user_id = :current_user_id_int
                     AND gm.removed_at IS NULL
                 )
               )
             GROUP BY COALESCE(ua.lrefno, \'\')'
        );
        $stmt->execute([
            ':chat_type' => self::CHAT_TYPE,
            ':current_user_id' => (string) $currentUserId,
            ':unread_status' => self::STATUS_UNREAD,
            ':group_key_pattern' => '^grp:[0-9]+$',
            ':current_user_id_int' => $currentUserId,
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

    private function normalizeGroupMemberIds(int $mainId, int $currentUserId, array $memberIds): array
    {
        $allowedParticipants = array_fill_keys($this->participantIdsForMain($mainId), true);
        $normalized = [];

        foreach ($memberIds as $memberId) {
            $candidate = trim((string) $memberId);
            if (
                $candidate === ''
                || $candidate === (string) $currentUserId
                || !isset($allowedParticipants[$candidate])
            ) {
                continue;
            }

            $normalized[$candidate] = true;
        }

        return array_values(array_keys($normalized));
    }

    private function requireGroupAccess(int $mainId, int $currentUserId, string $conversationKey): array
    {
        $groupId = $this->parseGroupIdFromConversationKey($conversationKey);
        if ($groupId === null) {
            throw new RuntimeException('Conversation not found');
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT
                CAST(g.id AS CHAR) AS id,
                g.name,
                CAST(g.main_id AS CHAR) AS main_id,
                CAST(g.created_by_user_id AS CHAR) AS created_by_user_id
             FROM internal_chat_groups g
             INNER JOIN internal_chat_group_members gm
               ON gm.group_id = g.id
              AND gm.user_id = :current_user_id
              AND gm.removed_at IS NULL
             WHERE g.id = :group_id
               AND g.main_id = :main_id
             LIMIT 1'
        );
        $stmt->execute([
            ':current_user_id' => $currentUserId,
            ':group_id' => $groupId,
            ':main_id' => $mainId,
        ]);

        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($group)) {
            throw new RuntimeException('Conversation not found');
        }

        return $group;
    }

    private function requireGroupCreator(int $mainId, int $currentUserId, int $groupId): array
    {
        $group = $this->requireGroupAccess($mainId, $currentUserId, self::buildGroupConversationKey($groupId));
        if (trim((string) ($group['created_by_user_id'] ?? '')) !== (string) $currentUserId) {
            throw new RuntimeException('Only the group creator can manage this group');
        }

        return $group;
    }

    private function activeGroupMemberIdsFromMeta(array $groupMeta): array
    {
        return $this->activeGroupMemberIds((int) ($groupMeta['id'] ?? 0));
    }

    private function activeGroupMemberIds(int $groupId): array
    {
        if ($groupId <= 0) {
            return [];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT CAST(user_id AS CHAR) AS user_id
             FROM internal_chat_group_members
             WHERE group_id = :group_id
               AND removed_at IS NULL
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([
            ':group_id' => $groupId,
        ]);

        return array_values(array_unique(array_map(
            static fn (array $row): string => trim((string) ($row['user_id'] ?? '')),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        )));
    }

    private function activeGroupMembers(int $groupId, array $participantMap): array
    {
        $members = [];
        foreach ($this->activeGroupMemberIds($groupId) as $memberId) {
            $members[] = $participantMap[$memberId] ?? $this->fallbackParticipant($memberId);
        }

        usort($members, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['full_name'] ?? ''), (string) ($right['full_name'] ?? ''));
        });

        return $members;
    }

    private function touchGroupUpdatedAt(int $groupId): void
    {
        if ($groupId <= 0) {
            return;
        }

        $this->db->pdo()->prepare(
            'UPDATE internal_chat_groups
             SET updated_at = :updated_at
             WHERE id = :group_id'
        )->execute([
            ':updated_at' => date('Y-m-d H:i:s'),
            ':group_id' => $groupId,
        ]);
    }

    private function clearConversationAlertsForUser(string $conversationKey, string $userId): void
    {
        $normalizedConversationKey = trim($conversationKey);
        $normalizedUserId = trim($userId);
        if ($normalizedConversationKey === '' || $normalizedUserId === '' || !ctype_digit($normalizedUserId)) {
            return;
        }

        $this->db->pdo()->prepare(
            'DELETE FROM tblusers_alerts
             WHERE COALESCE(ltype, \'\') = :chat_type
               AND COALESCE(lrefno, \'\') = :conversation_key
               AND COALESCE(luserid, \'\') = :user_id'
        )->execute([
            ':chat_type' => self::CHAT_TYPE,
            ':conversation_key' => $normalizedConversationKey,
            ':user_id' => $normalizedUserId,
        ]);
    }

    private function isGroupConversationKey(string $conversationKey): bool
    {
        return $this->parseGroupIdFromConversationKey($conversationKey) !== null;
    }

    private function parseGroupIdFromConversationKey(string $conversationKey): ?int
    {
        if (!preg_match('/^grp:(\d+)$/', trim($conversationKey), $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function buildAvatarLabel(string $label, int $memberCount = 0): string
    {
        $trimmed = trim($label);
        if ($trimmed === '') {
            return $memberCount > 0 ? sprintf('%dP', $memberCount) : 'U';
        }

        $parts = preg_split('/\s+/', $trimmed) ?: [];
        $initials = '';
        foreach (array_slice(array_filter($parts), 0, 2) as $part) {
            $initials .= strtoupper((string) substr((string) $part, 0, 1));
        }

        return $initials !== '' ? $initials : ($memberCount > 0 ? sprintf('%dP', $memberCount) : 'U');
    }

    private function buildDirectSubtitle(array $participant): string
    {
        $role = trim((string) ($participant['role'] ?? ''));
        $email = trim((string) ($participant['email'] ?? ''));
        if ($role !== '' && $email !== '') {
            return $role . ' • ' . $email;
        }
        if ($role !== '') {
            return $role;
        }

        return $email;
    }

    private function buildGroupSubtitle(int $memberCount): string
    {
        return sprintf('%d member%s', $memberCount, $memberCount === 1 ? '' : 's');
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
