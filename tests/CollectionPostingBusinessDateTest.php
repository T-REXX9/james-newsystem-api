<?php

declare(strict_types=1);

use App\Database;
use App\Controllers\CollectionController;
use App\Repositories\CollectionRepository;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Controllers/CollectionController.php';
require_once __DIR__ . '/../src/Support/AuditTrailWriter.php';
require_once __DIR__ . '/../src/Repositories/CollectionRepository.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($pdo instanceof \Pdo\Sqlite) {
    $pdo->createFunction('CONCAT', static fn (...$values): string => implode('', $values));
    $pdo->createFunction('NOW', static fn (): string => '2026-11-04 08:00:00');
} else {
    @$pdo->sqliteCreateFunction('CONCAT', static fn (...$values): string => implode('', $values));
    @$pdo->sqliteCreateFunction('NOW', static fn (): string => '2026-11-04 08:00:00');
}
$pdo->exec('CREATE TABLE tblaccount (lid INTEGER PRIMARY KEY, lfname TEXT, llname TEXT)');
$pdo->exec('CREATE TABLE tblpatient (lsessionid TEXT, lmain_id INTEGER, lcompany TEXT, lpatient_code TEXT)');
$pdo->exec('CREATE TABLE tblcollection (
    lrefno TEXT PRIMARY KEY, lmain_id INTEGER, luserid INTEGER, lstatus TEXT,
    lcolection_no TEXT, lamt REAL, ldatetime TEXT, ldate_approved TEXT
)');
$pdo->exec('CREATE TABLE tblcollection_item (
    lid INTEGER PRIMARY KEY, lrefno TEXT, lmainid INTEGER, luserid INTEGER,
    lcustomer TEXT, lcustomer_fname TEXT, lcustomer_lname TEXT, ltype TEXT,
    lbank TEXT, lchk_no TEXT, lchk_date TEXT, lamt REAL, lstatus TEXT,
    lremarks TEXT, lcollect_date TEXT, lpost INTEGER, lcollection_status TEXT,
    ltransaction_no TEXT, created_at TEXT
)');
$pdo->exec('CREATE TABLE tblledger (
    lid INTEGER PRIMARY KEY AUTOINCREMENT, lcustomerid TEXT, lrefno TEXT,
    lmesssage TEXT, lamt REAL, lcredit REAL, ldebit REAL, lcheckdate TEXT,
    lcheck_no TEXT, ldcr TEXT, lremarks TEXT, lref_name TEXT, lmainid TEXT,
    ltype TEXT, luserid TEXT, lcollection_id INTEGER, ldatetime TEXT, created_at TEXT
)');
$pdo->exec("INSERT INTO tblcollection VALUES ('DCR-01198-REF', 1, 5, 'Approved', 'DCR-01198', 125, '2026-11-04', NULL)");
$pdo->exec("INSERT INTO tblcollection_item VALUES (1198, 'DCR-01198-REF', 1, 5, 'CUSTOMER-1', 'Customer', '', 'Cash', '', '', NULL, 125, 'Received', '', '2026-03-03', 0, 'Pending', '', '')");
$pdo->exec("INSERT INTO tblcollection_item VALUES (1199, 'DCR-01198-REF', 1, 5, 'CUSTOMER-2', 'Customer', '', 'Cash', '', '', NULL, 50, 'Received', '', NULL, 0, 'Pending', '', '')");

$databaseReflection = new ReflectionClass(Database::class);
$database = $databaseReflection->newInstanceWithoutConstructor();
$pdoProperty = $databaseReflection->getProperty('pdo');
$pdoProperty->setValue($database, $pdo);

$repository = new CollectionRepository($database);
$controller = new CollectionController($repository);
$blockedBeforeHeaderPost = false;
try {
    $repository->postCollectionItems('DCR-01198-REF', [1198], 1, 5);
} catch (RuntimeException $error) {
    $blockedBeforeHeaderPost = str_contains($error->getMessage(), 'Post the DCR after approval');
}
if (!$blockedBeforeHeaderPost || (int) $pdo->query('SELECT COUNT(*) FROM tblledger')->fetchColumn() !== 0) {
    throw new RuntimeException('FAIL: payment lines must not post before the DCR header is posted');
}

$repository->postCollection('DCR-01198-REF', 1, 5);
$postedHeaderDate = $repository->getCollection('DCR-01198-REF')['ldatetime'] ?? null;
if ($postedHeaderDate !== '2026-03-03') {
    throw new RuntimeException('FAIL: header Post should replace the current header date with the first payment date March 3, 2026');
}

$result = $repository->postCollectionItems('DCR-01198-REF', [1198], 1, 5);
$ledgerDate = $pdo->query('SELECT ldatetime FROM tblledger WHERE lcollection_id = 1198')->fetchColumn();

if (($result['posted_items'] ?? null) !== 1) {
    throw new RuntimeException('FAIL: DCR-01198 payment line was not posted');
}
if ($ledgerDate !== '2026-03-03 00:00:00') {
    throw new RuntimeException('FAIL: ledger business date expected 2026-03-03, got ' . var_export($ledgerDate, true));
}

$fallbackResult = $repository->postCollectionItems('DCR-01198-REF', [1199], 1, 5);
$fallbackDate = $pdo->query('SELECT ldatetime FROM tblledger WHERE lcollection_id = 1199')->fetchColumn();
if (($fallbackResult['posted_items'] ?? null) !== 1 || $fallbackDate !== '2026-03-03 00:00:00') {
    throw new RuntimeException('FAIL: payment line without its own date should inherit the DCR date March 3, 2026');
}

$pdo->exec("INSERT INTO tblcollection VALUES ('DCR-FLOW-REF', 1, 5, 'Approved', 'DCR-FLOW', 0, '2026-11-04', NULL)");
$pdo->exec("INSERT INTO tblpatient VALUES ('CUSTOMER-FLOW', 1, 'Flow Customer', 'CF-1')");
$addResult = $controller->addPayment(['collectionRefno' => 'DCR-FLOW-REF'], [], [
    'main_id' => 1,
    'user_id' => 5,
    'customer_id' => 'CUSTOMER-FLOW',
    'type' => 'Cash',
    'bank' => '',
    'check_no' => '',
    'check_date' => '',
    'amount' => 75,
    'status' => 'Received',
    'remarks' => '',
    'collect_date' => '2024-01-04',
    'transactions' => [],
]);
$flowItemId = (int) ($addResult['collection_item_id'] ?? 0);
$controller->action(['collectionRefno' => 'DCR-FLOW-REF', 'action' => 'postrecord'], [], [
    'main_id' => 1,
    'user_id' => 5,
]);
$controller->postItems(['collectionRefno' => 'DCR-FLOW-REF'], [], [
    'main_id' => 1,
    'user_id' => 5,
    'item_ids' => [$flowItemId],
]);
$flowHeaderDate = $repository->getCollection('DCR-FLOW-REF')['ldatetime'] ?? null;
$flowItemDate = $repository->getCollectionItems('DCR-FLOW-REF')[0]['lcollect_date'] ?? null;
$flowLedgerDate = $pdo->query('SELECT ldatetime FROM tblledger WHERE lcollection_id = ' . (int) $flowItemId)->fetchColumn();
if ($flowHeaderDate !== '2024-01-04' || $flowItemDate !== '2024-01-04' || $flowLedgerDate !== '2024-01-04 00:00:00') {
    throw new RuntimeException('FAIL: add and post flow changed the Jan 4, 2024 business date (header: ' . var_export($flowHeaderDate, true) . ', item: ' . var_export($flowItemDate, true) . ', ledger: ' . var_export($flowLedgerDate, true) . ')');
}

echo "PASS: DCR posting preserves explicit and fallback business dates, including January 4, 2024\n";
