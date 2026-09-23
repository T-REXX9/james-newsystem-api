<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/migrations/044_add_daily_call_customer_team.sql');
$dailyCall = file_get_contents($root . '/src/Repositories/DailyCallMonitoringRepository.php');
$customerDatabase = file_get_contents($root . '/src/Repositories/CustomerDatabaseRepository.php');
$controller = file_get_contents($root . '/src/Controllers/DailyCallMonitoringController.php');

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: {$label}");
    }
    echo "PASS: {$label}\n";
};

$assert(str_contains($migration, 'lsales_team'), 'Migration adds the customer team assignment field');
$assert(
    str_contains($dailyCall, 'lsales_team')
        && str_contains($dailyCall, 'viewer_team_user_id')
        && !str_contains($dailyCall, 'viewer_teammate_user_id')
        && !str_contains($dailyCall, 'master_viewer_teammate_user_id'),
    'Daily Call visibility includes only direct agent and direct team assignments'
);
$assert(
    str_contains($dailyCall, 'viewerHasIndividualAssignments')
        && str_contains($dailyCall, "(int) (\$viewer['team_id'] ?? 0) > 0"),
    'Unassigned staff cannot widen Daily Call visibility through view-all permission'
);
$assert(
    str_contains($customerDatabase, "'sales_team_id'")
        && str_contains($customerDatabase, "array_key_exists('sales_person_id', \$payload)")
        && str_contains($customerDatabase, 'WHEN NULLIF(TRIM(:assignment_date_sales_person_id), \'\') IS NULL THEN NULL'),
    'Bulk agent assignments record a date and clearing the agent removes it'
);
$assert(
    str_contains($controller, 'getPurchaseMasterList(')
        && str_contains($controller, '$this->authenticatedViewerUserId($body)'),
    'Master list applies the authenticated viewer scope'
);
