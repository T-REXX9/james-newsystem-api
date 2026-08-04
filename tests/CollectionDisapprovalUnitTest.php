<?php

declare(strict_types=1);

use App\Database;
use App\Repositories\CollectionRepository;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Repositories/CollectionRepository.php';

function collectionTestRepository(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

    $pdo->exec("INSERT INTO tblcollection VALUES ('DCR-REF-1', 1, 10, 'Submitted', 'DCR-1001', 150, '2026-08-04')");
    $pdo->exec("INSERT INTO tblcollection_item VALUES (101, 'DCR-REF-1', 100, 'Posted', 1)");
    $pdo->exec("INSERT INTO tblcollection_item VALUES (102, 'DCR-REF-1', 50, 'Posted', 1)");
    $pdo->exec("INSERT INTO tblapprover VALUES (1, 'staff-1', 1, 'Collection')");
    $pdo->exec("INSERT INTO tblapprover VALUES (1, 'staff-2', 2, 'Collection')");
    $pdo->exec("INSERT INTO tblapprove_logs (lmain_id, lsales_refno, lstaff_id, IsApproved, IsUnapproved, ldatetime)
                VALUES (1, 'DCR-REF-1', 'staff-1', 0, 0, '2026-08-04')");
    $pdo->exec("INSERT INTO tblledger (lrefno, lcollection_id, lcredit, ldebit, lremarks)
                VALUES ('DCR-REF-1', 101, 100, 0, 'testing')");
    $pdo->exec("INSERT INTO tblledger (lrefno, lcollection_id, lcredit, ldebit, lremarks)
                VALUES ('legacy-ref', 102, 50, 0, 'testing')");
    $pdo->exec("INSERT INTO tblledger (lrefno, lcollection_id, lcredit, ldebit, lremarks)
                VALUES ('OTHER-DCR', 999, 25, 0, 'keep')");

    $databaseReflection = new ReflectionClass(Database::class);
    $database = $databaseReflection->newInstanceWithoutConstructor();
    $pdoProperty = $databaseReflection->getProperty('pdo');
    $pdoProperty->setValue($database, $pdo);

    return [new CollectionRepository($database), $pdo];
}

function collectionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$passed = 0;
$failed = 0;

$run = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo "  PASS {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "  FAIL {$name}: {$error->getMessage()}\n";
    }
};

$run('disapproval immediately reverses every linked ledger row and records the audit reason', static function (): void {
    [$repository, $pdo] = collectionTestRepository();

    $result = $repository->approveOrDisapproveCollection(
        'DCR-REF-1',
        1,
        'staff-1',
        'Disapprove',
        'Invalid collection'
    );

    collectionAssert($result['collection_status'] === 'Disapproved', 'Header was not marked Disapproved');
    collectionAssert($result['next_approvers'] === [], 'Disapproval must not advance to another approver');
    collectionAssert($result['ledger_rows_reversed'] === 2, 'Expected two linked ledger rows to be reversed');
    collectionAssert(
        $pdo->query("SELECT lstatus FROM tblcollection WHERE lrefno = 'DCR-REF-1'")->fetchColumn() === 'Disapproved',
        'Stored collection status was not updated'
    );
    collectionAssert((int) $pdo->query('SELECT COUNT(*) FROM tblledger')->fetchColumn() === 1, 'Unrelated ledger row was changed');

    $log = $pdo->query("SELECT IsUnapproved, lremarks FROM tblapprove_logs WHERE lstaff_id = 'staff-1'")->fetch(PDO::FETCH_ASSOC);
    collectionAssert((int) $log['IsUnapproved'] === 1, 'Disapproval audit flag was not saved');
    collectionAssert($log['lremarks'] === 'Invalid collection', 'Disapproval audit reason was not saved');
});

$run('status and audit changes roll back when ledger reversal fails', static function (): void {
    [$repository, $pdo] = collectionTestRepository();
    $pdo->exec("CREATE TRIGGER prevent_ledger_delete BEFORE DELETE ON tblledger
                BEGIN SELECT RAISE(ABORT, 'ledger reversal blocked'); END");

    try {
        $repository->approveOrDisapproveCollection('DCR-REF-1', 1, 'staff-1', 'Disapprove', 'Should roll back');
        throw new RuntimeException('Expected disapproval to fail');
    } catch (PDOException $error) {
        collectionAssert(str_contains($error->getMessage(), 'ledger reversal blocked'), 'Unexpected database failure');
    }

    collectionAssert(
        $pdo->query("SELECT lstatus FROM tblcollection WHERE lrefno = 'DCR-REF-1'")->fetchColumn() === 'Submitted',
        'Collection status was not rolled back'
    );
    $log = $pdo->query("SELECT IsUnapproved, lremarks FROM tblapprove_logs WHERE lstaff_id = 'staff-1'")->fetch(PDO::FETCH_ASSOC);
    collectionAssert((int) $log['IsUnapproved'] === 0, 'Audit flag was not rolled back');
    collectionAssert($log['lremarks'] === null, 'Audit reason was not rolled back');
    collectionAssert((int) $pdo->query('SELECT COUNT(*) FROM tblledger')->fetchColumn() === 3, 'Ledger rows changed after rollback');
});

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
