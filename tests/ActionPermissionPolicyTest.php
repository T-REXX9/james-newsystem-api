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
$assert(ActionPermissionPolicy::normalizePagePermissions(null)['can_backdate'] === false, 'backdated posting defaults off');
$assert(ActionPermissionPolicy::allows([
    'global' => ActionPermissionPolicy::DEFAULTS,
    'can_backdate' => true,
], 'backdate', false) === true, 'allows account-wide backdated posting when enabled');
$assert(ActionPermissionPolicy::allows([
    'global' => ActionPermissionPolicy::DEFAULTS,
    'can_backdate' => false,
], 'backdate', false) === false, 'denies account-wide backdated posting when disabled');
$assert(ActionPermissionPolicy::allows([
    'can_backdate' => false,
], 'backdate', true) === true, 'Master User bypasses backdated posting restriction');

require __DIR__ . '/../src/Support/DocumentDatePolicy.php';

use App\Support\DocumentDatePolicy;

$assert(DocumentDatePolicy::validateWrite(false, '2026-09-10', '2026-09-10', '2026-09-12')['ok'] === true, 'allows unchanged past date without backdate');
$assert(DocumentDatePolicy::validateWrite(false, '2026-09-10', '2026-09-12', '2026-09-12')['ok'] === false, 'rejects new past date without backdate');
$assert(DocumentDatePolicy::validateWrite(true, '2026-09-01', '2026-09-12', '2026-09-12')['ok'] === true, 'allows past date with backdate');
$assert(DocumentDatePolicy::validateWrite(true, '2026-09-20', '2026-09-12', '2026-09-12')['ok'] === false, 'rejects future document date');
