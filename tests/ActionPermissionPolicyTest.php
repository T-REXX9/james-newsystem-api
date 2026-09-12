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
$assert(ActionPermissionPolicy::normalize(null)['can_edit_invoice_number'] === false, 'edit invoice number defaults off');
$assert(ActionPermissionPolicy::allows([
    'global' => ActionPermissionPolicy::DEFAULTS,
    'pages' => ['Invoice' => ['can_edit_invoice_number' => true]],
], 'update_number', false, 'Invoice') === true, 'allows update_number when Invoice edit-number is enabled');
$assert(ActionPermissionPolicy::allows([
    'global' => ActionPermissionPolicy::DEFAULTS,
    'pages' => ['Invoice' => ['can_edit_invoice_number' => false]],
], 'update_number', false, 'Invoice') === false, 'denies update_number when Invoice edit-number is disabled');
$assert(ActionPermissionPolicy::allows([
    'global' => ActionPermissionPolicy::DEFAULTS,
    'pages' => ['Invoice' => ['can_edit' => true, 'can_edit_invoice_number' => false]],
], 'update_number', false, 'Invoice') === false, 'general edit does not grant edit invoice number');
$assert(ActionPermissionPolicy::allows([
    'pages' => ['Invoice' => ['can_edit_invoice_number' => false]],
], 'update_number', true, 'Invoice') === true, 'Master User bypasses edit invoice number restriction');
$assert(ActionPermissionPolicy::normalize(null)['can_edit_unit_price'] === false, 'edit unit price defaults off');
$assert(ActionPermissionPolicy::allows([
    'global' => ActionPermissionPolicy::DEFAULTS,
    'pages' => ['Sales Inquiry' => ['can_edit_unit_price' => true]],
], 'edit_unit_price', false, 'Sales Inquiry') === true, 'allows edit_unit_price when Sales Inquiry permission is enabled');
$assert(ActionPermissionPolicy::allows([
    'global' => ActionPermissionPolicy::DEFAULTS,
    'pages' => ['Sales Inquiry' => ['can_edit' => true, 'can_edit_unit_price' => false]],
], 'edit_unit_price', false, 'Sales Inquiry') === false, 'general edit does not grant edit unit price');
$assert(ActionPermissionPolicy::allows([
    'pages' => ['Sales Inquiry' => ['can_edit_unit_price' => false]],
], 'edit_unit_price', true, 'Sales Inquiry') === true, 'Master User bypasses edit unit price restriction');
