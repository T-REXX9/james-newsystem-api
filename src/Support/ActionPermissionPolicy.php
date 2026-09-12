<?php

declare(strict_types=1);

namespace App\Support;

final class ActionPermissionPolicy
{
    /** @var array<string, bool> */
    public const DEFAULTS = [
        'can_view' => true,
        'can_approve' => true,
        'can_add' => true,
        'can_edit' => true,
        'can_delete' => true,
        'can_post' => true,
        'can_unpost' => true,
        // Booklet catch-up only; Master User enables explicitly on System Access.
        'can_edit_invoice_number' => false,
        // Catalog unit-price overrides on Sales Inquiry; Master User enables explicitly.
        'can_edit_unit_price' => false,
        // Widens a page from "only the records assigned to me" to every record.
        // Defaults off so nobody gains reach from an unconfigured account.
        'can_view_all_records' => false,
    ];

    /** Account-wide; defaults off. Not a per-page Page Action Permission. */
    public const DEFAULT_CAN_BACKDATE = false;

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
     * @return array{global: array<string, bool>, pages: array<string, array<string, bool>>, can_backdate: bool}
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

        $canBackdate = self::DEFAULT_CAN_BACKDATE;
        if (is_bool($permissions['can_backdate'] ?? null)) {
            $canBackdate = $permissions['can_backdate'];
        } elseif (isset($permissions['can_backdate'])) {
            $canBackdate = (int) $permissions['can_backdate'] === 1;
        }

        return ['global' => $global, 'pages' => $pages, 'can_backdate' => $canBackdate];
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

        if ($action === 'backdate' || $action === 'can_backdate') {
            return self::normalizePagePermissions($permissions)['can_backdate'];
        }

        $field = match ($action) {
            'view' => 'can_view',
            'approve' => 'can_approve',
            'add' => 'can_add',
            'edit' => 'can_edit',
            'delete' => 'can_delete',
            'post' => 'can_post',
            'unpost' => 'can_unpost',
            'update_number', 'edit_invoice_number' => 'can_edit_invoice_number',
            'edit_unit_price' => 'can_edit_unit_price',
            'view_all', 'view_all_records' => 'can_view_all_records',
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
