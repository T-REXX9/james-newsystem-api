<?php

declare(strict_types=1);

namespace App\Support;

final class ActionPermissionPolicy
{
    /** @var array<string, bool> */
    public const DEFAULTS = [
        'can_add' => true,
        'can_edit' => true,
        'can_delete' => true,
        'can_post' => true,
        'can_unpost' => true,
    ];

    /**
     * @param array<string, mixed>|null $permissions
     * @return array<string, bool>
     */
    public static function normalize(?array $permissions): array
    {
        $normalized = self::DEFAULTS;
        foreach (array_keys($normalized) as $key) {
            if (is_bool($permissions[$key] ?? null)) {
                $normalized[$key] = $permissions[$key];
            } elseif (isset($permissions[$key])) {
                $normalized[$key] = (int) $permissions[$key] === 1;
            }
        }

        return $normalized;
    }

    /**
     * Normalize the new page-scoped shape while accepting legacy flat values.
     *
     * @return array{global: array<string, bool>, pages: array<string, array<string, bool>>}
     */
    public static function normalizePagePermissions(?array $permissions): array
    {
        $permissions ??= [];
        $global = self::normalize(is_array($permissions['global'] ?? null) ? $permissions['global'] : $permissions);
        $pages = [];
        foreach (($permissions['pages'] ?? []) as $page => $pagePermissions) {
            if (!is_string($page) || !is_array($pagePermissions)) continue;
            $pages[$page] = self::normalize(array_merge($global, $pagePermissions));
        }

        return ['global' => $global, 'pages' => $pages];
    }

    /**
     * Master Users bypass every stored action restriction.
     *
     * @param array<string, mixed>|null $permissions
     */
    public static function allows(?array $permissions, string $action, bool $isMasterUser, ?string $page = null): bool
    {
        if ($isMasterUser) {
            return true;
        }

        $field = match ($action) {
            'add' => 'can_add',
            'edit' => 'can_edit',
            'delete' => 'can_delete',
            'post' => 'can_post',
            'unpost' => 'can_unpost',
            default => null,
        };

        if ($field === null) return true;
        $normalized = self::normalizePagePermissions($permissions);
        $pagePermissions = $page !== null && isset($normalized['pages'][$page])
            ? $normalized['pages'][$page]
            : $normalized['global'];
        return $pagePermissions[$field];
    }
}
