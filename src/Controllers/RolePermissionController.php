<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\PermissionMiddleware;
use App\Repositories\RolePermissionRepository;
use App\Support\Exceptions\HttpException;

final class RolePermissionController
{
    public function __construct(
        private readonly RolePermissionRepository $repo,
        private readonly PermissionMiddleware $middleware
    ) {
    }

    /**
     * GET /api/v1/roles - List all roles and their permissions.
     */
    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->middleware->requireAuth();
        $mainId = (int) ($query['main_id'] ?? $claims['main_userid'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $roles = $this->repo->getAllRoles($mainId);
        $result = [];

        foreach ($roles as $role) {
            $groupId = (int) $role['id'];
            $permissions = $this->repo->getPermissionsForRole($mainId, $groupId);
            $actionPerms = $this->repo->getActionPermissions($mainId, $groupId);

            $result[] = [
                'id' => $groupId,
                'name' => $role['name'],
                'description' => $role['description'],
                'access_rights' => $permissions,
                'action_permissions' => $actionPerms,
            ];
        }

        return $result;
    }

    /**
     * GET /api/v1/roles/{roleId}/permissions - Get permissions for a specific role.
     */
    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->middleware->requireAuth();
        $mainId = (int) ($query['main_id'] ?? $claims['main_userid'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $roleId = (int) ($params['roleId'] ?? 0);
        if ($roleId <= 0) {
            throw new HttpException(422, 'roleId is required');
        }

        $permissions = $this->repo->getPermissionsForRole($mainId, $roleId);
        $actionPerms = $this->repo->getActionPermissions($mainId, $roleId);

        return [
            'role_id' => $roleId,
            'access_rights' => $permissions,
            'action_permissions' => $actionPerms,
        ];
    }

    /**
     * PUT /api/v1/roles/{roleId}/permissions - Update permissions for a role.
     */
    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->middleware->requireAuth();

        // Only Owner can update role permissions
        $userType = (string) ($claims['user_type'] ?? '');
        if ($userType !== '1') {
            throw new HttpException(403, 'Only the account owner can manage role permissions');
        }

        $mainId = (int) ($body['main_id'] ?? $claims['main_userid'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $roleId = (int) ($params['roleId'] ?? 0);
        if ($roleId <= 0) {
            throw new HttpException(422, 'roleId is required');
        }

        $accessRights = $body['access_rights'] ?? null;
        if (!is_array($accessRights)) {
            throw new HttpException(422, 'access_rights must be an array');
        }

        $actionPermissions = $body['action_permissions'] ?? [];
        if (!is_array($actionPermissions)) {
            $actionPermissions = [];
        }

        // Fetch old permissions for audit log
        $oldPermissions = $this->repo->getPermissionsForRole($mainId, $roleId);

        // Update permissions
        $this->repo->updateRolePermissions($mainId, $roleId, $accessRights, $actionPermissions);

        // Log the change
        $changedBy = (string) ($claims['sub'] ?? 'unknown');
        $this->repo->logPermissionChange(
            $mainId,
            $roleId,
            'UPDATE_ROLE_PERMISSIONS',
            $changedBy,
            $oldPermissions,
            $accessRights
        );

        // Return updated permissions
        $updatedPermissions = $this->repo->getPermissionsForRole($mainId, $roleId);
        $updatedActionPerms = $this->repo->getActionPermissions($mainId, $roleId);

        return [
            'role_id' => $roleId,
            'access_rights' => $updatedPermissions,
            'action_permissions' => $updatedActionPerms,
        ];
    }

    /**
     * POST /api/v1/roles - Create a new role.
     */
    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $claims = $this->middleware->requireAuth();

        // Only Owner can create roles
        $userType = (string) ($claims['user_type'] ?? '');
        if ($userType !== '1') {
            throw new HttpException(403, 'Only the account owner can create roles');
        }

        $mainId = (int) ($body['main_id'] ?? $claims['main_userid'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new HttpException(422, 'name is required');
        }

        $description = trim((string) ($body['description'] ?? ''));
        $accessRights = $body['access_rights'] ?? ['home'];
        if (!is_array($accessRights)) {
            $accessRights = ['home'];
        }

        // Create the role in tblusertype
        $stmt = $this->repo->getAllRoles($mainId);

        $pdo = $this->getDatabase()->pdo();
        $insertStmt = $pdo->prepare(
            'INSERT INTO tblusertype (ltype_name, ldesc, lmain_id, ldefault)
             VALUES (:name, :description, :main_id, 0)'
        );
        $insertStmt->execute([
            'name' => $name,
            'description' => $description,
            'main_id' => $mainId,
        ]);

        $roleId = (int) $pdo->lastInsertId();

        // Set initial permissions
        $this->repo->updateRolePermissions($mainId, $roleId, $accessRights);

        // Log the creation
        $changedBy = (string) ($claims['sub'] ?? 'unknown');
        $this->repo->logPermissionChange(
            $mainId,
            $roleId,
            'CREATE_ROLE',
            $changedBy,
            null,
            $accessRights
        );

        return [
            'id' => $roleId,
            'name' => $name,
            'description' => $description,
            'access_rights' => $accessRights,
        ];
    }

    private function getDatabase(): \App\Database
    {
        // Access database through the repository's reflection or re-construct
        // This is a workaround - ideally inject Database directly
        $reflection = new \ReflectionClass($this->repo);
        $dbProp = $reflection->getProperty('db');
        $dbProp->setAccessible(true);
        return $dbProp->getValue($this->repo);
    }
}
