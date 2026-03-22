<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AuthRepository;
use App\Repositories\RolePermissionRepository;
use App\Repositories\StaffRepository;
use App\Support\Exceptions\HttpException;

final class StaffController
{
    private ?AuthRepository $authRepo;
    private ?RolePermissionRepository $rolePermissionRepo;

    public function __construct(
        private readonly StaffRepository $repo,
        ?AuthRepository $authRepo = null,
        ?RolePermissionRepository $rolePermissionRepo = null
    ) {
        $this->authRepo = $authRepo;
        $this->rolePermissionRepo = $rolePermissionRepo;
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $search = trim((string) ($query['search'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));

        return $this->repo->listStaff($mainId, $search, $page, $perPage);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $staffId = (int) ($params['staffId'] ?? 0);
        if ($staffId <= 0) {
            throw new HttpException(422, 'staffId is required');
        }

        $staff = $this->repo->getStaffById($mainId, $staffId);
        if ($staff === null) {
            throw new HttpException(404, 'Staff member not found');
        }

        return $staff;
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $staffId = (int) ($params['staffId'] ?? 0);
        if ($staffId <= 0) {
            throw new HttpException(422, 'staffId is required');
        }

        // Extract updatable fields from body
        $allowedFields = [
            'full_name',
            'role',
            'mobile',
            'team_id',
            'birthday',
            'gender',
            'contact',
            'avatar_url',
            'sales_quota',
            'prospect_quota',
            'commission',
            'branch_id',
            'access_rights',
            'access_override',
            'group_id',
        ];

        $data = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = $body[$field];
            }
        }

        if (empty($data)) {
            throw new HttpException(422, 'No valid fields to update');
        }

        $updated = $this->repo->updateStaff($mainId, $staffId, $data);
        if ($updated === null) {
            throw new HttpException(404, 'Staff member not found');
        }

        return $updated;
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $fullName = trim((string) ($body['full_name'] ?? ''));
        if ($fullName === '') {
            throw new HttpException(422, 'full_name is required');
        }

        $email = trim((string) ($body['email'] ?? ''));
        if ($email === '') {
            throw new HttpException(422, 'email is required');
        }

        $password = (string) ($body['password'] ?? '');
        if ($password === '') {
            throw new HttpException(422, 'password is required');
        }

        if (strlen($password) < 8) {
            throw new HttpException(422, 'password must be at least 8 characters');
        }

        $role = trim((string) ($body['role'] ?? ''));
        if ($role === '') {
            throw new HttpException(422, 'role is required');
        }

        $created = $this->repo->createStaff($mainId, [
            'full_name' => $fullName,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'birthday' => $body['birthday'] ?? null,
            'mobile' => $body['mobile'] ?? null,
            'access_rights' => $body['access_rights'] ?? [],
            'group_id' => $body['group_id'] ?? null,
        ]);

        // Initialize permissions based on the user's role
        $newUserId = (int) ($created['id'] ?? 0);
        $groupId = (string) ($created['group_id'] ?? '0');

        if ($newUserId > 0 && $groupId !== '' && $groupId !== '0') {
            if ($this->rolePermissionRepo !== null) {
                $this->rolePermissionRepo->initializeUserPermissions($mainId, $newUserId, (int) $groupId);
                $this->rolePermissionRepo->logPermissionChange(
                    $mainId,
                    (int) $groupId,
                    'NEW_USER_PERMISSIONS',
                    'system',
                    null,
                    $this->rolePermissionRepo->getRoleDefaultPermissions($mainId, (int) $groupId)
                );
            } elseif ($this->authRepo !== null) {
                $this->authRepo->initializeUserPermissions($newUserId, $groupId, $mainId);
            }
        }

        return $created;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $staffId = (int) ($params['staffId'] ?? 0);
        if ($staffId <= 0) {
            throw new HttpException(422, 'staffId is required');
        }

        $ok = $this->repo->deleteStaff($mainId, $staffId);
        if (!$ok) {
            throw new HttpException(404, 'Staff member not found');
        }

        return [
            'deleted' => true,
            'staff_id' => $staffId,
        ];
    }

    /**
     * Get available user types/roles
     */
    public function roles(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return $this->repo->getUserTypes($mainId);
    }
}
