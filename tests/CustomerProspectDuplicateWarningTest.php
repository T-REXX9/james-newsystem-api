<?php

declare(strict_types=1);

/**
 * Prospect duplicate warning regression test.
 *
 * Run: php tests/CustomerProspectDuplicateWarningTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\CustomerDatabaseRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$customers = new CustomerDatabaseRepository($db);
$mainId = 1;
$userId = 1;
$stamp = date('YmdHis') . '-' . random_int(1000, 9999);
$blacklistedSessionId = "WARN-BL-{$stamp}";
$prospectSessionId = "WARN-PROSPECT-{$stamp}";

try {
    $customers->createCustomer($mainId, $userId, [
        'session_id' => $blacklistedSessionId,
        'company' => "BETAN AUTO {$stamp}",
        'status' => 1,
        'profile_type' => 'Old',
        'debt_type' => 'Bad',
    ]);

    $matches = $customers->findSimilarCustomerNames($mainId, 'BETAN');
    $hasBlacklistedMatch = array_filter(
        $matches,
        static fn (array $match): bool => ($match['company'] ?? '') === "BETAN AUTO {$stamp}"
            && ($match['is_blacklisted'] ?? false) === true
    );

    if ($hasBlacklistedMatch === []) {
        throw new RuntimeException('Expected BETAN to warn about the similar blacklisted company.');
    }

    echo "PASS: similar blacklisted company is returned for prospect warning\n";
} finally {
    $pdo->prepare('DELETE FROM tblpatient WHERE lsessionid = :session_id AND lmain_id = :main_id')->execute([
        'session_id' => $blacklistedSessionId,
        'main_id' => $mainId,
    ]);
    $pdo->prepare('DELETE FROM tblpatient WHERE lsessionid = :session_id AND lmain_id = :main_id')->execute([
        'session_id' => $prospectSessionId,
        'main_id' => $mainId,
    ]);
}
