<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\DealsRepository;
use App\Support\Exceptions\HttpException;

final class DealsController
{
    public function __construct(private readonly DealsRepository $repo)
    {
    }

    /**
     * List all deals
     * GET /api/v1/deals
     */
    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 50);

        return $this->repo->list($page, $perPage);
    }

    /**
     * Get a single deal
     * GET /api/v1/deals/{id}
     */
    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'Deal ID is required');
        }

        $deal = $this->repo->show($id);
        if (!$deal) {
            throw new HttpException(404, 'Deal not found');
        }

        return $deal;
    }

    /**
     * Create a new deal
     * POST /api/v1/deals
     */
    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            throw new HttpException(422, 'Deal title is required');
        }

        $company = trim((string) ($body['company'] ?? ''));
        if ($company === '') {
            throw new HttpException(422, 'Company is required');
        }

        $contactName = trim((string) ($body['contactName'] ?? $body['contact_name'] ?? ''));
        if ($contactName === '') {
            throw new HttpException(422, 'Contact name is required');
        }

        return $this->repo->create($body);
    }

    /**
     * Update a deal
     * PATCH /api/v1/deals/{id}
     */
    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'Deal ID is required');
        }

        $this->repo->update($id, $body);

        $updated = $this->repo->show($id);
        if (!$updated) {
            throw new HttpException(404, 'Deal not found after update');
        }

        return $updated;
    }

    /**
     * Delete (soft delete) a deal
     * DELETE /api/v1/deals/{id}
     */
    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'Deal ID is required');
        }

        $this->repo->delete($id);

        return ['success' => true, 'message' => 'Deal deleted successfully'];
    }

    /**
     * Restore a deleted deal
     * POST /api/v1/deals/{id}/restore
     */
    public function restore(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'Deal ID is required');
        }

        return $this->repo->restore($id);
    }

    /**
     * Bulk update deals
     * POST /api/v1/deals/bulk-update
     */
    public function bulkUpdate(array $params = [], array $query = [], array $body = []): array
    {
        $ids = $body['ids'] ?? [];
        if (empty($ids)) {
            throw new HttpException(422, 'Deal IDs are required');
        }

        $updates = $body['updates'] ?? [];
        if (empty($updates)) {
            throw new HttpException(422, 'Updates data is required');
        }

        $this->repo->bulkUpdate($ids, $updates);

        return ['success' => true, 'message' => 'Deals updated successfully'];
    }

    /**
     * Get deals by stage
     * GET /api/v1/deals/stage/{stageId}
     */
    public function getByStage(array $params = [], array $query = [], array $body = []): array
    {
        $stageId = trim((string) ($params['stageId'] ?? $params['stage_id'] ?? ''));
        if ($stageId === '') {
            throw new HttpException(422, 'Stage ID is required');
        }

        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 50);

        return $this->repo->getByStage($stageId, $page, $perPage);
    }

    /**
     * Move deal to stage
     * POST /api/v1/deals/{id}/move-stage
     */
    public function moveToStage(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'Deal ID is required');
        }

        $stageId = trim((string) ($body['stageId'] ?? $body['stage_id'] ?? ''));
        if ($stageId === '') {
            throw new HttpException(422, 'Stage ID is required');
        }

        return $this->repo->moveToStage($id, $stageId);
    }
}
