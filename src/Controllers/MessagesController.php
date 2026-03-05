<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\MessagesRepository;
use App\Support\Exceptions\HttpException;

final class MessagesController
{
    public function __construct(private readonly MessagesRepository $repo)
    {
    }

    /**
     * List messages in a team
     * GET /api/v1/teams/{teamId}/messages
     */
    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $teamId = trim((string) ($params['teamId'] ?? $params['team_id'] ?? ''));
        if ($teamId === '') {
            throw new HttpException(422, 'Team ID is required');
        }

        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 50);

        return $this->repo->list($teamId, $page, $perPage);
    }

    /**
     * Get a single message
     * GET /api/v1/messages/{id}
     */
    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'Message ID is required');
        }

        $message = $this->repo->show($id);
        if (!$message) {
            throw new HttpException(404, 'Message not found');
        }

        return $message;
    }

    /**
     * Create a new message
     * POST /api/v1/teams/{teamId}/messages
     */
    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $teamId = trim((string) ($params['teamId'] ?? $params['team_id'] ?? $body['teamId'] ?? $body['team_id'] ?? ''));
        if ($teamId === '') {
            throw new HttpException(422, 'Team ID is required');
        }

        $message = trim((string) ($body['message'] ?? ''));
        if ($message === '') {
            throw new HttpException(422, 'Message content is required');
        }

        $senderId = trim((string) ($body['senderId'] ?? $body['sender_id'] ?? ''));
        if ($senderId === '') {
            throw new HttpException(422, 'Sender ID is required');
        }

        $body['teamId'] = $teamId;

        return $this->repo->create($body);
    }

    /**
     * Update a message
     * PATCH /api/v1/messages/{id}
     */
    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'Message ID is required');
        }

        $this->repo->update($id, $body);

        $updated = $this->repo->show($id);
        if (!$updated) {
            throw new HttpException(404, 'Message not found after update');
        }

        return $updated;
    }

    /**
     * Delete (soft delete) a message
     * DELETE /api/v1/messages/{id}
     */
    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'Message ID is required');
        }

        $this->repo->delete($id);

        return ['success' => true, 'message' => 'Message deleted successfully'];
    }

    /**
     * Get messages by sender
     * GET /api/v1/teams/{teamId}/messages/sender/{senderId}
     */
    public function getBySender(array $params = [], array $query = [], array $body = []): array
    {
        $teamId = trim((string) ($params['teamId'] ?? $params['team_id'] ?? ''));
        if ($teamId === '') {
            throw new HttpException(422, 'Team ID is required');
        }

        $senderId = trim((string) ($params['senderId'] ?? $params['sender_id'] ?? ''));
        if ($senderId === '') {
            throw new HttpException(422, 'Sender ID is required');
        }

        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 50);

        return $this->repo->getBySender($teamId, $senderId, $page, $perPage);
    }
}
