<?php

declare(strict_types=1);

namespace App\Support;

use App\Database;
use PDO;
use RuntimeException;

final class InternalChatReactionStore
{
    /**
     * @var string[]
     */
    private const ALLOWED_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '👀'];

    public function __construct(private readonly Database $db)
    {
    }

    public function summarizeMessages(array $messages, string $currentUserId): array
    {
        if ($messages === []) {
            return [];
        }

        $messageIndex = [];
        foreach ($messages as $message) {
            $conversationKey = trim((string) ($message['conversation_key'] ?? ''));
            $messageId = trim((string) ($message['id'] ?? ''));
            if ($conversationKey === '' || $messageId === '' || !ctype_digit($messageId)) {
                continue;
            }

            $messageIndex[$conversationKey][$messageId] = true;
        }

        $result = [];
        foreach ($messageIndex as $conversationKey => $messageIds) {
            $result += $this->summarizeConversationMessages($conversationKey, array_keys($messageIds), $currentUserId);
        }

        return $result;
    }

    public function toggleReaction(string $conversationKey, string $messageId, string $userId, string $emoji): array
    {
        $normalizedConversationKey = trim($conversationKey);
        $normalizedMessageId = trim($messageId);
        $normalizedUserId = trim($userId);
        $normalizedEmoji = $this->normalizeReaction($emoji);
        if (
            $normalizedConversationKey === ''
            || $normalizedMessageId === ''
            || !ctype_digit($normalizedMessageId)
            || $normalizedUserId === ''
            || !ctype_digit($normalizedUserId)
            || $normalizedEmoji === null
        ) {
            throw new RuntimeException('Unsupported reaction');
        }

        $pdo = $this->db->pdo();
        $startedTransaction = false;

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $existingStmt = $pdo->prepare(
                'SELECT emoji
                 FROM internal_chat_message_reactions
                 WHERE message_id = :message_id
                   AND user_id = :user_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $existingStmt->execute([
                ':message_id' => (int) $normalizedMessageId,
                ':user_id' => (int) $normalizedUserId,
            ]);

            $existingEmoji = trim((string) ($existingStmt->fetchColumn() ?: ''));
            if ($existingEmoji === $normalizedEmoji) {
                $pdo->prepare(
                    'DELETE FROM internal_chat_message_reactions
                     WHERE message_id = :message_id
                       AND user_id = :user_id'
                )->execute([
                    ':message_id' => (int) $normalizedMessageId,
                    ':user_id' => (int) $normalizedUserId,
                ]);
            } else {
                $pdo->prepare(
                    'INSERT INTO internal_chat_message_reactions
                        (conversation_key, message_id, user_id, emoji)
                     VALUES
                        (:conversation_key, :message_id, :user_id, :emoji)
                     ON DUPLICATE KEY UPDATE
                        conversation_key = VALUES(conversation_key),
                        emoji = VALUES(emoji),
                        updated_at = CURRENT_TIMESTAMP(3)'
                )->execute([
                    ':conversation_key' => $normalizedConversationKey,
                    ':message_id' => (int) $normalizedMessageId,
                    ':user_id' => (int) $normalizedUserId,
                    ':emoji' => $normalizedEmoji,
                ]);
            }

            $summary = $this->summarizeConversationMessages(
                $normalizedConversationKey,
                [$normalizedMessageId],
                $normalizedUserId
            )[$normalizedMessageId] ?? [
                'reactions' => [],
                'current_user_reaction' => null,
            ];

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return $summary;
        } catch (\Throwable $error) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $error;
        }
    }

    /**
     * @return string[]
     */
    public function allowedReactions(): array
    {
        return self::ALLOWED_REACTIONS;
    }

    private function normalizeReaction(string $emoji): ?string
    {
        $trimmed = trim($emoji);
        if ($trimmed === '') {
            return null;
        }

        return in_array($trimmed, self::ALLOWED_REACTIONS, true) ? $trimmed : null;
    }

    private function formatReactionSummary(array $messageReactions, string $currentUserId): array
    {
        $counts = [];
        $currentUserReaction = null;

        foreach ($messageReactions as $userId => $payload) {
            $emoji = trim((string) ($payload['emoji'] ?? ''));
            if ($emoji === '') {
                continue;
            }

            if (!isset($counts[$emoji])) {
                $counts[$emoji] = [
                    'emoji' => $emoji,
                    'count' => 0,
                    'reacted_by_current_user' => false,
                ];
            }

            $counts[$emoji]['count']++;
            if ((string) $userId === $currentUserId) {
                $counts[$emoji]['reacted_by_current_user'] = true;
                $currentUserReaction = $emoji;
            }
        }

        usort($counts, static function (array $left, array $right): int {
            if (($left['count'] ?? 0) !== ($right['count'] ?? 0)) {
                return (int) ($right['count'] ?? 0) <=> (int) ($left['count'] ?? 0);
            }

            return strcmp((string) ($left['emoji'] ?? ''), (string) ($right['emoji'] ?? ''));
        });

        return [
            'reactions' => array_values($counts),
            'current_user_reaction' => $currentUserReaction,
        ];
    }

    private function summarizeConversationMessages(string $conversationKey, array $messageIds, string $currentUserId): array
    {
        $normalizedMessageIds = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $messageIds
        ), static fn (string $value): bool => $value !== '' && ctype_digit($value)));

        if ($conversationKey === '' || $normalizedMessageIds === []) {
            return [];
        }

        $result = [];
        foreach ($normalizedMessageIds as $messageId) {
            $result[$messageId] = [
                'reactions' => [],
                'current_user_reaction' => null,
            ];
        }

        $placeholders = implode(', ', array_fill(0, count($normalizedMessageIds), '?'));
        $sql = sprintf(
            'SELECT
                CAST(message_id AS CHAR) AS message_id,
                CAST(user_id AS CHAR) AS user_id,
                emoji
             FROM internal_chat_message_reactions
             WHERE conversation_key = ?
               AND message_id IN (%s)',
            $placeholders
        );

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$conversationKey, ...array_map('intval', $normalizedMessageIds)]);

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $messageId = trim((string) ($row['message_id'] ?? ''));
            $userId = trim((string) ($row['user_id'] ?? ''));
            $emoji = trim((string) ($row['emoji'] ?? ''));
            if ($messageId === '' || $userId === '' || $emoji === '') {
                continue;
            }

            $grouped[$messageId][$userId] = ['emoji' => $emoji];
        }

        foreach ($grouped as $messageId => $messageReactions) {
            $result[$messageId] = $this->formatReactionSummary($messageReactions, $currentUserId);
        }

        return $result;
    }
}
