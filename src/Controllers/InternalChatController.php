<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Response;
use App\Repositories\InternalChatRepository;
use App\Security\TokenService;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class InternalChatController
{
    public function __construct(
        private readonly InternalChatRepository $repo,
        private readonly TokenService $tokens
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
            return [
                'items' => $this->repo->sendMessage($mainId, $userId, $message, $recipientIds),
            ];
        } catch (RuntimeException $error) {
            throw new HttpException(422, $error->getMessage());
        }
    }

    public function markConversationRead(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->requireAuthClaims();
        [$userId] = $this->resolveIdentity($claims);

        $conversationKey = trim(urldecode((string) ($params['conversationKey'] ?? '')));
        if ($conversationKey === '') {
            throw new HttpException(422, 'conversationKey is required');
        }

        return $this->repo->markConversationRead($userId, $conversationKey);
    }

    public function unreadCount(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->requireAuthClaims();
        [$userId] = $this->resolveIdentity($claims);

        return [
            'count' => $this->repo->getUnreadCount($userId),
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
