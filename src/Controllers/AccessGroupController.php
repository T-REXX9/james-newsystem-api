<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AccessGroupRepository;
use App\Repositories\RolePermissionRepository;
use App\Support\Exceptions\HttpException;

final class AccessGroupController
{
    private ?RolePermissionRepository $rolePermissionRepo;

    public function __construct(
        private readonly AccessGroupRepository $repo,
        ?RolePermissionRepository $rolePermissionRepo = null
    ) {
        $this->rolePermissionRepo = $rolePermissionRepo;
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return $this->repo->listGroups($mainId);
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new HttpException(422, 'name is required');
        }

        return $this->repo->createGroup($mainId, [
            'name' => $name,
            'description' => $body['description'] ?? '',
            'access_rights' => $body['access_rights'] ?? [],
        ]);
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $groupId = (int) ($params['id'] ?? 0);
        if ($groupId <= 0) {
            throw new HttpException(422, 'id is required');
        }

        $data = [];
        foreach (['name', 'description', 'access_rights'] as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = $body[$field];
            }
        }

        if (empty($data)) {
            throw new HttpException(422, 'No valid fields to update');
        }

        // Capture old permissions for audit logging
        $oldGroup = $this->repo->getGroupById($mainId, $groupId);
        $oldPermissions = $oldGroup ? ($oldGroup['access_rights'] ?? []) : [];

        $updated = $this->repo->updateGroup($mainId, $groupId, $data);
        if ($updated === null) {
            throw new HttpException(404, 'Access group not found');
        }

        // Log permission changes for audit trail
        if (array_key_exists('access_rights', $data) && $this->rolePermissionRepo !== null) {
            $this->rolePermissionRepo->logPermissionChange(
                $mainId,
                $groupId,
                'UPDATE_GROUP_PERMISSIONS',
                'admin',
                $oldPermissions,
                $updated['access_rights'] ?? []
            );
        }

        return $updated;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $groupId = (int) ($params['id'] ?? 0);
        if ($groupId <= 0) {
            throw new HttpException(422, 'id is required');
        }

        if ($this->repo->countAssignedStaff($mainId, $groupId) > 0) {
            throw new HttpException(409, 'Cannot delete group while staff are assigned');
        }

        // Capture old permissions for audit logging before deletion
        $oldGroup = $this->repo->getGroupById($mainId, $groupId);
        $oldPermissions = $oldGroup ? ($oldGroup['access_rights'] ?? []) : [];

        $deleted = $this->repo->deleteGroup($mainId, $groupId);
        if (!$deleted) {
            throw new HttpException(404, 'Access group not found');
        }

        // Log the deletion for audit trail
        if ($this->rolePermissionRepo !== null) {
            $this->rolePermissionRepo->logPermissionChange(
                $mainId,
                $groupId,
                'DELETE_GROUP',
                'admin',
                $oldPermissions,
                null
            );
        }

        return [
            'success' => true,
            'id' => $groupId,
        ];
    }
}
