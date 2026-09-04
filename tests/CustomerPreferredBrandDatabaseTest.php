<?php

declare(strict_types=1);

/**
 * Preferred brand unit + database regression tests.
 *
 * Covers:
 * - column presence after migration 030
 * - normalizePreferredBrand accepted values
 * - create/update/list customer preferred_brand
 * - daily call excel rows expose preferredBrand
 *
 * Run: php api/tests/CustomerPreferredBrandDatabaseTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\CustomerDatabaseRepository;
use App\Repositories\DailyCallMonitoringRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$passed = 0;
$failed = 0;
$errors = [];

$assert = static function (bool $ok, string $label) use (&$passed, &$failed, &$errors): void {
    if ($ok) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }
    $failed++;
    $errors[] = $label;
    echo "FAIL: {$label}\n";
};

$assertEq = static function (mixed $expected, mixed $actual, string $label) use ($assert): void {
    $assert($expected === $actual, $label . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual));
};

echo "==========================================================\n";
echo " Customer Preferred Brand Database Test\n";
echo "==========================================================\n\n";

$columnExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'tblpatient'
       AND COLUMN_NAME = 'lpreferred_brand'"
)->fetchColumn();
$assert($columnExists === 1, 'tblpatient.lpreferred_brand column exists (migration 030 applied)');

$customers = new CustomerDatabaseRepository($db);
$normalizeCustomer = new ReflectionMethod(CustomerDatabaseRepository::class, 'normalizePreferredBrand');
$assertEq('Ishinomoto', $normalizeCustomer->invoke($customers, 'ishinomoto'), 'Customer normalize accepts ishinomoto');
$assertEq('Ishinomoto', $normalizeCustomer->invoke($customers, 'ISHINOMOTO'), 'Customer normalize accepts ISHINOMOTO');
$assertEq('Others', $normalizeCustomer->invoke($customers, 'others'), 'Customer normalize accepts others');
$assertEq('Others', $normalizeCustomer->invoke($customers, 'other'), 'Customer normalize accepts other');
$assertEq('', $normalizeCustomer->invoke($customers, 'Motul'), 'Customer normalize rejects unknown brand');
$assertEq('', $normalizeCustomer->invoke($customers, ''), 'Customer normalize blank stays blank');

$daily = new DailyCallMonitoringRepository($db);
$normalizeDaily = new ReflectionMethod(DailyCallMonitoringRepository::class, 'normalizePreferredBrand');
$assertEq('Ishinomoto', $normalizeDaily->invoke($daily, ' ishinomoto '), 'Daily call normalize accepts ishinomoto');
$assertEq('Others', $normalizeDaily->invoke($daily, 'Others'), 'Daily call normalize accepts Others');

$mainId = 1;
$sessionId = 'PREFBRAND-TEST-' . date('YmdHis') . '-' . random_int(1000, 9999);
$userId = 1;

try {
    $created = $customers->createCustomer($mainId, $userId, [
        'session_id' => $sessionId,
        'company' => 'Preferred Brand Test Co',
        'preferred_brand' => 'ishinomoto',
        'debt_type' => 'Good',
        'status' => 1,
        'profile_type' => 'Old',
        'phone' => '09171234567',
        'mobile' => '09171234567',
    ]);
    $assertEq('Ishinomoto', (string) ($created['preferred_brand'] ?? ''), 'createCustomer stores preferred_brand as Ishinomoto');

    $fetched = $customers->getCustomer($mainId, $sessionId);
    $assert($fetched !== null, 'getCustomer returns created preferred-brand customer');
    $assertEq('Ishinomoto', (string) ($fetched['preferred_brand'] ?? ''), 'getCustomer returns preferred_brand');

    $listed = $customers->listCustomers($mainId, 'Preferred Brand Test Co', 'all', 1, 50, 'full');
    $match = null;
    foreach (($listed['items'] ?? []) as $item) {
        if ((string) ($item['session_id'] ?? '') === $sessionId) {
            $match = $item;
            break;
        }
    }
    $assert($match !== null, 'listCustomers includes preferred-brand customer');
    $assertEq('Ishinomoto', (string) ($match['preferred_brand'] ?? ''), 'listCustomers returns preferred_brand without SQL error');

    $updated = $customers->updateCustomer($mainId, $sessionId, [
        'preferred_brand' => 'others',
        'user_id' => $userId,
    ]);
    $assertEq('Others', (string) ($updated['preferred_brand'] ?? ''), 'updateCustomer can change preferred_brand to Others');

    $cleared = $customers->updateCustomer($mainId, $sessionId, [
        'preferred_brand' => '',
        'user_id' => $userId,
    ]);
    $assertEq('', (string) ($cleared['preferred_brand'] ?? ''), 'updateCustomer can clear preferred_brand');

    $customers->updateCustomer($mainId, $sessionId, [
        'preferred_brand' => 'Ishinomoto',
        'user_id' => $userId,
    ]);

    $excelRows = $daily->getExcelRows($mainId, 'all', 'Preferred Brand Test Co', null);
    $excelMatch = null;
    foreach ($excelRows as $row) {
        if ((string) ($row['id'] ?? '') === $sessionId) {
            $excelMatch = $row;
            break;
        }
    }
    $assert($excelMatch !== null, 'daily call excel rows include preferred-brand customer');
    $assertEq('Ishinomoto', (string) ($excelMatch['preferredBrand'] ?? ''), 'daily call excel row exposes preferredBrand');
} finally {
    $pdo->prepare('DELETE FROM tblcontact_person WHERE lrefno = :session_id')->execute(['session_id' => $sessionId]);
    $pdo->prepare('DELETE FROM tblpatient_terms WHERE lpatient = :session_id')->execute(['session_id' => $sessionId]);
    $pdo->prepare('DELETE FROM tblpatient WHERE lsessionid = :session_id AND lmain_id = :main_id')->execute([
        'session_id' => $sessionId,
        'main_id' => $mainId,
    ]);
}

echo "\n==========================================================\n";
echo " Results: {$passed} passed, {$failed} failed\n";
echo "==========================================================\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    exit(1);
}

echo "\nAll preferred brand database checks passed.\n";
