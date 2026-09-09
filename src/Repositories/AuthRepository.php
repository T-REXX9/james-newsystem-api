<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\LegacyPermissionMapper;
use App\Support\Exceptions\HttpException;
use PDO;

final class AuthRepository
{
    private LegacyPermissionMapper $legacyPermissions;
    private ?bool $hasAccountAccessRightsColumn = null;
    private ?bool $hasSessionVersionColumn = null;

    public function __construct(private readonly Database $db)
    {
        $this->legacyPermissions = new LegacyPermissionMapper($db->pdo());
    }

    public function findActiveUserByEmail(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
             FROM tblaccount
             WHERE LOWER(TRIM(COALESCE(lemail, \'\'))) = LOWER(TRIM(:email))
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

    public function isSessionCurrent(array $claims): bool
    {
        $userId = (int) ($claims['sub'] ?? 0);
        if ($userId <= 0 || !$this->sessionVersionColumnExists()) {
            return $userId > 0;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT lsession_version FROM tblaccount WHERE lid = :user_id AND COALESCE(lstatus, 0) = 1 LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $current = $stmt->fetchColumn();

        return $current !== false && (int) $current === (int) ($claims['session_version'] ?? 0);
    }

    public function sessionVersion(int $userId): int
    {
        if ($userId <= 0 || !$this->sessionVersionColumnExists()) {
            return 0;
        }

        $stmt = $this->db->pdo()->prepare('SELECT lsession_version FROM tblaccount WHERE lid = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        return max(0, (int) ($stmt->fetchColumn() ?: 0));
    }

    public function changeStaffPassword(int $mainId, int $staffId, string $password): bool
    {
        if (!$this->sessionVersionColumnExists()) {
            throw new HttpException(500, 'Password changes require api/migrations/041_add_account_session_version.sql');
        }

        $staff = $this->findUserById($staffId);
        if ($staff === null || (int) ($staff['lmother_id'] ?? 0) !== $mainId || (int) ($staff['ltype'] ?? 0) === 1) {
            return false;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE tblaccount
                 SET lpassword = :password, lsession_version = COALESCE(lsession_version, 0) + 1
                 WHERE lid = :staff_id AND lmother_id = :main_id AND ltype <> 1 AND COALESCE(lstatus, 0) = 1
                 LIMIT 1'
            );
            $stmt->execute([
                'password' => $this->hashLegacyPassword($password),
                'staff_id' => $staffId,
                'main_id' => $mainId,
            ]);

            // Device registration is an authorization binding. It must be rebuilt after re-authentication.
            $devices = $pdo->prepare('DELETE FROM tblcall_devices WHERE lagent_id = :staff_id');
            $devices->execute(['staff_id' => $staffId]);
            $changed = $stmt->rowCount() > 0;
            $pdo->commit();
            return $changed;
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    private function sessionVersionColumnExists(): bool
    {
        if ($this->hasSessionVersionColumn !== null) {
            return $this->hasSessionVersionColumn;
        }

        $stmt = $this->db->pdo()->query("SHOW COLUMNS FROM tblaccount LIKE 'lsession_version'");
        $this->hasSessionVersionColumn = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        return $this->hasSessionVersionColumn;
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
        $hasStoredRights = array_key_exists('laccess_rights', $user) && $user['laccess_rights'] !== null;
        $storedRights = $this->decodeAccessRights($user['laccess_rights'] ?? null);
        if ($hasStoredRights) {
            return $storedRights;
        }

        $mainUserId = $this->resolveMainUserId($user);
        $groupId = (int) ($user['ltype'] ?? 0);
        if ($mainUserId <= 0 || $groupId <= 0) {
            return ['home'];
        }

        $roleName = strtolower(trim((string) ($this->getRoleName($groupId) ?? '')));
        if (in_array($roleName, ['owner', 'company owner'], true)) {
            return ['*'];
        }

        $rights = $this->legacyPermissions->getAccessRightsForGroup($mainUserId, $groupId);
        if (count(array_diff($rights, ['home'])) === 0) {
            $defaultRights = $this->getCoreRoleDefaultRights($roleName);
            if ($defaultRights !== []) {
                return $defaultRights;
            }
        }

        return $rights;
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

        if ($this->accountAccessRightsColumnExists()) {
            // Store the permissions as the user's access_rights when the column exists.
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

    private function accountAccessRightsColumnExists(): bool
    {
        if ($this->hasAccountAccessRightsColumn !== null) {
            return $this->hasAccountAccessRightsColumn;
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tblaccount'
               AND COLUMN_NAME = 'laccess_rights'"
        );
        $stmt->execute();

        $this->hasAccountAccessRightsColumn = (int) $stmt->fetchColumn() > 0;
        return $this->hasAccountAccessRightsColumn;
    }

    /**
     * @return array<int, string>
     */
    private function decodeAccessRights($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * @return array<int, string>
     */
    private function getCoreRoleDefaultRights(string $roleName): array
    {
        return match ($roleName) {
            'sales agent', 'sales person', 'salesperson' => [
                'home',
                'sales-database-customer-database',
                'sales-transaction-sales-inquiry',
                'sales-transaction-sales-order',
                'sales-transaction-order-slip',
                'sales-transaction-invoice',
                'sales-transaction-daily-call-monitoring',
                'sales-transaction-product-promotions',
                'sales-reports-inquiry-report',
                'sales-reports-sales-report',
                'sales-reports-sales-development-report',
                'communication-productivity-calendar',
            ],
            'warehouse', 'warehouse staff', 'warehouse personnel' => [
                'home',
                'warehouse-inventory-product-database',
                'warehouse-inventory-stock-movement',
                'warehouse-inventory-stock-adjustment',
                'warehouse-inventory-inventory-audit',
                'warehouse-purchasing-purchase-request',
                'warehouse-purchasing-purchase-order',
                'warehouse-purchasing-receiving-stock',
                'warehouse-purchasing-return-to-supplier',
                'warehouse-reports-inventory-report',
                'warehouse-reports-reorder-report',
                'warehouse-reports-item-suggested-for-stock-report',
                'warehouse-reports-fast-slow-inventory-report',
                'warehouse-reports-incident-items-report',
            ],
            default => [],
        };
    }
}
