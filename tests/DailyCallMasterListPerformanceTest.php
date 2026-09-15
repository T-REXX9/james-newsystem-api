<?php

declare(strict_types=1);

/**
 * Performance regression check for the owner Daily Call Master List.
 *
 * Run: php api/tests/DailyCallMasterListPerformanceTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\DailyCallMonitoringRepository;

$repo = new DailyCallMonitoringRepository(new Database(app_config()));
$startedAt = hrtime(true);
$result = $repo->getPurchaseMasterList(1);
$elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

if (!is_array($result['items'] ?? null)) {
    fwrite(STDERR, "FAIL: Master List did not return rows\n");
    exit(1);
}

// This formerly exceeded 30 seconds because each purchase row executed two
// correlated scans to count later active months. Allow modest CI/database
// variance while catching any return to a multi-second dashboard load.
if ($elapsedMs >= 3000) {
    fwrite(STDERR, sprintf("FAIL: Master List took %.1f ms (budget: < 3000 ms)\n", $elapsedMs));
    exit(1);
}

printf("PASS: Master List returned %d rows in %.1f ms\n", count($result['items']), $elapsedMs);
