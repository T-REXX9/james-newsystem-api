<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\RolePermissionRepository;
use App\Security\TokenService;
use App\Support\Exceptions\HttpException;

/**
 * Middleware that validates permissions before allowing access to protected endpoints.
 *
 * Checks three levels:
 * 1. Authentication (valid JWT token)
 * 2. Module-level access (user's role has access to the requested module)
 * 3. Action-level access (user's role can perform add/edit/delete on the module)
 */
final class PermissionMiddleware
{
    public function __construct(
        private readonly TokenService $tokenService,
        private readonly RolePermissionRepository $rolePermissionRepo
    ) {
    }

    /**
     * Validate that the authenticated user has access to the requested endpoint.
     *
     * @param string|null $requiredModule The module ID required for this endpoint (null = auth only)
     * @param string|null $requiredAction The action required: 'add', 'edit', 'delete', or null for read-only
     * @return array The JWT claims if validation passes
     * @throws HttpException If access is denied
     */
    public function validate(?string $requiredModule = null, ?string $requiredAction = null): array
    {
        // Step 1: Extract and verify JWT token
        $claims = $this->extractAuthClaims();

        // Owner (user_type=1) has full access to everything
        $userType = (string) ($claims['user_type'] ?? '');
        if ($userType === '1') {
            return $claims;
        }

        // Step 2: If a module is required, validate module-level access
        if ($requiredModule !== null) {
            $mainUserId = (int) ($claims['main_userid'] ?? 0);
            $groupId = (int) ($claims['logintype'] ?? 0);

            if ($mainUserId <= 0 || $groupId <= 0) {
                throw new HttpException(403, 'Forbidden: Unable to determine user permissions');
            }

            $permissions = $this->rolePermissionRepo->getPermissionsForRole($mainUserId, $groupId);

            if (!in_array($requiredModule, $permissions, true) && !in_array('*', $permissions, true)) {
                throw new HttpException(403, "Forbidden: You do not have access to the '{$requiredModule}' module");
            }

            // Step 3: If an action is required, validate action-level access
            if ($requiredAction !== null) {
                $this->validateActionPermission($mainUserId, $groupId, $requiredAction);
            }
        }

        return $claims;
    }

    /**
     * Check if the user has permission to perform a specific action.
     *
     * @throws HttpException If the action is not allowed
     */
    private function validateActionPermission(int $mainId, int $groupId, string $action): void
    {
        $actionPerms = $this->rolePermissionRepo->getActionPermissions($mainId, $groupId);

        // If no action permissions are defined, default to allowed
        if (empty($actionPerms)) {
            return;
        }

        $actionField = match ($action) {
            'add' => 'can_add',
            'edit' => 'can_edit',
            'delete' => 'can_delete',
            default => null,
        };

        if ($actionField === null) {
            return;
        }

        // Check if any permission row grants this action
        foreach ($actionPerms as $perm) {
            if ($perm[$actionField] ?? false) {
                return;
            }
        }

        throw new HttpException(403, "Forbidden: You do not have permission to {$action} in this module");
    }

    /**
     * Extract and verify JWT claims from the Authorization header.
     *
     * @return array The decoded JWT claims
     * @throws HttpException If no valid token is present
     */
    private function extractAuthClaims(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
        if (!is_string($header) || trim($header) === '') {
            throw new HttpException(401, 'Authorization header is required');
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            throw new HttpException(401, 'Bearer token is required');
        }

        return $this->tokenService->verify((string) $matches[1]);
    }

    /**
     * Convenience method: validate authentication only (no module/action check).
     *
     * @return array The JWT claims
     */
    public function requireAuth(): array
    {
        return $this->validate();
    }

    /**
     * Convenience method: validate module access for read operations.
     *
     * @return array The JWT claims
     */
    public function requireModuleAccess(string $moduleId): array
    {
        return $this->validate($moduleId);
    }

    /**
     * Convenience method: validate module access with a specific action.
     *
     * @return array The JWT claims
     */
    public function requireActionAccess(string $moduleId, string $action): array
    {
        return $this->validate($moduleId, $action);
    }

    /**
     * Get action permissions for the authenticated user's role.
     * Returns an associative array of module_id => {can_add, can_edit, can_delete}.
     *
     * @return array<string, array{can_add: bool, can_edit: bool, can_delete: bool}>
     */
    public function getActionPermissionsForUser(array $claims): array
    {
        $userType = (string) ($claims['user_type'] ?? '');
        if ($userType === '1') {
            // Owner gets all action permissions
            return [];
        }

        $mainUserId = (int) ($claims['main_userid'] ?? 0);
        $groupId = (int) ($claims['logintype'] ?? 0);

        if ($mainUserId <= 0 || $groupId <= 0) {
            return [];
        }

        return $this->rolePermissionRepo->getActionPermissionsByModule($mainUserId, $groupId);
    }
}
