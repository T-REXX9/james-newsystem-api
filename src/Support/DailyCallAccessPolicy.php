<?php

declare(strict_types=1);

namespace App\Support;

final class DailyCallAccessPolicy
{
    /** The System Access page that owns the Daily Call viewing scope. */
    public const PAGE = 'Daily Call Monitoring';

    /**
     * Whether the viewer sees every customer instead of only their assigned ones.
     *
     * This used to be a list of role names held in this file, which meant an
     * admin could not grant or revoke it. It is now the "See all records"
     * Page Action Permission, configured on System Access.
     *
     * @param array<string, mixed>|null $permissions resolved Page Action Permissions
     */
    public static function canViewAll(?array $permissions, bool $isMasterUser): bool
    {
        return ActionPermissionPolicy::allows($permissions, 'view_all_records', $isMasterUser, self::PAGE);
    }

    public static function isCustomerAssignedToViewer(string $assignedUserId, string $viewerUserId): bool
    {
        $assigned = trim($assignedUserId);
        $viewer = trim($viewerUserId);
        return $assigned !== '' && $viewer !== '' && $assigned === $viewer;
    }
}
