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
 * 3. Action-level access (the account can perform add/edit/delete/post/unpost)
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
     * @param string|null $requiredAction The action required: 'add', 'edit', 'delete', 'post', 'unpost', or null for read-only
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
                $this->assertActionPermission($claims, $requiredAction, $requiredModule);
            }
        }

        return $claims;
    }

    /**
     * Check if the user has permission to perform a specific action.
     *
     * @throws HttpException If the action is not allowed
     */
    public function assertActionPermission(array $claims, string $action, ?string $page = null): void
    {
        if ((string) ($claims['user_type'] ?? '') === '1') {
            return;
        }

        $mainId = (int) ($claims['main_userid'] ?? 0);
        $accountId = (int) ($claims['sub'] ?? 0);
        $groupId = (int) ($claims['logintype'] ?? 0);
        if ($mainId <= 0 || $accountId <= 0 || $groupId <= 0) {
            throw new HttpException(403, 'Forbidden: Unable to determine user permissions');
        }

        $permissions = $this->rolePermissionRepo->getActionPermissionsForAccount($mainId, $accountId, $groupId);
        if (!\App\Support\ActionPermissionPolicy::allows($permissions, $action, false, $page)) {
            throw new HttpException(403, "Forbidden: You do not have permission to {$action}");
        }
    }

    /**
     * Enforce Backdated posting rules for a document-date write.
     *
     * @throws HttpException
     */
    public function assertDocumentDateWrite(array $claims, string $proposedYmd, ?string $previousYmd): void
    {
        $isMaster = (string) ($claims['user_type'] ?? '') === '1';
        $permissions = $this->getActionPermissionsForUser($claims);
        $hasBackdate = \App\Support\ActionPermissionPolicy::allows($permissions, 'backdate', $isMaster);
        $result = \App\Support\DocumentDatePolicy::validateWrite(
            $hasBackdate,
            $proposedYmd,
            $previousYmd,
            \App\Support\DocumentDatePolicy::todayYmd()
        );
        if (!($result['ok'] ?? false)) {
            throw new HttpException(403, (string) ($result['reason'] ?? 'Document date is not allowed.'));
        }
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
     * Get action permissions for the authenticated account.
     *
     * @return array<string, bool>
     */
    public function getActionPermissionsForUser(array $claims): array
    {
        $userType = (string) ($claims['user_type'] ?? '');
        if ($userType === '1') {
            return \App\Support\ActionPermissionPolicy::DEFAULTS;
        }

        $mainUserId = (int) ($claims['main_userid'] ?? 0);
        $groupId = (int) ($claims['logintype'] ?? 0);

        if ($mainUserId <= 0 || $groupId <= 0) {
            return [];
        }

        return $this->rolePermissionRepo->getActionPermissionsForAccount(
            $mainUserId,
            (int) ($claims['sub'] ?? 0),
            $groupId
        );
    }
}
