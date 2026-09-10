<?php

declare(strict_types=1);

namespace App\Support;

final class DailyCallAccessPolicy
{
    public static function canViewAll(string $userType, string $roleName): bool
    {
        if (trim($userType) === '1') {
            return true;
        }

        $role = strtolower(trim($roleName));
        return in_array($role, ['accountant', 'assistant accountant'], true);
    }

    public static function isCustomerAssignedToViewer(string $assignedUserId, string $viewerUserId): bool
    {
        $assigned = trim($assignedUserId);
        $viewer = trim($viewerUserId);
        return $assigned !== '' && $viewer !== '' && $assigned === $viewer;
    }
}
