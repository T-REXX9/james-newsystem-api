<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class InternalChatTypingStore
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @return string[]
     */
    public function listTypingUserIds(string $conversationKey, ?string $excludeUserId = null): array
    {
        return $this->withLockedStore(function (array &$store) use ($conversationKey, $excludeUserId): array {
            $this->prune($store);
            $conversation = $store['conversations'][$conversationKey] ?? [];
            $userIds = [];

            foreach ($conversation as $userId => $payload) {
                $normalizedUserId = trim((string) $userId);
                if ($normalizedUserId === '') {
                    continue;
                }
                if ($excludeUserId !== null && $normalizedUserId === $excludeUserId) {
                    continue;
                }
                $userIds[] = $normalizedUserId;
            }

            sort($userIds, SORT_NATURAL);
            return array_values(array_unique($userIds));
        });
    }

    /**
     * @return string[]
     */
    public function setTyping(string $conversationKey, string $userId, bool $isTyping, int $ttlMilliseconds = 3500): array
    {
        return $this->withLockedStore(function (array &$store) use ($conversationKey, $userId, $isTyping, $ttlMilliseconds): array {
            $this->prune($store);

            $conversation = $store['conversations'][$conversationKey] ?? [];
            if ($isTyping) {
                $expiresAtMs = $this->nowMs() + max(250, $ttlMilliseconds);
                $conversation[$userId] = [
                    'expires_at' => (int) floor($expiresAtMs / 1000),
                    'expires_at_ms' => $expiresAtMs,
                ];
            } else {
                unset($conversation[$userId]);
            }

            if ($conversation === []) {
                unset($store['conversations'][$conversationKey]);
            } else {
                $store['conversations'][$conversationKey] = $conversation;
            }

            $activeIds = [];
            foreach ($store['conversations'][$conversationKey] ?? [] as $activeUserId => $payload) {
                $normalizedUserId = trim((string) $activeUserId);
                if ($normalizedUserId !== '') {
                    $activeIds[] = $normalizedUserId;
                }
            }

            sort($activeIds, SORT_NATURAL);
            return array_values(array_unique($activeIds));
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
            throw new RuntimeException('Unable to prepare internal chat typing storage');
        }

        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open internal chat typing storage');
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock internal chat typing storage');
            }

            $contents = stream_get_contents($handle);
            $decoded = is_string($contents) && trim($contents) !== '' ? json_decode($contents, true) : null;
            $store = is_array($decoded) ? $decoded : ['conversations' => []];
            $store['conversations'] = is_array($store['conversations'] ?? null) ? $store['conversations'] : [];

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
        $nowMs = $this->nowMs();

        foreach ($store['conversations'] as $conversationKey => $conversation) {
            if (!is_array($conversation)) {
                unset($store['conversations'][$conversationKey]);
                continue;
            }

            foreach ($conversation as $userId => $payload) {
                if ($this->resolveExpiresAtMs($payload) <= $nowMs) {
                    unset($store['conversations'][$conversationKey][$userId]);
                }
            }

            if (($store['conversations'][$conversationKey] ?? []) === []) {
                unset($store['conversations'][$conversationKey]);
            }
        }
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function resolveExpiresAtMs(mixed $payload): int
    {
        if (!is_array($payload)) {
            return 0;
        }

        $expiresAtMs = (int) ($payload['expires_at_ms'] ?? 0);
        if ($expiresAtMs > 0) {
            return $expiresAtMs;
        }

        $expiresAtSeconds = (int) ($payload['expires_at'] ?? 0);
        if ($expiresAtSeconds > 0) {
            return $expiresAtSeconds * 1000;
        }

        return 0;
    }
}
