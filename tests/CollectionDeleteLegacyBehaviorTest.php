<?php

declare(strict_types=1);

use App\Database;
use App\Repositories\CollectionRepository;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Support/AuditTrailWriter.php';
require_once __DIR__ . '/../src/Repositories/CollectionRepository.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($pdo instanceof \Pdo\Sqlite) {
    $pdo->createFunction('NOW', static fn (): string => '2026-11-09 09:00:00');
} else {
    @$pdo->sqliteCreateFunction('NOW', static fn (): string => '2026-11-09 09:00:00');
}
$pdo->exec('CREATE TABLE tblcollection (lrefno TEXT PRIMARY KEY, lmain_id INTEGER, lstatus TEXT)');
$pdo->exec('CREATE TABLE tblcollection_item (lid INTEGER PRIMARY KEY, lrefno TEXT)');
$pdo->exec('CREATE TABLE tblcollection_item_transactions (lid INTEGER PRIMARY KEY, lcollection_itemid INTEGER)');
$pdo->exec('CREATE TABLE tblapprove_logs (lid INTEGER PRIMARY KEY, lsales_refno TEXT)');
$pdo->exec('CREATE TABLE tblledger (lid INTEGER PRIMARY KEY, lrefno TEXT, lcollection_id INTEGER)');
$pdo->exec('CREATE TABLE tblaudit_trail (
    lid INTEGER PRIMARY KEY AUTOINCREMENT, lmain_id INTEGER, luser_id INTEGER, lpage TEXT,
    laction TEXT, lrefno TEXT, lreason TEXT, lold_status TEXT, lnew_status TEXT, ldatetime TEXT
)');
$pdo->exec("INSERT INTO tblcollection VALUES ('DCR-POSTED-REF', 1, 'Posted'), ('DCR-OTHER-REF', 1, 'Pending'), ('DCR-FOREIGN-REF', 2, 'Posted')");
$pdo->exec("INSERT INTO tblcollection_item VALUES (101, 'DCR-POSTED-REF'), (102, 'DCR-OTHER-REF')");
$pdo->exec('INSERT INTO tblcollection_item_transactions VALUES (1001, 101), (1002, 102)');
$pdo->exec("INSERT INTO tblapprove_logs VALUES (201, 'DCR-POSTED-REF'), (202, 'DCR-OTHER-REF')");
$pdo->exec("INSERT INTO tblledger VALUES (301, 'DCR-POSTED-REF', 101), (302, 'DCR-OTHER-REF', 102), (303, 'LEGACY-ITEM-REF', 101)");

$databaseReflection = new ReflectionClass(Database::class);
$database = $databaseReflection->newInstanceWithoutConstructor();
$databaseReflection->getProperty('pdo')->setValue($database, $pdo);
$repository = new CollectionRepository($database);
$foreignScopeRejected = false;
try {
    $repository->deleteCollection('DCR-FOREIGN-REF', 1, 10);
} catch (RuntimeException $error) {
    $foreignScopeRejected = $error->getMessage() === 'Collection record not found';
}
if (!$foreignScopeRejected) {
    throw new RuntimeException('DCR deletion must reject records outside the authenticated account scope');
}
$repository->deleteCollection('DCR-POSTED-REF', 1, 10);

$assertCount = static function (string $table, int $expected) use ($pdo): void {
    $actual = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    if ($actual !== $expected) {
        throw new RuntimeException("Expected {$expected} rows in {$table}; found {$actual}");
    }
};
$assertCount('tblcollection', 2);
$assertCount('tblcollection_item', 1);
$assertCount('tblcollection_item_transactions', 1);
$assertCount('tblapprove_logs', 1);
$assertCount('tblledger', 2);
$assertCount('tblaudit_trail', 1);

$audit = $pdo->query('SELECT lmain_id, luser_id, lpage, laction, lrefno FROM tblaudit_trail')->fetch(PDO::FETCH_ASSOC);
if ($audit !== [
    'lmain_id' => 1,
    'luser_id' => 10,
    'lpage' => 'Daily Collection Entry',
    'laction' => 'Delete Collection Report',
    'lrefno' => 'DCR-POSTED-REF',
]) {
    throw new RuntimeException('DCR deletion audit entry did not match the expected actor and DCR reference');
}

echo "PASS: posted DCR deletion removes linked report data, preserves unrelated/account-scoped records, and writes an audit row\n";
