<?php

declare(strict_types=1);

/**
 * Daily Call former-name regression test.
 *
 * Ensures a renamed customer is returned through both Daily Call datasets
 * when staff search with the company name they knew before the rename.
 *
 * Run: php api/tests/DailyCallOldNameSearchTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\CustomerDatabaseRepository;
use App\Repositories\DailyCallMonitoringRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$customers = new CustomerDatabaseRepository($db);
$dailyCall = new DailyCallMonitoringRepository($db);
$mainId = 1;
$sessionId = 'DAILY-CALL-OLD-NAME-' . date('YmdHis') . '-' . random_int(1000, 9999);
$previousName = 'Masbate Calibration Regression';
$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }

    $failed++;
    echo "FAIL: {$label}\n";
};

$find = static function (array $items) use ($sessionId): ?array {
    foreach ($items as $item) {
        if ((string) ($item['id'] ?? '') === $sessionId) {
            return $item;
        }
    }
    return null;
};

try {
    $customers->createCustomer($mainId, 1, [
        'session_id' => $sessionId,
        'company' => 'Junvill Automotive Regression',
        'status' => 1,
        'profile_type' => 'Old',
    ]);
    $pdo->prepare(
        'INSERT INTO tlbCustomer_Details (lsessionid, loldname, ldate)
         VALUES (:session_id, :old_name, CURRENT_DATE)'
    )->execute([
        'session_id' => $sessionId,
        'old_name' => $previousName,
    ]);

    $masterMatch = $find($dailyCall->getPurchaseMasterList($mainId, '2025-10-01', $previousName)['items'] ?? []);
    $assert($masterMatch !== null, 'master Daily Call search finds a former company name');
    $assert((string) ($masterMatch['pastName'] ?? '') === $previousName, 'master Daily Call returns the former company name');

    $excelMatch = $find($dailyCall->getExcelRows($mainId, 'all', $previousName));
    $assert($excelMatch !== null, 'agent Daily Call search finds a former company name');
    $assert((string) ($excelMatch['pastName'] ?? '') === $previousName, 'agent Daily Call returns the former company name');
} finally {
    $pdo->prepare('DELETE FROM tlbCustomer_Details WHERE lsessionid = :session_id')->execute(['session_id' => $sessionId]);
    $pdo->prepare('DELETE FROM tblpatient WHERE lsessionid = :session_id AND lmain_id = :main_id')->execute([
        'session_id' => $sessionId,
        'main_id' => $mainId,
    ]);
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
