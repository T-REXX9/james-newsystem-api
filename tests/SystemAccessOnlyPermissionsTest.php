<?php

declare(strict_types=1);

/**
 * Permissions come from System Access only.
 *
 * Routes gate every write through $requireActionAuth(page, action), which reads
 * the Page Action Permissions an admin grants. Repositories used to re-decide
 * the same thing from role names written into the source, which silently
 * overrode System Access: granting can_unpost still produced a 422 for anyone
 * outside that list. This locks the repositories out of that decision.
 */

$root = dirname(__DIR__);

$read = static function (string $relative) use ($root): string {
    $contents = file_get_contents($root . '/' . $relative);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$relative}");
    }
    return $contents;
};

/** Role names that were previously hardcoded into authorization decisions. */
$hardcodedRoleNames = [
    'purchasing manager',
    'warehouse manager',
    'administrator',
];

/** Repositories that must not make their own authorization decisions. */
$documentRepositories = [
    'src/Repositories/PurchaseRequestRepository.php',
    'src/Repositories/PurchaseOrderRepository.php',
    'src/Repositories/ReceivingStockRepository.php',
];

$bootstrap = $read('src/bootstrap.php');
$policy = $read('src/Support/ActionPermissionPolicy.php');
$middleware = $read('src/Middleware/PermissionMiddleware.php');

$checks = [];

foreach ($documentRepositories as $relative) {
    $source = $read($relative);
    $name = basename($relative, '.php');

    $found = [];
    foreach ($hardcodedRoleNames as $role) {
        if (stripos($source, $role) !== false) {
            $found[] = $role;
        }
    }
    $checks["{$name} decides no permission from a role name"] = $found === []
        ? true
        : 'still mentions ' . implode(', ', $found);

    // ltype/ltype_name is how the old gates read a user's role. Reading it for
    // authorization is what we are removing.
    $checks["{$name} does not read a user's role to authorize"] =
        !str_contains($source, 'ltype_name') && !str_contains($source, 'assertPrivilegedAction');
}

// The single enforcement point still has to be there.
$checks['routes gate actions through System Access'] = str_contains($bootstrap, '$requireActionAuth')
    && str_contains($bootstrap, 'assertActionPermission');
$checks['unpost routes are gated'] = substr_count($bootstrap, "'unpost'") >= 10;
$checks['procurement unpost routes name their System Access page'] =
    str_contains($bootstrap, "'Purchase Request', 'unpost'")
    && str_contains($bootstrap, "'Purchase Order', 'unpost'")
    && str_contains($bootstrap, "'Receiving Stock', 'unpost'");
$checks['System Access maps unpost to can_unpost'] = str_contains($policy, "'unpost' => 'can_unpost'");
$checks['Master User still bypasses stored restrictions'] = str_contains($middleware, "=== '1'")
    && str_contains($policy, '$isMasterUser');
$checks['permission failures answer 403, not 422'] = str_contains($middleware, 'HttpException(403');

$failed = 0;
foreach ($checks as $name => $result) {
    if ($result === true) {
        echo "  PASS {$name}\n";
        continue;
    }
    $detail = is_string($result) ? " ({$result})" : '';
    echo "  FAIL {$name}{$detail}\n";
    $failed++;
}

echo 'Results: ' . (count($checks) - $failed) . ' passed, ' . $failed . " failed\n";
exit($failed === 0 ? 0 : 1);
