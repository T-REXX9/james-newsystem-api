<?php

declare(strict_types=1);

/**
 * Customer identity uniqueness: company name or non-empty TIN.
 *
 * Run: php tests/CustomerIdentityUniquenessTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\CustomerDatabaseRepository;

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
echo " Customer Identity Uniqueness Test\n";
echo "==========================================================\n\n";

$customers = new CustomerDatabaseRepository($db);
$mainId = 1;
$userId = 1;
$stamp = date('YmdHis') . '-' . random_int(1000, 9999);
$sessionIds = [];

$create = static function (string $sessionId, string $company, string $tin = '') use ($customers, $mainId, $userId, &$sessionIds): array {
    $sessionIds[] = $sessionId;
    return $customers->createCustomer($mainId, $userId, [
        'session_id' => $sessionId,
        'company' => $company,
        'tin' => $tin,
        'debt_type' => 'Good',
        'status' => 3,
        'profile_type' => 'prospect',
        'phone' => '09171234567',
        'mobile' => '09171234567',
    ]);
};

$countLive = static function (string $company) use ($pdo, $mainId): int {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM tblpatient
         WHERE lmain_id = :main_id
           AND LOWER(TRIM(lcompany)) = LOWER(:company)
           AND COALESCE(ldeleted, 0) = 0'
    );
    $stmt->execute(['main_id' => $mainId, 'company' => $company]);
    return (int) $stmt->fetchColumn();
};

try {
    $companyA = "Dup Co A {$stamp}";
    $companyB = "Dup Co B {$stamp}";
    $sessionA = "DUP-A-{$stamp}";
    $sessionB = "DUP-B-{$stamp}";
    $sessionC = "DUP-C-{$stamp}";
    $sessionD = "DUP-D-{$stamp}";
    $sessionE = "DUP-E-{$stamp}";

    $create($sessionA, $companyA, "TIN-{$stamp}");
    $assertEq(1, $countLive($companyA), 'first create stores the customer');

    $nameDuplicateMessage = '';
    try {
        $create($sessionB, strtoupper($companyA), '');
    } catch (RuntimeException $e) {
        $nameDuplicateMessage = $e->getMessage();
    }
    $assert(str_contains($nameDuplicateMessage, $companyA), 'duplicate company name error names the existing company');
    $assertEq(1, $countLive($companyA), 'duplicate company name does not insert a second row');
    $assert($customers->getCustomer($mainId, $sessionB) === null, 'rejected duplicate company is not retrievable');

    $create($sessionC, $companyB, '');
    $assertEq(1, $countLive($companyB), 'different company name with blank TIN is allowed');

    $tinDuplicateMessage = '';
    try {
        $create($sessionD, "Dup Co D {$stamp}", "tin {$stamp}");
    } catch (RuntimeException $e) {
        $tinDuplicateMessage = $e->getMessage();
    }
    $assert(str_contains($tinDuplicateMessage, $companyA), 'duplicate TIN error names the existing company');
    $assert($customers->getCustomer($mainId, $sessionD) === null, 'rejected duplicate TIN is not retrievable');

    $updated = $customers->updateCustomer($mainId, $sessionA, [
        'company' => $companyA,
        'tin' => "TIN-{$stamp}",
        'user_id' => $userId,
    ]);
    $assert($updated !== null, 'editing own company name and TIN is not a self-duplicate');

    $collideMessage = '';
    try {
        $customers->updateCustomer($mainId, $sessionC, [
            'company' => $companyA,
            'user_id' => $userId,
        ]);
    } catch (RuntimeException $e) {
        $collideMessage = $e->getMessage();
    }
    $assert(str_contains($collideMessage, $companyA), 'updating onto another company name is refused');
    $stillB = $customers->getCustomer($mainId, $sessionC);
    $assertEq($companyB, (string) ($stillB['company'] ?? ''), 'refused update leaves the original company name');

    $create($sessionE, "Dup Co E {$stamp}", '');
    $assertEq(1, $countLive("Dup Co E {$stamp}"), 'another blank-TIN customer with a distinct name is allowed');
} finally {
    foreach (array_unique($sessionIds) as $sessionId) {
        $pdo->prepare('DELETE FROM tblcontact_person WHERE lrefno = :session_id')->execute(['session_id' => $sessionId]);
        $pdo->prepare('DELETE FROM tblpatient_terms WHERE lpatient = :session_id')->execute(['session_id' => $sessionId]);
        $pdo->prepare('DELETE FROM tblpatient WHERE lsessionid = :session_id AND lmain_id = :main_id')->execute([
            'session_id' => $sessionId,
            'main_id' => $mainId,
        ]);
    }
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

echo "\nAll customer identity uniqueness checks passed.\n";
