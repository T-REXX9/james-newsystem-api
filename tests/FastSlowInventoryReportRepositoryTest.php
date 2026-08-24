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

$pdo->exec('CREATE TABLE tblinventory_logs (
    lid INTEGER PRIMARY KEY,
    linvent_id TEXT,
    ltransaction_type TEXT,
    lstatus_logs TEXT,
    lin INTEGER,
    lout INTEGER,
    ldateadded TEXT
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

// Add sales for exactly 3 consecutive months: 3 months ago, 2 months ago, 1 month ago
$now = new \DateTimeImmutable('now');
$month3 = $now->modify('-3 months')->format('Y-m-d 12:00:00');
$month2 = $now->modify('-2 months')->format('Y-m-d 12:00:00');
$month1 = $now->modify('-1 months')->format('Y-m-d 12:00:00');

$pdo->exec("INSERT INTO tblinventory_logs (linvent_id, ltransaction_type, lstatus_logs, lout, ldateadded) VALUES ('item-1', 'Invoice', '-', 5, '{$month3}')");
$pdo->exec("INSERT INTO tblinventory_logs (linvent_id, ltransaction_type, lstatus_logs, lout, ldateadded) VALUES ('item-1', 'Invoice', '-', 2, '{$month2}')");
$pdo->exec("INSERT INTO tblinventory_logs (linvent_id, ltransaction_type, lstatus_logs, lout, ldateadded) VALUES ('item-1', 'Invoice', '-', 1, '{$month1}')");

$result = $repo->report(1, 'part_no', 'asc');
expect_true(count($result['fastMovingItems']) === 0, 'item with sales in months [3,2,1] is not fast moving under [2,1,0] rule');
expect_true(count($result['slowMovingItems']) === 1, 'item is slow moving');

// Current month sales (0 months ago) should count if the period logic uses [2,1,0]
$pdo->exec("INSERT INTO tblinventory_item (lid, lsession, lpartno, lmain_id) VALUES (2, 'item-2', 'PART-2', 1)");
$month0 = $now->format('Y-m-d 12:00:00');
$pdo->exec("INSERT INTO tblinventory_logs (linvent_id, ltransaction_type, lstatus_logs, lout, ldateadded) VALUES ('item-2', 'Invoice', '-', 5, '{$month2}')");
$pdo->exec("INSERT INTO tblinventory_logs (linvent_id, ltransaction_type, lstatus_logs, lout, ldateadded) VALUES ('item-2', 'Invoice', '-', 2, '{$month1}')");
$pdo->exec("INSERT INTO tblinventory_logs (linvent_id, ltransaction_type, lstatus_logs, lout, ldateadded) VALUES ('item-2', 'Invoice', '-', 1, '{$month0}')");

$result = $repo->report(1, 'part_no', 'asc');
expect_true(count($result['fastMovingItems']) === 1, 'item with sales in months [2,1,0] should be fast moving');

echo "Tests passed.\n";
