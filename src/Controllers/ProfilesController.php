<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ProfilesRepository;
use App\Support\Exceptions\HttpException;

final class ProfilesController
{
    public function __construct(private readonly ProfilesRepository $repo)
    {
    }

    /**
     * List all user profiles
     * GET /api/v1/profiles
     */
    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 50);

        return $this->repo->list($page, $perPage);
    }

    /**
     * Get a single user profile
     * GET /api/v1/profiles/{id}
     */
    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException(422, 'User ID is required');
        }

        $profile = $this->repo->show($id);
        if (!$profile) {
            throw new HttpException(404, 'User profile not found');
        }

        return $profile;
    }

    /**
     * Get sales agents
     * GET /api/v1/profiles/sales-agents
     */
    public function salesAgents(array $params = [], array $query = [], array $body = []): array
    {
        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 50);

        return $this->repo->getSalesAgents($page, $perPage);
    }

    /**
     * Update a user profile
     * PATCH /api/v1/profiles/{id}
     */
    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException(422, 'User ID is required');
        }

        $this->repo->update($id, $body);

        $updated = $this->repo->show($id);
        if (!$updated) {
            throw new HttpException(404, 'User profile not found after update');
        }

        return $updated;
    }

    /**
     * Deactivate a staff account
     * POST /api/v1/profiles/{id}/deactivate
     */
    public function deactivate(array $params = [], array $query = [], array $body = []): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException(422, 'User ID is required');
        }

        $this->repo->deactivate($id);

        return ['success' => true, 'message' => 'User account deactivated successfully'];
    }

    /**
     * Activate a staff account
     * POST /api/v1/profiles/{id}/activate
     */
    public function activate(array $params = [], array $query = [], array $body = []): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException(422, 'User ID is required');
        }

        $this->repo->activate($id);

        return ['success' => true, 'message' => 'User account activated successfully'];
    }

    /**
     * Update user role
     * POST /api/v1/profiles/{id}/role
     */
    public function updateRole(array $params = [], array $query = [], array $body = []): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException(422, 'User ID is required');
        }

        $role = trim((string) ($body['role'] ?? ''));
        if ($role === '') {
            throw new HttpException(422, 'Role is required');
        }

        $this->repo->updateRole($id, $role);

        $updated = $this->repo->show($id);
        if (!$updated) {
            throw new HttpException(404, 'User profile not found after role update');
        }

        return $updated;
    }
}
