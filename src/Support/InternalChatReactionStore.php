<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class InternalChatReactionStore
{
    /**
     * @var string[]
     */
    private const ALLOWED_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '👀'];

    public function __construct(private readonly string $path)
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
            if ($conversationKey === '' || $messageId === '') {
                continue;
            }

            $messageIndex[$conversationKey][$messageId] = true;
        }

        return $this->withLockedStore(function (array &$store) use ($messageIndex, $currentUserId): array {
            $this->prune($store);
            $result = [];

            foreach ($messageIndex as $conversationKey => $messageIds) {
                $conversation = $store['messages'][$conversationKey] ?? [];
                foreach (array_keys($messageIds) as $messageId) {
                    $messageReactions = $conversation[$messageId] ?? [];
                    $result[$messageId] = $this->formatReactionSummary($messageReactions, $currentUserId);
                }
            }

            return $result;
        });
    }

    public function toggleReaction(string $conversationKey, string $messageId, string $userId, string $emoji): array
    {
        $normalizedEmoji = $this->normalizeReaction($emoji);
        if ($normalizedEmoji === null) {
            throw new RuntimeException('Unsupported reaction');
        }

        return $this->withLockedStore(function (array &$store) use ($conversationKey, $messageId, $userId, $normalizedEmoji): array {
            $this->prune($store);

            $conversation = $store['messages'][$conversationKey] ?? [];
            $message = $conversation[$messageId] ?? [];
            $existing = $message[$userId]['emoji'] ?? null;

            if ($existing === $normalizedEmoji) {
                unset($message[$userId]);
            } else {
                $message[$userId] = [
                    'emoji' => $normalizedEmoji,
                    'updated_at' => date('c'),
                ];
            }

            if ($message === []) {
                unset($conversation[$messageId]);
            } else {
                $conversation[$messageId] = $message;
            }

            if ($conversation === []) {
                unset($store['messages'][$conversationKey]);
            } else {
                $store['messages'][$conversationKey] = $conversation;
            }

            return $this->formatReactionSummary($message, $userId);
        });
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

    /**
     * @template T
     * @param callable(array):T $callback
     * @return T
     */
    private function withLockedStore(callable $callback): mixed
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to prepare internal chat reaction storage');
        }

        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open internal chat reaction storage');
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock internal chat reaction storage');
            }

            $contents = stream_get_contents($handle);
            $decoded = is_string($contents) && trim($contents) !== '' ? json_decode($contents, true) : null;
            $store = is_array($decoded) ? $decoded : ['messages' => []];
            $store['messages'] = is_array($store['messages'] ?? null) ? $store['messages'] : [];

            $result = $callback($store);

            rewind($handle);
            ftruncate($handle, 0);
            @fwrite($handle, (string) json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            @fflush($handle);
            @flock($handle, LOCK_UN);

            return $result;
        } finally {
            fclose($handle);
        }
    }

    private function prune(array &$store): void
    {
        foreach ($store['messages'] as $conversationKey => $conversation) {
            if (!is_array($conversation)) {
                unset($store['messages'][$conversationKey]);
                continue;
            }

            foreach ($conversation as $messageId => $message) {
                if (!is_array($message) || $message === []) {
                    unset($store['messages'][$conversationKey][$messageId]);
                }
            }

            if (($store['messages'][$conversationKey] ?? []) === []) {
                unset($store['messages'][$conversationKey]);
            }
        }
    }
}
