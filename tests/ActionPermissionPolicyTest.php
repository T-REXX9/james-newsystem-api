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
$assert(ActionPermissionPolicy::allows(['can_post' => false], 'post', false) === false, 'denies a disabled post action');
$assert(ActionPermissionPolicy::allows(['can_delete' => false], 'delete', true) === true, 'Master User bypasses disabled delete');
$assert(ActionPermissionPolicy::normalize(['can_add' => 0])['can_add'] === false, 'normalizes legacy numeric flags');
$assert(ActionPermissionPolicy::normalize(null)['can_unpost'] === true, 'preserves backward-compatible defaults');
