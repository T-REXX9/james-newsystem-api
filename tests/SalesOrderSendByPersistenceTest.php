<?php

declare(strict_types=1);

/**
 * Regression test: Sales Order "Send By" field must persist on create and
 * survive an update (lshipped column on tbltransaction). Previously
 * SalesOrderRepository never read/wrote lshipped, so edits to Send By were
 * silently dropped.
 *
 * Run:
 *   php api/tests/SalesOrderSendByPersistenceTest.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\SalesOrderRepository;

function send_by_expect(bool $condition, string $message): void
{
    if (!$condition) {
        echo "FAIL: {$message}\n";
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$db = new Database(new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'test_db', 'test', 'test'));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE tbltransaction (
    lid INTEGER PRIMARY KEY AUTOINCREMENT,
    lmain_id INTEGER, luser TEXT, lrefno TEXT, lsaleno TEXT, lbranch TEXT,
    ldate TEXT, ltime TEXT, lcustomerid TEXT, lcompany TEXT,
    lt_lfname TEXT, lt_llname TEXT, lsales_address TEXT,
    lmy_refno TEXT, lyour_refno TEXT, lshipped TEXT, lprice_group TEXT,
    lcredit_limit REAL, lpromissory_note TEXT, lpo_no TEXT, lnote TEXT,
    lterms TEXT, lterm_condition TEXT, lsales_person TEXT, lsales_person_id TEXT,
    lsubmitstat TEXT, ltransaction_status TEXT, lcancel INTEGER, lcancel_reason TEXT,
    linquiry_refno TEXT, linquiry_no TEXT, ldr_refno TEXT, ldr_no TEXT,
    invoice_refno TEXT, invoice_no TEXT, lurgency TEXT, lurgency_date TEXT, IsInquiry INTEGER
)');
$pdo->exec('CREATE TABLE tbltransaction_item (lid INTEGER PRIMARY KEY AUTOINCREMENT, lrefno TEXT, litemid TEXT, linv_refno TEXT, lpartno TEXT, litemcode TEXT, ldesc TEXT, llocation TEXT, lqty REAL, lprice REAL, lremark TEXT, lbrand TEXT, lcancel INTEGER)');
$pdo->exec('CREATE TABLE tblaccount (lid INTEGER PRIMARY KEY, lfname TEXT, llname TEXT, lstatus INTEGER)');
$pdo->exec('CREATE TABLE tblpatient (lid INTEGER PRIMARY KEY, lmain_id INTEGER, lsessionid TEXT, lfname TEXT, llname TEXT, lcompany TEXT, ldelivery_address TEXT, lprice_group TEXT, lcredit TEXT, lterms TEXT, lsales_person TEXT, lcity TEXT, ltransaction_type TEXT, ldeleted INTEGER)');
$pdo->exec('CREATE TABLE tblnumber_generator (lid INTEGER PRIMARY KEY AUTOINCREMENT, ltransaction_type TEXT, lmax_no INTEGER)');
$pdo->exec('CREATE TABLE tblapprover_assignment (lid INTEGER PRIMARY KEY, lmain_id INTEGER, luser_id INTEGER, ldocument_type TEXT)');
$pdo->exec('CREATE TABLE tblvip_document_discount (
    lid INTEGER PRIMARY KEY AUTOINCREMENT, lmain_id INTEGER, ldocument_type TEXT, ldocument_refno TEXT,
    lcustomerid TEXT, lsales_date TEXT, lapplied INTEGER DEFAULT 0, ltier TEXT DEFAULT \'regular\',
    lpercentage REAL DEFAULT 0, ldiscount_amount REAL DEFAULT 0, ltotal_to_pay REAL DEFAULT 0
)');
$pdo->exec("INSERT INTO tblpatient VALUES (1, 1, 'cust-1', 'Jane', 'Doe', 'Acme Corp', '123 Main St', 'Standard', '10000', 'NET30', '12', 'Metro', 'Regular', 0)");
$pdo->exec("INSERT INTO tblaccount VALUES (12, 'Alice', 'Agent', 1)");

$reflection = new ReflectionClass($db);
$property = $reflection->getProperty('pdo');
$property->setValue($db, $pdo);

$repo = new SalesOrderRepository($db);

// --- Create: send_by must be persisted and returned ---
$created = $repo->createSalesOrder(1, 12, [
    'contact_id' => 'cust-1',
    'send_by' => 'LBC Express',
    'terms' => 'NET30',
    'terms_condition' => 'NET30',
    'items' => [],
]);
send_by_expect($created !== null, 'createSalesOrder returns a record');
send_by_expect(($created['order']['send_by'] ?? null) === 'LBC Express', 'send_by is persisted and returned on create');

$salesRefno = (string) ($created['order']['sales_refno'] ?? '');
send_by_expect($salesRefno !== '', 'created order has a sales_refno');

// --- Read back independently: getSalesOrder must select lshipped ---
$fetched = $repo->getSalesOrder(1, $salesRefno);
send_by_expect($fetched !== null, 'getSalesOrder finds the created record');
send_by_expect(($fetched['order']['send_by'] ?? null) === 'LBC Express', 'getSalesOrder returns send_by from lshipped column');

// --- Update: send_by must be updatable and persisted ---
$updated = $repo->updateSalesOrder(1, $salesRefno, [
    'contact_id' => 'cust-1',
    'send_by' => 'JRS Express',
]);
send_by_expect($updated !== null, 'updateSalesOrder returns a record');
send_by_expect(($updated['order']['send_by'] ?? null) === 'JRS Express', 'send_by is updated to the new value');

// --- Read back after update: change must survive a fresh fetch ---
$refetched = $repo->getSalesOrder(1, $salesRefno);
send_by_expect(($refetched['order']['send_by'] ?? null) === 'JRS Express', 'send_by change survives a fresh getSalesOrder fetch');

// --- Update without touching send_by: existing value must be preserved ---
$updatedAgain = $repo->updateSalesOrder(1, $salesRefno, [
    'contact_id' => 'cust-1',
    'remarks' => 'no send_by key in this payload',
]);
send_by_expect(($updatedAgain['order']['send_by'] ?? null) === 'JRS Express', 'send_by is preserved when omitted from an update payload');

echo "\nAll Sales Order Send By persistence checks passed.\n";
