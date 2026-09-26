<?php

declare(strict_types=1);

use App\Database;
use App\Repositories\CollectionRepository;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Repositories/CollectionRepository.php';

$pdo = class_exists(\Pdo\Sqlite::class)
    ? new \Pdo\Sqlite('sqlite::memory:')
    : new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($pdo instanceof \Pdo\Sqlite) {
    $pdo->createFunction('NOW', static fn (): string => '2026-09-26 10:00:00');
    $pdo->createFunction('CONCAT', static fn (...$values): string => implode('', $values));
} else {
    $pdo->sqliteCreateFunction('NOW', static fn (): string => '2026-09-26 10:00:00');
    $pdo->sqliteCreateFunction('CONCAT', static fn (...$values): string => implode('', $values));
}

$pdo->exec('CREATE TABLE tblaccount (lid INTEGER PRIMARY KEY, lfname TEXT, llname TEXT)');
$pdo->exec('CREATE TABLE tblcollection (
    lrefno TEXT PRIMARY KEY, lmain_id INTEGER, luserid INTEGER, lstatus TEXT,
    lcolection_no TEXT, lamt REAL, ldatetime TEXT
)');
$pdo->exec('CREATE TABLE tblcollection_item (
    lid INTEGER PRIMARY KEY, lrefno TEXT, lmainid INTEGER, luserid INTEGER,
    lcustomer TEXT, lcustomer_fname TEXT, lcustomer_lname TEXT, ltype TEXT,
    lbank TEXT, lchk_no TEXT, lchk_date TEXT, lamt REAL, lstatus TEXT,
    lremarks TEXT, lcollect_date TEXT, lpost INTEGER, lcollection_status TEXT,
    ltransaction_no TEXT
)');
$pdo->exec('CREATE TABLE tblledger (
    lcollection_id INTEGER, lmesssage TEXT, lcheck_no TEXT, lcheckdate TEXT, lremarks TEXT
)');
$pdo->exec('CREATE TABLE tblcollection_item_transactions (
    lid INTEGER PRIMARY KEY AUTOINCREMENT, ldatetime TEXT, lcollection_refno TEXT,
    lcollection_itemid INTEGER, ltransaction_type TEXT, ltransaction_refno TEXT,
    ltransaction_no TEXT, ltransaction_amount REAL, lpaid_amt REAL
)');
$pdo->exec("INSERT INTO tblcollection VALUES ('DCR-REF-1', 1, 10, 'Pending', 'DCR-1001', 100, '2026-09-26')");
$pdo->exec("INSERT INTO tblaccount VALUES (10, 'Test', 'User')");
$pdo->exec("INSERT INTO tblcollection_item VALUES (101, 'DCR-REF-1', 1, 10, 'customer-1', 'Customer One', '', 'Cash', '', '', '2026-09-26', 100, 'Pending', '', '2026-09-26', 0, 'Pending', 'INV-1')");
$pdo->exec("INSERT INTO tblledger VALUES (101, 'CASH', '', '2026-09-26', '')");
$pdo->exec("INSERT INTO tblcollection_item_transactions (ldatetime, lcollection_refno, lcollection_itemid, ltransaction_type, ltransaction_refno, ltransaction_no, ltransaction_amount, lpaid_amt) VALUES ('2026-09-26', 'DCR-REF-1', 101, 'Invoice', 'INV-REF-1', 'INV-1', 200, 100)");
if ($pdo->query('SELECT COUNT(*) FROM tblcollection_item_transactions WHERE lcollection_itemid = 101')->fetchColumn() != 1) {
    throw new RuntimeException('FAIL: payment-line transaction fixture was not created');
}

$databaseReflection = new ReflectionClass(Database::class);
$database = $databaseReflection->newInstanceWithoutConstructor();
$pdoProperty = $databaseReflection->getProperty('pdo');
$pdoProperty->setValue($database, $pdo);
$repository = new CollectionRepository($database);
$transactionsMethod = (new ReflectionClass(CollectionRepository::class))->getMethod('getCollectionItemTransactions');
$transactionRows = $transactionsMethod->invoke($repository, 101);
if (count($transactionRows) !== 1 || ($transactionRows[0]['ltransaction_no'] ?? null) !== 'INV-1') {
    throw new RuntimeException('FAIL: payment-line transaction fixture cannot be loaded');
}

$result = $repository->updateCollectionPaymentLine(101, [
    'main_id' => 1,
    'user_id' => 20,
    'type' => 'Check',
    'bank' => 'BDO',
    'check_no' => '123456',
    'check_date' => '2026-09-26',
    'amount' => 150.5,
    'status' => 'Received',
    'remarks' => 'Updated payment',
]);

$saved = $pdo->query('SELECT luserid, ltype, lbank, lchk_no, lchk_date, lamt, lstatus, lremarks, ltransaction_no FROM tblcollection_item WHERE lid = 101')->fetch(PDO::FETCH_ASSOC);
if ($saved === false) {
    throw new RuntimeException('FAIL: updated collection item was not found');
}

foreach ([
    'luserid' => '20',
    'ltype' => 'Check',
    'lbank' => 'BDO',
    'lchk_no' => '123456',
    'lchk_date' => '2026-09-26',
    'lamt' => '150.5',
    'lstatus' => 'Received',
    'lremarks' => 'Updated payment',
    'ltransaction_no' => 'INV-1',
] as $field => $expected) {
    if ((string) $saved[$field] !== $expected) {
        throw new RuntimeException("FAIL: {$field} was not persisted (stored: " . var_export($saved[$field], true) . ')');
    }
}

if (($result['item']['lremarks'] ?? null) !== 'Updated payment' || (float) ($result['item']['lamt'] ?? 0) !== 150.5) {
    throw new RuntimeException('FAIL: update response did not return the persisted payment line');
}

$transaction = $pdo->query('SELECT ltransaction_no, lpaid_amt FROM tblcollection_item_transactions WHERE lcollection_itemid = 101')->fetch(PDO::FETCH_ASSOC);
if ($transaction === false || $transaction['ltransaction_no'] !== 'INV-1' || (float) $transaction['lpaid_amt'] !== 150.5) {
    throw new RuntimeException('FAIL: linked transaction allocation was not preserved');
}

echo "PASS: collection payment line edits persist and return the stored row\n";
