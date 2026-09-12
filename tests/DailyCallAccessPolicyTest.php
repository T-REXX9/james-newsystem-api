<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/ActionPermissionPolicy.php';
require __DIR__ . '/../src/Support/DailyCallAccessPolicy.php';

use App\Support\DailyCallAccessPolicy;

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: {$label}");
    }
    echo "PASS: {$label}\n";
};

/** Permissions as stored by System Access for one page. */
$onPage = static fn (bool $seesAll): array => [
    'pages' => [DailyCallAccessPolicy::PAGE => ['can_view_all_records' => $seesAll]],
];

$assert(
    DailyCallAccessPolicy::canViewAll(null, true) === true,
    'Master User can view all Daily Call customers'
);
$assert(
    DailyCallAccessPolicy::canViewAll($onPage(true), false) === true,
    'System Access can grant a staff account every Daily Call customer'
);
$assert(
    DailyCallAccessPolicy::canViewAll($onPage(false), false) === false,
    'System Access can limit a staff account to its assigned customers'
);
$assert(
    DailyCallAccessPolicy::canViewAll(null, false) === false,
    'an unconfigured staff account sees only its assigned customers'
);
$assert(
    DailyCallAccessPolicy::canViewAll(
        ['pages' => ['Customer Data' => ['can_view_all_records' => true]]],
        false
    ) === false,
    'the grant is per page, so another page does not widen Daily Call'
);
$assert(
    DailyCallAccessPolicy::isCustomerAssignedToViewer('63', '63') === true
        && DailyCallAccessPolicy::isCustomerAssignedToViewer('63', '64') === false,
    'assigned-only access is limited to the assigned account'
);
