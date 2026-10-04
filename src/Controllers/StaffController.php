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
        $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
        $claimMainId = (int) ($claims['main_userid'] ?? 0);
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }
        if ($claimMainId <= 0 || $mainId !== $claimMainId) {
            throw new HttpException(403, 'Invalid account scope');
        }

        $search = trim((string) ($query['search'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));

        $result = $this->repo->listStaff($mainId, $search, $page, $perPage);
        $viewerId = (int) ($claims['sub'] ?? 0);
        $isMaster = (string) ($claims['user_type'] ?? '') === '1'
            && $viewerId > 0
            && $viewerId === (int) ($claims['main_userid'] ?? 0);
        if (!$isMaster) {
            $result['items'] = array_map(static function (array $staff) use ($viewerId): array {
                if ((int) ($staff['id'] ?? 0) !== $viewerId) {
                    unset($staff['monthly_quota'], $staff['sales_quota']);
                }
                return $staff;
            }, $result['items'] ?? []);
        }
        return $result;
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
        $claimMainId = (int) ($claims['main_userid'] ?? 0);
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }
        if ($claimMainId <= 0 || $mainId !== $claimMainId) {
            throw new HttpException(403, 'Invalid account scope');
        }

        $staffId = (int) ($params['staffId'] ?? 0);
        if ($staffId <= 0) {
            throw new HttpException(422, 'staffId is required');
        }

        $staff = $this->repo->getStaffById($mainId, $staffId);
        if ($staff === null) {
            throw new HttpException(404, 'Staff member not found');
        }

        $viewerId = (int) ($claims['sub'] ?? 0);
        $isMaster = (string) ($claims['user_type'] ?? '') === '1'
            && $viewerId > 0
            && $viewerId === (int) ($claims['main_userid'] ?? 0);
        if (!$isMaster && $staffId !== $viewerId) {
            unset($staff['monthly_quota'], $staff['sales_quota']);
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
            'action_permissions',
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

        if (array_key_exists('role', $data) && !array_key_exists('group_id', $data)) {
            throw new HttpException(422, 'group_id is required when assigning a staff role');
        }

        $updated = $this->repo->updateStaff($mainId, $staffId, $data);
        if ($updated === null) {
            throw new HttpException(404, 'Staff member not found');
        }

        return $updated;
    }

    public function updateOwnSalesQuota(array $params = [], array $query = [], array $body = []): array
    {
        $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
        $userId = (int) ($claims['sub'] ?? 0);
        $mainId = (int) ($claims['main_userid'] ?? 0);
        $userType = (string) ($claims['user_type'] ?? '');
        if ($userId <= 0 || $mainId <= 0 || $userType === '') {
            throw new HttpException(403, 'Only a Master User or Sales Agent can update their own quota');
        }
        if ($userType === '1' && $userId !== $mainId) {
            throw new HttpException(403, 'Invalid Master User account scope');
        }
        if (!array_key_exists('sales_quota', $body) || !is_numeric($body['sales_quota'])) {
            throw new HttpException(422, 'A valid sales_quota is required');
        }

        $quota = (float) $body['sales_quota'];
        if (!is_finite($quota) || $quota < 0 || $quota > 9999999999999.99) {
            throw new HttpException(422, 'sales_quota must be between 0 and 9999999999999.99');
        }

        $updatedQuota = $this->repo->updateOwnSalesQuota($mainId, $userId, $userType, number_format($quota, 2, '.', ''));
        if ($updatedQuota === null) {
            throw new HttpException(403, 'This account cannot update its own sales quota');
        }

        return ['id' => (string) $userId, 'monthly_quota' => $updatedQuota];
    }

    public function changePassword(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $staffId = (int) ($params['staffId'] ?? 0);
        $password = (string) ($body['new_password'] ?? '');
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }
        if ($staffId <= 0) {
            throw new HttpException(422, 'staffId is required');
        }
        if (strlen($password) < 8) {
            throw new HttpException(422, 'Password must be at least 8 characters');
        }
        if ($this->authRepo === null || !$this->authRepo->changeStaffPassword($mainId, $staffId, $password)) {
            throw new HttpException(404, 'Staff member not found');
        }

        return ['password_changed' => true, 'staff_id' => $staffId];
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

        $role = trim((string) ($body['role'] ?? ''));
        if ($role === '') {
            throw new HttpException(422, 'role is required');
        }

        $groupId = (int) ($body['group_id'] ?? 0);
        if ($groupId <= 0) {
            throw new HttpException(422, 'group_id is required and must reference an existing access group');
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
