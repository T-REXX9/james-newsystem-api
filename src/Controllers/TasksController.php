<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\TasksRepository;
use App\Support\Exceptions\HttpException;

final class TasksController
{
    public function __construct(private readonly TasksRepository $repo)
    {
    }

    /**
     * List all tasks
     * GET /api/v1/tasks
     */
    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 50);

        return $this->repo->list($page, $perPage);
    }

    /**
     * Get a single task
     * GET /api/v1/tasks/{id}
     */
    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'Task ID is required');
        }

        $task = $this->repo->show($id);
        if (!$task) {
            throw new HttpException(404, 'Task not found');
        }

        return $task;
    }

    /**
     * Create a new task
     * POST /api/v1/tasks
     */
    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            throw new HttpException(422, 'Task title is required');
        }

        $dueDate = trim((string) ($body['dueDate'] ?? $body['due_date'] ?? ''));
        if ($dueDate === '') {
            throw new HttpException(422, 'Due date is required');
        }

        return $this->repo->create($body);
    }

    /**
     * Update a task
     * PATCH /api/v1/tasks/{id}
     */
    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'Task ID is required');
        }

        $this->repo->update($id, $body);

        $updated = $this->repo->show($id);
        if (!$updated) {
            throw new HttpException(404, 'Task not found after update');
        }

        return $updated;
    }

    /**
     * Delete (soft delete) a task
     * DELETE /api/v1/tasks/{id}
     */
    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'Task ID is required');
        }

        $this->repo->delete($id);

        return ['success' => true, 'message' => 'Task deleted successfully'];
    }

    /**
     * Restore a deleted task
     * POST /api/v1/tasks/{id}/restore
     */
    public function restore(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'Task ID is required');
        }

        return $this->repo->restore($id);
    }

    /**
     * Get tasks by status
     * GET /api/v1/tasks/status/{status}
     */
    public function getByStatus(array $params = [], array $query = [], array $body = []): array
    {
        $status = trim((string) ($params['status'] ?? ''));
        if ($status === '') {
            throw new HttpException(422, 'Status is required');
        }

        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 50);

        return $this->repo->getByStatus($status, $page, $perPage);
    }

    /**
     * Bulk update tasks
     * POST /api/v1/tasks/bulk-update
     */
    public function bulkUpdate(array $params = [], array $query = [], array $body = []): array
    {
        $ids = $body['ids'] ?? [];
        if (empty($ids)) {
            throw new HttpException(422, 'Task IDs are required');
        }

        $updates = $body['updates'] ?? [];
        if (empty($updates)) {
            throw new HttpException(422, 'Updates data is required');
        }

        $this->repo->bulkUpdate($ids, $updates);

        return ['success' => true, 'message' => 'Tasks updated successfully'];
    }
}
