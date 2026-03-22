<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\LegacyPermissionMapper;
use PDO;

final class AuthRepository
{
    private LegacyPermissionMapper $legacyPermissions;

    public function __construct(private readonly Database $db)
    {
        $this->legacyPermissions = new LegacyPermissionMapper($db->pdo());
    }

    public function findActiveUserByEmail(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
             FROM tblaccount
             WHERE lemail = :email
               AND COALESCE(lstatus, 0) = 1
             LIMIT 1'
        );
        $stmt->execute(['email' => trim($email)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findUserById(int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
             FROM tblaccount
             WHERE lid = :id
               AND COALESCE(lstatus, 0) = 1
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getWebPermissions(int $mainUserId, string $group): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lpageno, lstatus, ladd_action, ledit_action, ldelete_action
             FROM tblweb_permission
             WHERE lmain_id = :main_id
               AND lgroup = :lgroup'
        );
        $stmt->execute([
            'main_id' => $mainUserId,
            'lgroup' => $group,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPackagePermissions(int $mainUserId, string $packageId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lpageno, lstatus
             FROM tblmy_permission
             WHERE luserid = :main_id
               AND lpackage = :package_id'
        );
        $stmt->execute([
            'main_id' => $mainUserId,
            'package_id' => $packageId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function hashLegacyPassword(string $rawPassword): string
    {
        $recode = md5($rawPassword);
        return md5($rawPassword . $recode);
    }

    public function resolveMainUserId(array $user): int
    {
        $type = (string) ($user['ltype'] ?? '1');
        $userId = (int) ($user['lid'] ?? 0);
        if ($type === '1') {
            return $userId;
        }

        $mother = (int) ($user['lmother_id'] ?? 0);
        return $mother > 0 ? $mother : $userId;
    }

    /**
     * @return array<int, string>
     */
    public function getDerivedAccessRights(array $user): array
    {
        $mainUserId = $this->resolveMainUserId($user);
        $groupId = (int) ($user['ltype'] ?? 0);
        if ($mainUserId <= 0 || $groupId <= 0) {
            return ['home'];
        }

        return $this->legacyPermissions->getAccessRightsForGroup($mainUserId, $groupId);
    }

    public function getRoleName(int $groupId): ?string
    {
        if ($groupId <= 0) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT ltype_name
             FROM tblusertype
             WHERE lid = :group_id
             LIMIT 1'
        );
        $stmt->execute(['group_id' => $groupId]);
        $value = $stmt->fetchColumn();
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Get default permissions for a role type based on tblweb_permission.
     *
     * @return array<int, string>
     */
    public function getRoleDefaultPermissions(int $mainId, int $groupId): array
    {
        if ($mainId <= 0 || $groupId <= 0) {
            return ['home'];
        }

        return $this->legacyPermissions->getAccessRightsForGroup($mainId, $groupId);
    }

    /**
     * Initialize permissions for a new user based on their role type.
     * Fetches the role's default permissions from tblweb_permission and assigns them.
     */
    public function initializeUserPermissions(int $userId, string $roleType, int $mainId): void
    {
        $groupId = (int) $roleType;
        $rolePermissions = $this->getRoleDefaultPermissions($mainId, $groupId);

        // Store the permissions as the user's access_rights
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tblaccount
             SET laccess_rights = :access_rights
             WHERE lid = :user_id'
        );
        $stmt->execute([
            'access_rights' => json_encode($rolePermissions),
            'user_id' => $userId,
        ]);
    }

    /**
     * Get action-level permissions (add/edit/delete) for a user based on their role.
     *
     * @return array<string, array{can_add: bool, can_edit: bool, can_delete: bool}>
     */
    public function getActionPermissionsForRole(int $mainId, int $groupId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lpageno, ladd_action, ledit_action, ldelete_action
             FROM tblweb_permission
             WHERE lmain_id = :main_id
               AND lgroup = :group_id
               AND lstatus = 1'
        );
        $stmt->execute([
            'main_id' => $mainId,
            'group_id' => $groupId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $pageNo = (string) ($row['lpageno'] ?? '');
            if ($pageNo !== '') {
                $result[$pageNo] = [
                    'can_add' => (int) ($row['ladd_action'] ?? 0) === 1,
                    'can_edit' => (int) ($row['ledit_action'] ?? 0) === 1,
                    'can_delete' => (int) ($row['ldelete_action'] ?? 0) === 1,
                ];
            }
        }

        return $result;
    }
}
