<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\FastSlowInventoryReportRepository;

function expect_true(bool $condition, string $message): void {
    if (!$condition) {
        echo "FAIL: $message\n";
        exit(1);
    }
}

putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=3306');
putenv('DB_NAME=test_db');
putenv('DB_USER=test');
putenv('DB_PASS=test');

$db = new Database(new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'test_db', 'test', 'test'));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE tblinventory_item (
    lid INTEGER PRIMARY KEY,
    lsession TEXT,
    lpartno TEXT,
    litemcode TEXT,
    ldescription TEXT,
    lmain_id INTEGER,
    lstatus INTEGER DEFAULT 1
)');

$pdo->exec('CREATE TABLE tblinventory_price_history (
    lid INTEGER PRIMARY KEY,
    linv_refno TEXT,
    lupdated_at TEXT
)');

$pdo->exec('CREATE TABLE tblinventory_price (
    lid INTEGER PRIMARY KEY,
    linv_refno TEXT,
    lprice_name TEXT,
    lprice_amt REAL
)');

$pdo->exec('CREATE TABLE tblinventory_logs (
    lid INTEGER PRIMARY KEY,
    linvent_id TEXT,
    ltransaction_type TEXT,
    lstatus_logs TEXT,
    lin INTEGER,
    lout INTEGER,
    ldateadded TEXT
)');

$pdo->exec('CREATE TABLE tblinvoice_list (
    lrefno TEXT PRIMARY KEY,
    lmain_id INTEGER,
    ldate TEXT,
    lstatus TEXT,
    lcancel TEXT,
    lcancel_invoice INTEGER DEFAULT 0
)');

$pdo->exec('CREATE TABLE tblinvoice_itemrec (
    lid INTEGER PRIMARY KEY,
    linvoice_refno TEXT,
    linv_refno TEXT,
    lqty INTEGER
)');

$pdo->exec('CREATE TABLE tbldelivery_receipt (
    lrefno TEXT PRIMARY KEY,
    lmain_id INTEGER,
    ldate TEXT,
    lstatus TEXT,
    lcancel TEXT
)');

$pdo->exec('CREATE TABLE tbldelivery_receipt_items (
    lid INTEGER PRIMARY KEY,
    lor_refno TEXT,
    linv_refno TEXT,
    lqty INTEGER
)');

$pdo->exec('CREATE TABLE tblpurchase_item (
    lid INTEGER PRIMARY KEY,
    litemid INTEGER,
    lpo_id INTEGER,
    lrefno TEXT
)');

$pdo->exec('CREATE TABLE tblreceiving_item (
    lid INTEGER PRIMARY KEY,
    lpo_id INTEGER,
    ldate_added TEXT
)');

$pdo->exec('CREATE TABLE tblpurchase_order (
    lid INTEGER PRIMARY KEY,
    lmain_id INTEGER,
    ldate TEXT,
    lrefno TEXT
)');

// Inject the memory PDO into the real Database instance
$reflection = new ReflectionClass($db);
$property = $reflection->getProperty('pdo');
$property->setValue($db, $pdo);

$repo = new FastSlowInventoryReportRepository($db);

$pdo->exec("INSERT INTO tblinventory_item (lid, lsession, lpartno, lmain_id) VALUES (1, 'item-1', 'PART-1', 1)");

// Add document-dated sales for exactly 3 consecutive months: 3 months ago, 2 months ago, 1 month ago.
// Inventory log timestamps intentionally do not contain these sales; the report must use the
// authoritative invoice/order-slip sales dates.
$now = new \DateTimeImmutable('now');
$month3 = $now->modify('-3 months')->format('Y-m-d 12:00:00');
$month2 = $now->modify('-2 months')->format('Y-m-d 12:00:00');
$month1 = $now->modify('-1 months')->format('Y-m-d 12:00:00');

$pdo->exec("INSERT INTO tblinvoice_list (lrefno, lmain_id, ldate, lstatus, lcancel) VALUES
    ('inv-old-1', 1, '{$month3}', 'Posted', ''),
    ('inv-old-2', 1, '{$month2}', 'Posted', ''),
    ('inv-old-3', 1, '{$month1}', 'Posted', '')");
$pdo->exec("INSERT INTO tblinvoice_itemrec (lid, linvoice_refno, linv_refno, lqty) VALUES
    (1, 'inv-old-1', 'item-1', 5),
    (2, 'inv-old-2', 'item-1', 2),
    (3, 'inv-old-3', 'item-1', 1)");

$result = $repo->report(1, 'part_no', 'asc');
expect_true(count($result['fastMovingItems']) === 0, 'item with sales in months [3,2,1] is not fast moving under [2,1,0] rule');
expect_true(count($result['slowMovingItems']) === 1, 'item is slow moving');

// Current month sales (0 months ago) should count if the period logic uses [2,1,0]
$pdo->exec("INSERT INTO tblinventory_item (lid, lsession, lpartno, lmain_id) VALUES (2, 'item-2', 'PART-2', 1)");
$pdo->exec("INSERT INTO tblinventory_price (lid, linv_refno, lprice_name, lprice_amt) VALUES
    (1, 'item-2', 'AAA', 120),
    (2, 'item-2', 'AAA', 135),
    (3, 'item-2', 'VIP 1', 0)");
$month0 = $now->format('Y-m-d 12:00:00');
$pdo->exec("INSERT INTO tblinvoice_list (lrefno, lmain_id, ldate, lstatus, lcancel) VALUES
    ('inv-fast-1', 1, '{$month2}', 'Posted', ''),
    ('inv-fast-2', 1, '{$month1}', 'Posted', '')");
$pdo->exec("INSERT INTO tblinvoice_itemrec (lid, linvoice_refno, linv_refno, lqty) VALUES
    (4, 'inv-fast-1', 'item-2', 5),
    (5, 'inv-fast-2', 'item-2', 2)");
$pdo->exec("INSERT INTO tbldelivery_receipt (lrefno, lmain_id, ldate, lstatus, lcancel) VALUES
    ('dr-fast-3', 1, '{$month0}', 'Posted', '')");
$pdo->exec("INSERT INTO tbldelivery_receipt_items (lid, lor_refno, linv_refno, lqty) VALUES
    (1, 'dr-fast-3', 'item-2', 1)");

$result = $repo->report(1, 'part_no', 'asc');
expect_true(count($result['fastMovingItems']) === 1, 'item with sales in months [2,1,0] should be fast moving');
expect_true(($result['fastMovingItems'][0]['vip1_price'] ?? 0) === 135.0, 'report returns the latest VIP 1 price');

echo "Tests passed.\n";
