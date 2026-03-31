<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\NotificationsRepository;
use App\Support\Exceptions\HttpException;

final class NotificationsController
{
    public function __construct(private readonly NotificationsRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $userId = trim((string) ($query['user_id'] ?? ''));
        if ($userId === '') {
            throw new HttpException(422, 'user_id is required');
        }

        $limit = max(1, min(500, (int) ($query['limit'] ?? 50)));
        return [
            'data' => $this->repo->listByUser($userId, $limit),
        ];
    }

    public function unreadCount(array $params = [], array $query = [], array $body = []): array
    {
        $userId = trim((string) ($query['user_id'] ?? ''));
        if ($userId === '') {
            throw new HttpException(422, 'user_id is required');
        }

        return [
            'count' => $this->repo->getUnreadCount($userId),
        ];
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        if (trim((string) ($body['recipient_id'] ?? '')) === '') {
            throw new HttpException(422, 'recipient_id is required');
        }
        if (trim((string) ($body['title'] ?? '')) === '') {
            throw new HttpException(422, 'title is required');
        }
        if (trim((string) ($body['message'] ?? '')) === '') {
            throw new HttpException(422, 'message is required');
        }

        $created = $this->repo->create($body);
        if ($created === null) {
            throw new HttpException(500, 'Failed to create notification');
        }

        return $created;
    }

    public function markAsRead(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'Notification id is required');
        }

        if (!$this->repo->markAsRead($id)) {
            throw new HttpException(404, 'Notification not found');
        }

        return ['success' => true];
    }

    public function markAllAsRead(array $params = [], array $query = [], array $body = []): array
    {
        $userId = trim((string) ($body['user_id'] ?? $query['user_id'] ?? ''));
        if ($userId === '') {
            throw new HttpException(422, 'user_id is required');
        }

        $this->repo->markAllAsRead($userId);
        return ['success' => true];
    }

    public function markByEntityRead(array $params = [], array $query = [], array $body = []): array
    {
        $userId = trim((string) ($body['user_id'] ?? ''));
        if ($userId === '') {
            throw new HttpException(422, 'user_id is required');
        }

        return $this->repo->markByEntityKey(
            $userId,
            isset($body['entity_type']) ? (string) $body['entity_type'] : null,
            isset($body['entity_id']) ? (string) $body['entity_id'] : null,
            isset($body['refno']) ? (string) $body['refno'] : null
        );
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'Notification id is required');
        }

        if (!$this->repo->delete($id)) {
            throw new HttpException(404, 'Notification not found');
        }

        return ['success' => true];
    }

    public function workflowDispatch(array $params = [], array $query = [], array $body = []): array
    {
        foreach (['title', 'message', 'type', 'action', 'status', 'entityType', 'entityId'] as $required) {
            if (trim((string) ($body[$required] ?? '')) === '') {
                throw new HttpException(422, sprintf('%s is required', $required));
            }
        }

        return [
            'data' => $this->repo->dispatchWorkflow($body),
        ];
    }

    public function scanInventoryAlerts(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? $query['main_id'] ?? 0);

        return [
            'data' => $this->repo->scanInventoryAlerts($mainId),
        ];
    }
}
