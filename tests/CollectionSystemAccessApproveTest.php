<?php

declare(strict_types=1);

/**
 * Feedback loop: staff with System Access can_approve (and no tblapprover row)
 * must still be able to finalize a Collection approve.
 */

use App\Database;
use App\Repositories\CollectionRepository;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Repositories/CollectionRepository.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE tblaccount (
    lid INTEGER PRIMARY KEY, lfname TEXT, llname TEXT
)');
$pdo->exec('CREATE TABLE tblcollection (
    lrefno TEXT PRIMARY KEY, lmain_id INTEGER, luserid INTEGER, lstatus TEXT,
    lcolection_no TEXT, lamt REAL, ldatetime TEXT
)');
$pdo->exec('CREATE TABLE tblcollection_item (
    lid INTEGER PRIMARY KEY, lrefno TEXT, lamt REAL, lcollection_status TEXT, lpost INTEGER
)');
$pdo->exec('CREATE TABLE tblapprove_logs (
    lid INTEGER PRIMARY KEY AUTOINCREMENT, lmain_id INTEGER, lsales_refno TEXT,
    lstaff_id TEXT, IsApproved INTEGER, IsUnapproved INTEGER, ldatetime TEXT, lremarks TEXT
)');
$pdo->exec('CREATE TABLE tblapprover (
    lmain_id INTEGER, lstaff_id TEXT, lorder INTEGER, ltrans_type TEXT
)');
$pdo->exec('CREATE TABLE tblledger (
    lid INTEGER PRIMARY KEY AUTOINCREMENT, lrefno TEXT, lcollection_id INTEGER,
    lcredit REAL, ldebit REAL, lremarks TEXT
)');

$pdo->exec("INSERT INTO tblaccount VALUES (20, 'Staff', 'NoApproverRow')");
$pdo->exec("INSERT INTO tblcollection VALUES ('DCR-SYS-1', 1, 20, 'Submitted', 'DCR-2001', 75, '2026-09-11')");
$pdo->exec("INSERT INTO tblapprove_logs (lmain_id, lsales_refno, lstaff_id, IsApproved, IsUnapproved, ldatetime)
            VALUES (1, 'DCR-SYS-1', '20', 0, 0, '2026-09-11')");

$databaseReflection = new ReflectionClass(Database::class);
$database = $databaseReflection->newInstanceWithoutConstructor();
$pdoProperty = $databaseReflection->getProperty('pdo');
$pdoProperty->setValue($database, $pdo);

$repository = new CollectionRepository($database);
$result = $repository->approveOrDisapproveCollection('DCR-SYS-1', 1, '20', 'Approve', 'System Access only');

if (($result['collection_status'] ?? null) !== 'Approved') {
    throw new RuntimeException('FAIL: Collection approve without tblapprover should finalize as Approved, got ' . json_encode($result));
}

$status = $pdo->query("SELECT lstatus FROM tblcollection WHERE lrefno = 'DCR-SYS-1'")->fetchColumn();
if ($status !== 'Approved') {
    throw new RuntimeException('FAIL: Collection row status expected Approved, got ' . json_encode($status));
}

echo "PASS: Collection approve works with System Access only (no tblapprover row)\n";
