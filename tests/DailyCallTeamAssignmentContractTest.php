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
    str_contains($dailyCall, 'lsales_team') && str_contains($dailyCall, 'viewer_team_user_id'),
    'Daily Call visibility includes the assigned team membership'
);
$assert(str_contains($customerDatabase, "'sales_team_id'"), 'Bulk customer updates accept team assignment');
$assert(
    str_contains($controller, '$viewerUserId = $this->authenticatedViewerUserId($body);')
        && str_contains($controller, 'getPurchaseMasterList($mainId, $fromDate, $search, $viewerUserId)'),
    'Master list applies authenticated viewer visibility filtering'
);
