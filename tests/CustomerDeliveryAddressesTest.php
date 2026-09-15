<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\CustomerDatabaseRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$customers = new CustomerDatabaseRepository($db);
$mainId = 1;
$sessionId = 'CUSTOMER-DELIVERY-' . date('YmdHis') . '-' . random_int(1000, 9999);
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

try {
    $created = $customers->createCustomer($mainId, 1, [
        'session_id' => $sessionId,
        'company' => 'Delivery Address Test Company',
        'status' => 1,
        'profile_type' => 'Old',
        'delivery_addresses' => ['Warehouse A', 'Branch B', 'Warehouse A'],
    ]);

    $assert($created['delivery_addresses'] === ['Warehouse A', 'Branch B'], 'creates distinct delivery addresses in order');
    $assert((string) $created['delivery_address'] === 'Warehouse A', 'keeps the first delivery address as the legacy primary address');

    $updated = $customers->updateCustomer($mainId, $sessionId, [
        'delivery_addresses' => ['Branch B', 'Warehouse C'],
    ]);
    $assert(($updated['delivery_addresses'] ?? []) === ['Branch B', 'Warehouse C'], 'replaces the customer delivery-address list');
    $assert((string) ($updated['delivery_address'] ?? '') === 'Branch B', 'updates the legacy primary address when the list changes');
} finally {
    $pdo->prepare('DELETE FROM tblpatient_delivery_address WHERE lmain_id = :main_id AND lsessionid = :session_id')->execute([
        'main_id' => $mainId,
        'session_id' => $sessionId,
    ]);
    $pdo->prepare('DELETE FROM tblpatient WHERE lmain_id = :main_id AND lsessionid = :session_id')->execute([
        'main_id' => $mainId,
        'session_id' => $sessionId,
    ]);
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
