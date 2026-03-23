<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\LegacyPermissionMapper;
use PDO;

final class RolePermissionRepository
{
    private LegacyPermissionMapper $legacyPermissions;

    /** @var array<string, array<int, string>> */
    private array $permissionCache = [];

    /** @var array<string, array<int, array{module_id: string, can_add: bool, can_edit: bool, can_delete: bool}>> */
    private array $actionPermissionCache = [];
    private ?bool $hasAccountAccessRightsColumn = null;

    public function __construct(private readonly Database $db)
    {
        $this->legacyPermissions = new LegacyPermissionMapper($db->pdo());
    }

    /**
     * Fetch permissions for a given role (ltype/group_id).
     *
     * @return array<int, string>
     */
    public function getPermissionsForRole(int $mainId, int $groupId): array
    {
        $cacheKey = "{$mainId}:{$groupId}";
        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }

        $rights = $this->legacyPermissions->getAccessRightsForGroup($mainId, $groupId);
        $this->permissionCache[$cacheKey] = $rights;

        return $rights;
    }

    /**
     * Check if a role has access to a specific module.
     */
    public function hasPermission(int $mainId, int $groupId, string $moduleId): bool
    {
        $permissions = $this->getPermissionsForRole($mainId, $groupId);
        return in_array($moduleId, $permissions, true);
    }

    /**
     * Get action-level permissions (add/edit/delete) for a role.
     *
     * @return array<int, array{page_id: int, module_id: string, can_add: bool, can_edit: bool, can_delete: bool}>
     */
    public function getActionPermissions(int $mainId, int $groupId): array
    {
        $cacheKey = "{$mainId}:{$groupId}";
        if (isset($this->actionPermissionCache[$cacheKey])) {
            return $this->actionPermissionCache[$cacheKey];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT lpageno, lstatus, ladd_action, ledit_action, ldelete_action
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
            $result[] = [
                'page_id' => (int) ($row['lpageno'] ?? 0),
                'can_add' => (int) ($row['ladd_action'] ?? 0) === 1,
                'can_edit' => (int) ($row['ledit_action'] ?? 0) === 1,
                'can_delete' => (int) ($row['ldelete_action'] ?? 0) === 1,
            ];
        }

        $this->actionPermissionCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Get action permissions mapped by module ID.
     *
     * @return array<string, array{can_add: bool, can_edit: bool, can_delete: bool}>
     */
    public function getActionPermissionsByModule(int $mainId, int $groupId): array
    {
        $actionPerms = $this->getActionPermissions($mainId, $groupId);
        $moduleActions = [];

        $moduleRights = $this->getPermissionsForRole($mainId, $groupId);

        // Map page-level action permissions to module-level
        // For Owner (type 1), grant all actions
        foreach ($moduleRights as $moduleId) {
            if ($moduleId === 'home') {
                continue;
            }
            $moduleActions[$moduleId] = [
                'can_add' => true,
                'can_edit' => true,
                'can_delete' => true,
            ];
        }

        // Override with actual action permissions from tblweb_permission
        foreach ($actionPerms as $perm) {
            // Find which modules correspond to this page_id
            $pageModules = $this->legacyPermissions->getAccessRightsForGroup($mainId, $groupId);
            foreach ($pageModules as $moduleId) {
                if (isset($moduleActions[$moduleId])) {
                    $moduleActions[$moduleId] = [
                        'can_add' => $perm['can_add'],
                        'can_edit' => $perm['can_edit'],
                        'can_delete' => $perm['can_delete'],
                    ];
                }
            }
        }

        return $moduleActions;
    }

    /**
     * Fetch all roles from tblusertype.
     *
     * @return array<int, array{id: int, name: string, description: string}>
     */
    public function getAllRoles(int $mainId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                CAST(ut.lid AS SIGNED) AS id,
                COALESCE(ut.ltype_name, \'\') AS name,
                COALESCE(ut.ldesc, \'\') AS description
             FROM tblusertype ut
             WHERE ut.lid != 7
               AND (
                 ut.lmain_id = :main_id
                 OR COALESCE(ut.lmain_id, 0) = 0
               )
             ORDER BY ut.ltype_name ASC'
        );
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get the default permissions for a role type.
     * Used when creating new users to assign initial permissions.
     *
     * @return array<int, string>
     */
    public function getRoleDefaultPermissions(int $mainId, int $groupId): array
    {
        return $this->getPermissionsForRole($mainId, $groupId);
    }

    /**
     * Update permissions for a role, with action-level control.
     *
     * @param array<int, string> $accessRights
     * @param array<string, array{can_add?: bool, can_edit?: bool, can_delete?: bool}> $actionPermissions
     */
    public function updateRolePermissions(
        int $mainId,
        int $groupId,
        array $accessRights,
        array $actionPermissions = []
    ): void {
        // Sync module-level permissions
        $this->legacyPermissions->syncGroupPermissions($mainId, $groupId, $accessRights);

        // If action permissions provided, update them
        if (!empty($actionPermissions)) {
            $this->updateActionPermissions($mainId, $groupId, $actionPermissions);
        }

        // Clear cache
        $cacheKey = "{$mainId}:{$groupId}";
        unset($this->permissionCache[$cacheKey]);
        unset($this->actionPermissionCache[$cacheKey]);
    }

    /**
     * Update action-level permissions for specific modules.
     *
     * @param array<string, array{can_add?: bool, can_edit?: bool, can_delete?: bool}> $actionPermissions
     */
    private function updateActionPermissions(int $mainId, int $groupId, array $actionPermissions): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tblweb_permission
             SET ladd_action = :can_add,
                 ledit_action = :can_edit,
                 ldelete_action = :can_delete
             WHERE lmain_id = :main_id
               AND lgroup = :group_id
               AND lpageno = :page_id'
        );

        foreach ($actionPermissions as $moduleId => $actions) {
            // We need to find the page_id for this module - use the existing permission rows
            $pageStmt = $this->db->pdo()->prepare(
                'SELECT lpageno FROM tblweb_permission
                 WHERE lmain_id = :main_id AND lgroup = :group_id AND lstatus = 1'
            );
            $pageStmt->execute(['main_id' => $mainId, 'group_id' => $groupId]);
            $pageIds = $pageStmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($pageIds as $pageId) {
                $stmt->execute([
                    'can_add' => ($actions['can_add'] ?? true) ? 1 : 0,
                    'can_edit' => ($actions['can_edit'] ?? true) ? 1 : 0,
                    'can_delete' => ($actions['can_delete'] ?? true) ? 1 : 0,
                    'main_id' => $mainId,
                    'group_id' => $groupId,
                    'page_id' => $pageId,
                ]);
            }
        }
    }

    /**
     * Log a permission change for audit trail.
     */
    public function logPermissionChange(
        int $mainId,
        int $groupId,
        string $action,
        string $changedBy,
        ?array $oldPermissions = null,
        ?array $newPermissions = null
    ): void {
        // Check if audit table exists, create if needed
        try {
            $this->db->pdo()->exec(
                'CREATE TABLE IF NOT EXISTS tblpermission_audit_log (
                    lid INT AUTO_INCREMENT PRIMARY KEY,
                    lmain_id INT NOT NULL,
                    lgroup_id INT NOT NULL,
                    laction VARCHAR(50) NOT NULL,
                    lchanged_by VARCHAR(255) NOT NULL,
                    lold_permissions TEXT,
                    lnew_permissions TEXT,
                    lcreated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )'
            );
        } catch (\Throwable $e) {
            // Table may already exist or we can't create it - continue
        }

        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO tblpermission_audit_log
                    (lmain_id, lgroup_id, laction, lchanged_by, lold_permissions, lnew_permissions)
                 VALUES
                    (:main_id, :group_id, :action, :changed_by, :old_permissions, :new_permissions)'
            );
            $stmt->execute([
                'main_id' => $mainId,
                'group_id' => $groupId,
                'action' => $action,
                'changed_by' => $changedBy,
                'old_permissions' => $oldPermissions !== null ? json_encode($oldPermissions) : null,
                'new_permissions' => $newPermissions !== null ? json_encode($newPermissions) : null,
            ]);
        } catch (\Throwable $e) {
            // Audit logging should not break the main flow
            error_log('Permission audit log failed: ' . $e->getMessage());
        }
    }

    /**
     * Initialize permissions for a newly created user based on their role.
     */
    public function initializeUserPermissions(int $mainId, int $userId, int $groupId): void
    {
        $rolePermissions = $this->getRoleDefaultPermissions($mainId, $groupId);

        // Older local databases may not have the optional laccess_rights column yet.
        if ($this->accountAccessRightsColumnExists()) {
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

        $this->logPermissionChange(
            $mainId,
            $groupId,
            'INIT_USER_PERMISSIONS',
            'system',
            null,
            $rolePermissions
        );
    }

    /**
     * Clear the permission cache (useful after updates).
     */
    public function clearCache(): void
    {
        $this->permissionCache = [];
        $this->actionPermissionCache = [];
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
}
