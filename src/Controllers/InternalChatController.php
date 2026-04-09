<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\InternalChatRepository;
use App\Security\TokenService;
use App\Services\InternalChatRealtimeNotifier;
use App\Support\InternalChatReactionStore;
use App\Support\InternalChatTypingStore;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class InternalChatController
{
    public function __construct(
        private readonly InternalChatRepository $repo,
        private readonly TokenService $tokens,
        private readonly InternalChatRealtimeNotifier $realtimeNotifier,
        private readonly InternalChatReactionStore $reactionStore,
        private readonly InternalChatTypingStore $typingStore
    ) {
    }

    public function participants(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->requireAuthClaims();
        [$userId, $mainId] = $this->resolveIdentity($claims);

        return [
            'items' => $this->repo->listParticipants($mainId, $userId),
        ];
    }

    public function conversations(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->requireAuthClaims();
        [$userId, $mainId] = $this->resolveIdentity($claims);

        return [
            'items' => $this->repo->listConversations($mainId, $userId),
        ];
    }

    public function messages(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->requireAuthClaims();
        [$userId, $mainId] = $this->resolveIdentity($claims);

        $conversationKey = trim(urldecode((string) ($params['conversationKey'] ?? '')));
        if ($conversationKey === '') {
            throw new HttpException(422, 'conversationKey is required');
        }

        try {
            return [
                'items' => $this->repo->listMessages($mainId, $userId, $conversationKey),
            ];
        } catch (RuntimeException $error) {
            throw new HttpException(404, $error->getMessage());
        }
    }

    public function send(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->requireAuthClaims();
        [$userId, $mainId] = $this->resolveIdentity($claims);

        $message = trim((string) ($body['message'] ?? ''));
        if ($message === '') {
            throw new HttpException(422, 'message is required');
        }

        $recipientIds = [];
        if (is_array($body['recipient_ids'] ?? null)) {
            $recipientIds = array_map(static fn (mixed $value): string => trim((string) $value), $body['recipient_ids']);
        }

        $conversationKey = trim((string) ($body['conversation_key'] ?? ''));
        if ($recipientIds === [] && $conversationKey !== '') {
            if (!preg_match('/^dm:(\d+):(\d+)$/', $conversationKey, $matches)) {
                throw new HttpException(422, 'Invalid conversation_key');
            }

            $participants = [(string) $matches[1], (string) $matches[2]];
            if (!in_array((string) $userId, $participants, true)) {
                throw new HttpException(403, 'You do not have access to this conversation');
            }

            $recipientIds = array_values(array_filter(
                $participants,
                static fn (string $participantId): bool => $participantId !== (string) $userId
            ));
        }

        if ($recipientIds === []) {
            throw new HttpException(422, 'recipient_ids or conversation_key is required');
        }

        try {
            $items = $this->repo->sendMessage($mainId, $userId, $message, $recipientIds);
            $this->realtimeNotifier->notifyMessagesCreated($items);

            return [
                'items' => $items,
            ];
        } catch (RuntimeException $error) {
            throw new HttpException(422, $error->getMessage());
        }
    }

    public function markConversationRead(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->requireAuthClaims();
        [$userId, $mainId] = $this->resolveIdentity($claims);

        $conversationKey = trim(urldecode((string) ($params['conversationKey'] ?? '')));
        if ($conversationKey === '') {
            throw new HttpException(422, 'conversationKey is required');
        }

        try {
            $this->repo->assertConversationAccess($mainId, $userId, $conversationKey);
        } catch (RuntimeException $error) {
            throw new HttpException(404, $error->getMessage());
        }

        $result = $this->repo->markConversationRead($userId, $conversationKey);
        $this->realtimeNotifier->notifyConversationRead(
            $userId,
            $conversationKey,
            (int) ($result['updated_count'] ?? 0)
        );

        return $result;
    }

    public function unreadCount(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->requireAuthClaims();
        [$userId] = $this->resolveIdentity($claims);

        return [
            'count' => $this->repo->getUnreadCount($userId),
        ];
    }

    public function toggleReaction(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->requireAuthClaims();
        [$userId, $mainId] = $this->resolveIdentity($claims);

        $messageId = trim((string) ($params['messageId'] ?? ''));
        if ($messageId === '') {
            throw new HttpException(422, 'messageId is required');
        }

        $emoji = trim((string) ($body['emoji'] ?? ''));
        if ($emoji === '') {
            throw new HttpException(422, 'emoji is required');
        }

        try {
            $message = $this->repo->getMessageForUser($mainId, $userId, $messageId);
            $reactionState = $this->reactionStore->toggleReaction(
                (string) ($message['conversation_key'] ?? ''),
                (string) ($message['id'] ?? ''),
                (string) $userId,
                $emoji
            );
        } catch (RuntimeException $error) {
            $status = str_contains(strtolower($error->getMessage()), 'message not found') ? 404 : 422;
            throw new HttpException($status, $error->getMessage());
        }

        $payload = [
            'message_id' => (string) ($message['id'] ?? ''),
            'conversation_key' => (string) ($message['conversation_key'] ?? ''),
            'reactions' => array_values($reactionState['reactions'] ?? []),
            'current_user_reaction' => $reactionState['current_user_reaction'] ?? null,
            'actor_user_id' => (string) $userId,
        ];

        $this->realtimeNotifier->notifyReactionUpdated($payload);

        return $payload;
    }

    public function typingState(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->requireAuthClaims();
        [$userId, $mainId] = $this->resolveIdentity($claims);

        $conversationKey = trim(urldecode((string) ($params['conversationKey'] ?? '')));
        if ($conversationKey === '') {
            throw new HttpException(422, 'conversationKey is required');
        }

        try {
            $this->repo->assertConversationAccess($mainId, $userId, $conversationKey);
        } catch (RuntimeException $error) {
            throw new HttpException(404, $error->getMessage());
        }

        return [
            'conversation_key' => $conversationKey,
            'typing_user_ids' => $this->typingStore->listTypingUserIds($conversationKey, (string) $userId),
        ];
    }

    public function updateTyping(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->requireAuthClaims();
        [$userId, $mainId] = $this->resolveIdentity($claims);

        $conversationKey = trim(urldecode((string) ($params['conversationKey'] ?? '')));
        if ($conversationKey === '') {
            throw new HttpException(422, 'conversationKey is required');
        }

        try {
            $this->repo->assertConversationAccess($mainId, $userId, $conversationKey);
        } catch (RuntimeException $error) {
            throw new HttpException(404, $error->getMessage());
        }

        $isTyping = filter_var($body['is_typing'] ?? false, FILTER_VALIDATE_BOOL);
        $typingUserIds = $this->typingStore->setTyping($conversationKey, (string) $userId, $isTyping);
        $visibleTypingUserIds = array_values(array_filter(
            $typingUserIds,
            static fn (string $typingUserId): bool => $typingUserId !== (string) $userId
        ));

        $payload = [
            'conversation_key' => $conversationKey,
            'user_id' => (string) $userId,
            'is_typing' => $isTyping,
            'typing_user_ids' => $typingUserIds,
        ];

        $this->realtimeNotifier->notifyTypingUpdated($payload);

        return [
            'conversation_key' => $conversationKey,
            'user_id' => (string) $userId,
            'is_typing' => $isTyping,
            'typing_user_ids' => $visibleTypingUserIds,
        ];
    }

    public function stream(array $params = [], array $query = [], array $body = []): ?array
    {
        throw new HttpException(503, 'Internal chat live updates are temporarily disabled.');
    }

    private function requireAuthClaims(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
        if (!is_string($header) || trim($header) === '') {
            throw new HttpException(401, 'Authorization header is required');
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            throw new HttpException(401, 'Bearer token is required');
        }

        return $this->tokens->verify((string) $matches[1]);
    }

    private function requireStreamAuthClaims(array $query): array
    {
        $queryToken = trim((string) ($query['token'] ?? ''));
        if ($queryToken !== '') {
            return $this->tokens->verify($queryToken);
        }

        return $this->requireAuthClaims();
    }

    private function resolveIdentity(array $claims): array
    {
        $userId = (int) ($claims['sub'] ?? 0);
        $mainId = (int) ($claims['main_userid'] ?? 0);
        if ($userId <= 0 || $mainId <= 0) {
            throw new HttpException(401, 'Invalid auth context');
        }

        return [$userId, $mainId];
    }
}
