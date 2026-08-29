<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\InventoryReportRepository;

function inventory_report_expect(bool $condition, string $message): void
{
    if (!$condition) {
        echo "FAIL: {$message}\n";
        exit(1);
    }
}

$db = new Database(new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'test_db', 'test', 'test'));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE tblinventory_item (
    lid INTEGER PRIMARY KEY,
    lsession TEXT,
    lpartno TEXT,
    litemcode TEXT,
    ldescription TEXT,
    lproduct_group TEXT,
    llocation TEXT,
    lreorder_amt REAL,
    lmain_id INTEGER,
    lstatus INTEGER DEFAULT 1
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
    lwarehouse TEXT,
    lin REAL,
    lout REAL,
    ldateadded TEXT,
    ltransaction_type TEXT,
    lstatus_logs TEXT
)');
$pdo->exec('CREATE TABLE tblbranch (lid INTEGER PRIMARY KEY, lname TEXT)');

$reflection = new ReflectionClass($db);
$property = $reflection->getProperty('pdo');
$property->setValue($db, $pdo);

$pdo->exec("INSERT INTO tblinventory_item
    (lid, lsession, lpartno, litemcode, ldescription, lproduct_group, llocation, lreorder_amt, lmain_id, lstatus)
    VALUES (1, 'item-1', 'PN-001', 'IT-001', 'NOZZLE', 'Fuel System', 'A-01', 15, 1, 1)");
$pdo->exec("INSERT INTO tblinventory_price (lid, linv_refno, lprice_name, lprice_amt) VALUES
    (1, 'item-1', 'AAA', 18),
    (2, 'item-1', 'VIP 1', 0)");
$pdo->exec("INSERT INTO tblinventory_logs
    (lid, linvent_id, lwarehouse, lin, lout, ldateadded, ltransaction_type, lstatus_logs) VALUES
    (1, 'item-1', 'MAIN', 5, 0, '2026-07-01 09:00:00', 'Receiving', '+'),
    (2, 'item-1', 'MAIN', 0, 2, '2026-08-27 14:30:00', 'Invoice', '-'),
    (3, 'item-1', 'MAIN', 3, 0, '2026-08-12 09:15:00', 'Receiving', '+'),
    (4, 'item-1', 'MAIN', 1, 0, '0000-00-00 00:00:00', 'Receiving', '+')");
$pdo->exec("INSERT INTO tblbranch (lid, lname) VALUES (1, 'MAIN')");

$repo = new InventoryReportRepository($db);
$result = $repo->report(1, [
    'description' => '',
    'part_number' => '',
    'item_code' => '',
    'stock_status' => 'all',
    'report_type' => 'inventory',
    'date_from' => '',
    'date_to' => '',
]);

inventory_report_expect(count($result['items']) === 1, 'report returns the inventory item');
inventory_report_expect(
    ($result['items'][0]['last_transaction_date'] ?? '') === '2026-08-27 14:30:00',
    'report returns the latest valid inventory transaction date'
);
inventory_report_expect(
    ($result['items'][0]['last_rr_date'] ?? '') === '2026-08-12 09:15:00',
    'report returns the latest valid Receiving Report transaction date'
);
inventory_report_expect(
    ($result['items'][0]['reorder_quantity'] ?? 0) === 15.0,
    'report returns the product reorder quantity'
);
inventory_report_expect(
    ($result['items'][0]['vip1_price'] ?? 0) === 18.0,
    'report uses the price shown as VIP 1 in Product Database rather than the internal VIP 1 field shown as VIP 2'
);
inventory_report_expect(
    ($result['items'][0]['value'] ?? 0) === 126.0,
    'inventory report value uses the Product Database VIP 1 price'
);

echo "Tests passed.\n";
