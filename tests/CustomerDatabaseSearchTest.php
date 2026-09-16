<?php

declare(strict_types=1);

/**
 * Customer Database search regression test.
 *
 * Verifies that full customer searches find a record using a contact-person
 * name or its former company name, and return the former name to the UI.
 *
 * Run: php api/tests/CustomerDatabaseSearchTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\CustomerDatabaseRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$customers = new CustomerDatabaseRepository($db);
$mainId = 1;
$sessionId = 'CUSTOMER-SEARCH-' . date('YmdHis') . '-' . random_int(1000, 9999);
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
        if ((string) ($item['session_id'] ?? '') === $sessionId) {
            return $item;
        }
    }
    return null;
};

try {
    $customers->createCustomer($mainId, 1, [
        'session_id' => $sessionId,
        'company' => 'Current Search Company',
        'status' => 1,
        'profile_type' => 'Old',
    ]);

    $pdo->prepare(
        'INSERT INTO tblcontact_person (lmainid, lrefno, lfname, lmname, llname)
         VALUES (:main_id, :session_id, :first_name, :middle_name, :last_name)'
    )->execute([
        'main_id' => (string) $mainId,
        'session_id' => $sessionId,
        'first_name' => 'Marisol',
        'middle_name' => 'Rivera',
        'last_name' => 'Santos',
    ]);
    $pdo->prepare(
        'INSERT INTO tlbCustomer_Details (lsessionid, loldname, ldate)
         VALUES (:session_id, :old_name, CURRENT_DATE)'
    )->execute([
        'session_id' => $sessionId,
        'old_name' => 'Former Search Company',
    ]);

    $contactResult = $customers->listCustomers($mainId, 'Rivera', 'all', 1, 50, 'full');
    $assert($find($contactResult['items']) !== null, 'full search finds a customer by contact-person middle name');

    $oldNameResult = $customers->listCustomers($mainId, 'Former Search Company', 'all', 1, 50, 'full');
    $oldNameMatch = $find($oldNameResult['items']);
    $assert($oldNameMatch !== null, 'full search finds a customer by former company name');
    $assert((string) ($oldNameMatch['old_name'] ?? '') === 'Former Search Company', 'full search returns the former company name');

    $detail = $customers->getCustomer($mainId, $sessionId);
    $assert((string) ($detail['old_name'] ?? '') === 'Former Search Company', 'customer detail returns the former company name after the list refresh');
} finally {
    $pdo->prepare('DELETE FROM tblcontact_person WHERE lrefno = :session_id')->execute(['session_id' => $sessionId]);
    $pdo->prepare('DELETE FROM tlbCustomer_Details WHERE lsessionid = :session_id')->execute(['session_id' => $sessionId]);
    $pdo->prepare('DELETE FROM tblpatient WHERE lsessionid = :session_id AND lmain_id = :main_id')->execute([
        'session_id' => $sessionId,
        'main_id' => $mainId,
    ]);
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
