<?php

declare(strict_types=1);

/**
 * Regression test for the prospect duplicate-check response.
 *
 * Run: php tests/CustomerSimilarMatchSessionIdUnitTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\CustomerDatabaseRepository;

$db = new Database(app_config());
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$reflection = new ReflectionClass($db);
$pdoProperty = $reflection->getProperty('pdo');
$pdoProperty->setValue($db, $pdo);

$pdo->exec(
    'CREATE TABLE tblpatient (
        lsessionid TEXT,
        lcompany TEXT,
        lstatus INTEGER,
        lprofile_type TEXT,
        ldebt_type TEXT,
        ltin TEXT,
        lphone TEXT,
        lmobile TEXT,
        laddress TEXT,
        ldelivery_address TEXT,
        lcity TEXT,
        lprovince TEXT,
        lmain_id INTEGER,
        ldeleted INTEGER
    )'
);
$pdo->prepare(<<<SQL
    INSERT INTO tblpatient (
        lsessionid, lcompany, lstatus, lprofile_type, ldebt_type, ltin,
        lphone, lmobile, laddress, ldelivery_address, lcity, lprovince,
        lmain_id, ldeleted
    ) VALUES (
        :session_id, :company, 1, :profile_type, :debt_type, '', '', '', '', '', '', '', 1, 0
    )
SQL
)->execute([
    'session_id' => 'prospect-match-1',
    'company' => 'Session ID Prospect',
    'profile_type' => 'Prospective',
    'debt_type' => 'Good',
]);

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $matches = (new CustomerDatabaseRepository($db))->findSimilarCustomers(1, [
        'company' => 'Session ID Prospect',
    ]);
} finally {
    restore_error_handler();
}

if ($matches === []) {
    throw new RuntimeException('Expected the matching prospect to be returned.');
}

if (($matches[0]['session_id'] ?? '') !== 'prospect-match-1') {
    throw new RuntimeException('Expected the duplicate match to retain its session ID.');
}

echo "PASS: similar customer matching uses the selected session_id alias without warnings\n";
