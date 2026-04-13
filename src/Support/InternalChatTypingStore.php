<?php

declare(strict_types=1);

namespace App\Support;

use App\Database;
use DateInterval;
use DateTimeImmutable;
use PDO;

final class InternalChatTypingStore
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return string[]
     */
    public function listTypingUserIds(string $conversationKey, ?string $excludeUserId = null): array
    {
        $normalizedConversationKey = trim($conversationKey);
        if ($normalizedConversationKey === '') {
            return [];
        }

        $this->pruneExpiredEntries();

        $sql = 'SELECT CAST(user_id AS CHAR) AS user_id
                FROM internal_chat_typing_states
                WHERE conversation_key = :conversation_key
                  AND expires_at > :now';
        $params = [
            ':conversation_key' => $normalizedConversationKey,
            ':now' => $this->currentTimestamp(),
        ];

        $normalizedExcludeUserId = trim((string) ($excludeUserId ?? ''));
        if ($normalizedExcludeUserId !== '' && ctype_digit($normalizedExcludeUserId)) {
            $sql .= ' AND user_id <> :exclude_user_id';
            $params[':exclude_user_id'] = (int) $normalizedExcludeUserId;
        }

        $sql .= ' ORDER BY user_id ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        $userIds = array_map(
            static fn (array $row): string => trim((string) ($row['user_id'] ?? '')),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
        $userIds = array_values(array_filter($userIds, static fn (string $value): bool => $value !== ''));
        sort($userIds, SORT_NATURAL);

        return array_values(array_unique($userIds));
    }

    /**
     * @return string[]
     */
    public function setTyping(string $conversationKey, string $userId, bool $isTyping, int $ttlMilliseconds = 3500): array
    {
        $normalizedConversationKey = trim($conversationKey);
        $normalizedUserId = trim($userId);
        if ($normalizedConversationKey === '' || $normalizedUserId === '' || !ctype_digit($normalizedUserId)) {
            return [];
        }

        $this->pruneExpiredEntries();

        if ($isTyping) {
            $this->db->pdo()->prepare(
                'INSERT INTO internal_chat_typing_states
                    (conversation_key, user_id, expires_at)
                 VALUES
                    (:conversation_key, :user_id, :expires_at)
                 ON DUPLICATE KEY UPDATE
                    expires_at = VALUES(expires_at),
                    updated_at = CURRENT_TIMESTAMP(3)'
            )->execute([
                ':conversation_key' => $normalizedConversationKey,
                ':user_id' => (int) $normalizedUserId,
                ':expires_at' => $this->futureTimestamp(max(250, $ttlMilliseconds)),
            ]);
        } else {
            $this->db->pdo()->prepare(
                'DELETE FROM internal_chat_typing_states
                 WHERE conversation_key = :conversation_key
                   AND user_id = :user_id'
            )->execute([
                ':conversation_key' => $normalizedConversationKey,
                ':user_id' => (int) $normalizedUserId,
            ]);
        }

        return $this->listTypingUserIds($normalizedConversationKey);
    }

    private function pruneExpiredEntries(): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM internal_chat_typing_states
             WHERE expires_at <= :now'
        )->execute([
            ':now' => $this->currentTimestamp(),
        ]);
    }

    private function currentTimestamp(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s.v');
    }

    private function futureTimestamp(int $ttlMilliseconds): string
    {
        $interval = DateInterval::createFromDateString(sprintf('%d milliseconds', max(250, $ttlMilliseconds)));
        $expiresAt = $interval instanceof DateInterval
            ? (new DateTimeImmutable())->add($interval)
            : (new DateTimeImmutable())->modify('+1 second');

        return ($expiresAt ?: new DateTimeImmutable())->format('Y-m-d H:i:s.v');
    }
}
