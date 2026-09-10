<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/DailyCallAccessPolicy.php';

use App\Support\DailyCallAccessPolicy;

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: {$label}");
    }
    echo "PASS: {$label}\n";
};

$assert(
    DailyCallAccessPolicy::canViewAll('1', 'Master User') === true,
    'Master User can view all Daily Call customers'
);
$assert(
    DailyCallAccessPolicy::canViewAll('2', 'Accountant') === true,
    'Accountant can view all Daily Call customers'
);
$assert(
    DailyCallAccessPolicy::canViewAll('2', 'Assistant Accountant') === true,
    'Assistant Accountant can view all Daily Call customers'
);
$assert(
    DailyCallAccessPolicy::canViewAll('2', 'Sales Person') === false,
    'Sales Person cannot view all Daily Call customers'
);
$assert(
    DailyCallAccessPolicy::isCustomerAssignedToViewer('63', '63') === true
        && DailyCallAccessPolicy::isCustomerAssignedToViewer('63', '64') === false,
    'Sales Person access is limited to the assigned account'
);
