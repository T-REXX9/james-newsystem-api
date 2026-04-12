<?php

declare(strict_types=1);

namespace App\Services;

final class InternalChatRealtimeNotifier
{
    public function __construct(
        private readonly string $endpointUrl,
        private readonly string $sharedSecret
    ) {
    }

    public function notifyMessagesCreated(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $this->post([
            'type' => 'messages.created',
            'items' => array_values($messages),
        ]);
    }

    public function notifyConversationRead(int $userId, string $conversationKey, int $updatedCount, array $targetUserIds = []): void
    {
        if ($conversationKey === '') {
            return;
        }

        $this->post([
            'type' => 'conversation.read',
            'user_id' => (string) $userId,
            'read_by_user_id' => (string) $userId,
            'conversation_key' => $conversationKey,
            'updated_count' => $updatedCount,
            'target_user_ids' => array_values($targetUserIds),
        ]);
    }

    public function notifyReactionUpdated(array $payload): void
    {
        $conversationKey = trim((string) ($payload['conversation_key'] ?? ''));
        $messageId = trim((string) ($payload['message_id'] ?? ''));
        if ($conversationKey === '' || $messageId === '') {
            return;
        }

        $this->post([
            'type' => 'reaction.updated',
            'conversation_key' => $conversationKey,
            'message_id' => $messageId,
            'reactions' => array_values($payload['reactions'] ?? []),
            'current_user_reaction' => $payload['current_user_reaction'] ?? null,
            'actor_user_id' => trim((string) ($payload['actor_user_id'] ?? '')),
            'target_user_ids' => array_values($payload['target_user_ids'] ?? []),
        ]);
    }

    public function notifyTypingUpdated(array $payload): void
    {
        $conversationKey = trim((string) ($payload['conversation_key'] ?? ''));
        if ($conversationKey === '') {
            return;
        }

        $this->post([
            'type' => 'typing.updated',
            'conversation_key' => $conversationKey,
            'user_id' => trim((string) ($payload['user_id'] ?? '')),
            'is_typing' => (bool) ($payload['is_typing'] ?? false),
            'typing_user_ids' => array_values($payload['typing_user_ids'] ?? []),
            'target_user_ids' => array_values($payload['target_user_ids'] ?? []),
        ]);
    }

    private function post(array $payload): void
    {
        if ($this->endpointUrl === '' || $this->sharedSecret === '') {
            return;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return;
        }

        if (function_exists('curl_init')) {
            $this->postWithCurl($json);
            return;
        }

        $this->postWithStream($json);
    }

    private function postWithCurl(string $json): void
    {
        $handle = curl_init($this->endpointUrl);
        if ($handle === false) {
            return;
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Internal-Chat-Secret: ' . $this->sharedSecret,
                'Content-Length: ' . strlen($json),
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 150,
            CURLOPT_TIMEOUT_MS => 500,
        ]);

        curl_exec($handle);
    }

    private function postWithStream(string $json): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'X-Internal-Chat-Secret: ' . $this->sharedSecret,
                    'Content-Length: ' . strlen($json),
                ]),
                'content' => $json,
                'ignore_errors' => true,
                'timeout' => 0.5,
            ],
        ]);

        @file_get_contents($this->endpointUrl, false, $context);
    }
}
