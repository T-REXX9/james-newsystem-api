<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\CustomerDatabaseRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$customers = new CustomerDatabaseRepository($db);
$mainId = 1;
$userId = 1;
$prefix = 'PROSPECT-SOURCE-TEST-' . date('YmdHis') . '-' . random_int(1000, 9999);
$passed = 0;
$failed = 0;

$assert = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$label}\n";
};

try {
    try {
        $customers->createCustomer($mainId, $userId, [
            'session_id' => $prefix . '-missing',
            'company' => 'Prospect Source Missing Test',
            'status' => 3,
            'profile_type' => 'Prospect',
        ]);
        $assert(false, 'creating a Prospect without a source is rejected');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'source is required when creating a Prospect', 'creating a Prospect without a source is rejected');
    }

    $prospect = $customers->createCustomer($mainId, $userId, [
        'session_id' => $prefix . '-prospect',
        'company' => 'Prospect Source Present Test',
        'status' => 3,
        'profile_type' => 'Prospect',
        'verification' => 'Unverified',
        'refer_by' => 'Google Search',
    ]);
    $assert(($prospect['refer_by'] ?? '') === 'Master User - Google Search', 'creating a Prospect prefixes its source with the creating staff member');

    $legacy = $customers->createCustomer($mainId, $userId, [
        'session_id' => $prefix . '-legacy',
        'company' => 'Legacy Source Default Test',
        'status' => 1,
        'profile_type' => 'Old',
    ]);
    $assert(($legacy['refer_by'] ?? '') === 'QBP', 'blank legacy customer source is represented as QBP');
} finally {
    $pdo->prepare('DELETE FROM tblcontact_person WHERE lrefno LIKE :prefix')->execute(['prefix' => $prefix . '%']);
    $pdo->prepare('DELETE FROM tblpatient WHERE lmain_id = :main_id AND lsessionid LIKE :prefix')->execute([
        'main_id' => $mainId,
        'prefix' => $prefix . '%',
    ]);
}

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
