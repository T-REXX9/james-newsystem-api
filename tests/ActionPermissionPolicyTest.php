<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/ActionPermissionPolicy.php';

use App\Support\ActionPermissionPolicy;

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: {$label}");
    }
    echo "PASS: {$label}\n";
};

$assert(ActionPermissionPolicy::allows(['can_edit' => false], 'edit', false) === false, 'denies a disabled edit action');
$assert(ActionPermissionPolicy::allows(['can_view' => false], 'view', false) === false, 'denies a disabled view action');
$assert(ActionPermissionPolicy::allows(['can_approve' => false], 'approve', false) === false, 'denies a disabled approve action');
$assert(ActionPermissionPolicy::allows(['can_post' => false], 'post', false) === false, 'denies a disabled post action');
$assert(ActionPermissionPolicy::allows(['can_delete' => false], 'delete', true) === true, 'Master User bypasses disabled delete');
$assert(ActionPermissionPolicy::normalize(['can_add' => 0])['can_add'] === false, 'normalizes legacy numeric flags');
$assert(ActionPermissionPolicy::normalize(null)['can_unpost'] === true, 'preserves backward-compatible defaults');
$pagePermissions = [
    'global' => ActionPermissionPolicy::DEFAULTS,
    'pages' => [
        'Sales Inquiry' => ['can_delete' => true],
        'Product Database' => ['can_delete' => false],
    ],
];
$assert(ActionPermissionPolicy::allows($pagePermissions, 'delete', false, 'Sales Inquiry') === true, 'allows a page-specific action');
$assert(ActionPermissionPolicy::allows($pagePermissions, 'delete', false, 'Product Database') === false, 'isolates page-specific actions');
$assert(ActionPermissionPolicy::allows([
    'global' => ['can_approve' => true],
    'pages' => ['Sales Inquiry' => ['can_approve' => true], 'Product Database' => ['can_approve' => false]],
], 'approve', false, 'Product Database') === false, 'isolates page-specific approve permissions');
$assert(ActionPermissionPolicy::allows([
    'global' => ['can_view' => true],
    'pages' => ['Product Database' => ['can_view' => false]],
], 'view', false, 'Product Database') === false, 'isolates page-specific view permissions');
