<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class InternalChatReplyStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function listReplies(string $conversationKey, array $messageIds): array
    {
        $normalizedMessageIds = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $messageIds
        )));

        if ($conversationKey === '' || $normalizedMessageIds === []) {
            return [];
        }

        return $this->withLockedStore(function (array &$store) use ($conversationKey, $normalizedMessageIds): array {
            $this->prune($store);
            $result = [];

            foreach ($normalizedMessageIds as $messageId) {
                $payload = $store['messages'][$conversationKey][$messageId] ?? null;
                if (!is_array($payload)) {
                    continue;
                }

                $replyToMessageId = trim((string) ($payload['reply_to_message_id'] ?? ''));
                if ($replyToMessageId === '') {
                    unset($store['messages'][$conversationKey][$messageId]);
                    continue;
                }

                $result[$messageId] = [
                    'message_id' => $messageId,
                    'reply_to_message_id' => $replyToMessageId,
                    'updated_at' => (string) ($payload['updated_at'] ?? ''),
                ];
            }

            if (($store['messages'][$conversationKey] ?? []) === []) {
                unset($store['messages'][$conversationKey]);
            }

            return $result;
        });
    }

    public function saveReply(string $conversationKey, string $messageId, string $replyToMessageId): array
    {
        $normalizedConversationKey = trim($conversationKey);
        $normalizedMessageId = trim($messageId);
        $normalizedReplyToMessageId = trim($replyToMessageId);

        if ($normalizedConversationKey === '' || $normalizedMessageId === '' || $normalizedReplyToMessageId === '') {
            throw new RuntimeException('Reply metadata is invalid');
        }

        return $this->withLockedStore(function (array &$store) use (
            $normalizedConversationKey,
            $normalizedMessageId,
            $normalizedReplyToMessageId
        ): array {
            $this->prune($store);

            $store['messages'][$normalizedConversationKey][$normalizedMessageId] = [
                'message_id' => $normalizedMessageId,
                'reply_to_message_id' => $normalizedReplyToMessageId,
                'updated_at' => date('c'),
            ];

            return $store['messages'][$normalizedConversationKey][$normalizedMessageId];
        });
    }

    public function deleteReplies(string $conversationKey, array $messageIds): void
    {
        $normalizedConversationKey = trim($conversationKey);
        $normalizedMessageIds = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $messageIds
        )));

        if ($normalizedConversationKey === '' || $normalizedMessageIds === []) {
            return;
        }

        $this->withLockedStore(function (array &$store) use ($normalizedConversationKey, $normalizedMessageIds): bool {
            $this->prune($store);

            foreach ($normalizedMessageIds as $messageId) {
                unset($store['messages'][$normalizedConversationKey][$messageId]);
            }

            if (($store['messages'][$normalizedConversationKey] ?? []) === []) {
                unset($store['messages'][$normalizedConversationKey]);
            }

            return true;
        });
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
            throw new RuntimeException('Unable to prepare internal chat reply storage');
        }

        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open internal chat reply storage');
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock internal chat reply storage');
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

            foreach ($conversation as $messageId => $payload) {
                if (!is_array($payload)) {
                    unset($store['messages'][$conversationKey][$messageId]);
                    continue;
                }

                $normalizedMessageId = trim((string) ($payload['message_id'] ?? $messageId));
                $normalizedReplyToMessageId = trim((string) ($payload['reply_to_message_id'] ?? ''));
                if ($normalizedMessageId === '' || $normalizedReplyToMessageId === '') {
                    unset($store['messages'][$conversationKey][$messageId]);
                    continue;
                }

                $store['messages'][$conversationKey][$messageId] = [
                    'message_id' => $normalizedMessageId,
                    'reply_to_message_id' => $normalizedReplyToMessageId,
                    'updated_at' => (string) ($payload['updated_at'] ?? ''),
                ];
            }

            if (($store['messages'][$conversationKey] ?? []) === []) {
                unset($store['messages'][$conversationKey]);
            }
        }
    }
}
