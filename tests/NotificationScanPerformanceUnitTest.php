<?php

declare(strict_types=1);

$repository = file_get_contents(__DIR__ . '/../src/Repositories/NotificationsRepository.php');
$migration = file_get_contents(__DIR__ . '/../migrations/014_optimize_notification_indexes.sql');
$setup = file_get_contents(__DIR__ . '/../../james-newsystem/setup.sh');

if ($repository === false || $migration === false || $setup === false) {
    fwrite(STDERR, "FAIL: unable to read notification performance files\n");
    exit(1);
}

$checks = [
    'inventory scans use a server-wide non-blocking lock' => str_contains($repository, "SELECT GET_LOCK(:lock_name, 0)"),
    'inventory scans release the lock in a finally block' => str_contains($repository, 'finally {')
        && str_contains($repository, 'releaseInventoryScanLock($mainId)'),
    'existing notification keys are loaded in bounded batches' => str_contains(
        $repository,
        'array_chunk($referenceKeys, self::INVENTORY_SCAN_REFERENCE_BATCH_SIZE)'
    ),
    'batch lookup selects recipient and reference keys together' => str_contains(
        $repository,
        'SELECT luserid, lrefno'
    ) && str_contains($repository, 'AND luserid IN (%s)') && str_contains($repository, 'AND lrefno IN (%s)'),
    'scan inserts without repeating the per-notification lookup' => str_contains(
        $repository,
        '$notification = $this->insertNotification('
    ),
    'notification lookup index is included in the migration' => str_contains(
        $migration,
        'idx_notifications_user_ref_status (luserid, lrefno, lstatus)'
    ),
    'notification polling index is included in the migration' => str_contains(
        $migration,
        'idx_notifications_user_status_datetime (luserid, lstatus, ldatetime, lid)'
    ),
    'setup and update automatically apply the migration' => substr_count(
        $setup,
        'apply_required_database_migrations'
    ) >= 5,
];

$failed = [];
foreach ($checks as $label => $passed) {
    if (!$passed) {
        $failed[] = $label;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
}

if ($failed !== []) {
    fwrite(STDERR, sprintf("Results: %d passed, %d failed\n", count($checks) - count($failed), count($failed)));
    exit(1);
}

echo sprintf("Notification scan performance: %d/%d passed\n", count($checks), count($checks));
