<?php

declare(strict_types=1);

namespace App\Support;

use App\Database;
use PDO;
use RuntimeException;

final class InternalChatReplyStore
{
    public function __construct(private readonly Database $db)
    {
    }

    public function listReplies(string $conversationKey, array $messageIds): array
    {
        $normalizedMessageIds = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $messageIds
        ), static fn (string $value): bool => $value !== '' && ctype_digit($value)));

        if ($conversationKey === '' || $normalizedMessageIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($normalizedMessageIds), '?'));
        $sql = sprintf(
            'SELECT
                CAST(message_id AS CHAR) AS message_id,
                CAST(reply_to_message_id AS CHAR) AS reply_to_message_id,
                COALESCE(DATE_FORMAT(updated_at, \'%%Y-%%m-%%d %%H:%%i:%%s\'), \'\') AS updated_at
             FROM internal_chat_message_replies
             WHERE conversation_key = ?
               AND message_id IN (%s)',
            $placeholders
        );

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$conversationKey, ...array_map('intval', $normalizedMessageIds)]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $messageId = trim((string) ($row['message_id'] ?? ''));
            $replyToMessageId = trim((string) ($row['reply_to_message_id'] ?? ''));
            if ($messageId === '' || $replyToMessageId === '') {
                continue;
            }

            $result[$messageId] = [
                'message_id' => $messageId,
                'reply_to_message_id' => $replyToMessageId,
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $result;
    }

    public function saveReply(string $conversationKey, string $messageId, string $replyToMessageId): array
    {
        $normalizedConversationKey = trim($conversationKey);
        $normalizedMessageId = trim($messageId);
        $normalizedReplyToMessageId = trim($replyToMessageId);

        if (
            $normalizedConversationKey === ''
            || $normalizedMessageId === ''
            || !ctype_digit($normalizedMessageId)
            || $normalizedReplyToMessageId === ''
            || !ctype_digit($normalizedReplyToMessageId)
        ) {
            throw new RuntimeException('Reply metadata is invalid');
        }

        $this->db->pdo()->prepare(
            'INSERT INTO internal_chat_message_replies
                (conversation_key, message_id, reply_to_message_id)
             VALUES
                (:conversation_key, :message_id, :reply_to_message_id)
             ON DUPLICATE KEY UPDATE
                conversation_key = VALUES(conversation_key),
                reply_to_message_id = VALUES(reply_to_message_id),
                updated_at = CURRENT_TIMESTAMP(3)'
        )->execute([
            ':conversation_key' => $normalizedConversationKey,
            ':message_id' => (int) $normalizedMessageId,
            ':reply_to_message_id' => (int) $normalizedReplyToMessageId,
        ]);

        return [
            'message_id' => $normalizedMessageId,
            'reply_to_message_id' => $normalizedReplyToMessageId,
            'updated_at' => date('c'),
        ];
    }

    public function deleteReplies(string $conversationKey, array $messageIds): void
    {
        $normalizedConversationKey = trim($conversationKey);
        $normalizedMessageIds = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $messageIds
        ), static fn (string $value): bool => $value !== '' && ctype_digit($value)));

        if ($normalizedConversationKey === '' || $normalizedMessageIds === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($normalizedMessageIds), '?'));
        $sql = sprintf(
            'DELETE FROM internal_chat_message_replies
             WHERE conversation_key = ?
               AND message_id IN (%s)',
            $placeholders
        );

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$normalizedConversationKey, ...array_map('intval', $normalizedMessageIds)]);
    }
}
