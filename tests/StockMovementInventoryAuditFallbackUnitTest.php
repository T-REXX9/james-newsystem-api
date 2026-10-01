<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\StockMovementRepository;

function stock_movement_audit_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$db = new Database(new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'test_db', 'test', 'test'));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('CONCAT', static fn (...$values): string => implode('', array_map(static fn ($value): string => (string) $value, $values)), -1);
$pdo->sqliteCreateFunction('GREATEST', static fn (...$values): float => max(array_map(static fn ($value): float => (float) $value, $values)), -1);
$pdo->sqliteCreateFunction('CONCAT_WS', static function (string $separator, ...$values): string {
    return implode($separator, array_values(array_filter($values, static fn ($value): bool => $value !== null && $value !== '')));
}, -1);

$pdo->exec('CREATE TABLE tblinventory_item (
    lid INTEGER PRIMARY KEY, lsession TEXT, lpartno TEXT, litemcode TEXT,
    ldescription TEXT, lbrand TEXT, lmain_id INTEGER
)');
$pdo->exec('CREATE TABLE tblbrand (lid INTEGER PRIMARY KEY, lname TEXT)');
$pdo->exec('CREATE TABLE tblinventory_logs (
    lid TEXT, linvent_id TEXT, lin REAL, lout REAL, ltotal REAL, ldateadded TEXT,
    lprocess_by TEXT, lstatus_logs TEXT, lnote TEXT, linventory_id TEXT,
    ltransaction_item_id TEXT, lpurchase_item_id TEXT, lprice REAL, lrefno TEXT,
    llocation TEXT, lcustomer_id TEXT, lsupplier_id TEXT, lupdated TEXT,
    lwarehouse TEXT, lphysical_count REAL, ltransaction_type TEXT, litemcode TEXT, lpartno TEXT
)');
$pdo->exec('CREATE TABLE tblstock_adjustment_item (
    lid INTEGER PRIMARY KEY, litemsession TEXT, ladjust_qty REAL, lold_qty REAL,
    ldatetime TEXT, ladjustment_refno TEXT, lremarks TEXT, linv_value REAL,
    lwarehouse TEXT, llocation TEXT
)');
$pdo->exec('CREATE TABLE tblpatient (lsessionid TEXT, lcompany TEXT)');
$pdo->exec('CREATE TABLE tblsupplier (lrefno TEXT, lname TEXT)');
$pdo->exec('CREATE TABLE tbldelivery_receipt (lrefno TEXT, lcustomer_name TEXT, linvoice_no TEXT)');
$pdo->exec('CREATE TABLE tblinvoice_list (lrefno TEXT, lcustomer_name TEXT, linvoice_no TEXT)');
$pdo->exec('CREATE TABLE tblpurchase_order (lrefno TEXT, lpurchaseno TEXT)');
$pdo->exec('CREATE TABLE tblbranchinventory_transferlist (lrefno TEXT, ltransfer_no TEXT)');
$pdo->exec('CREATE TABLE tblcredit_memo (lrefno TEXT, clname TEXT, lcredit_no TEXT)');
$pdo->exec('CREATE TABLE tblstock_adjustment (lrefno TEXT, ladjustment_number TEXT)');

$reflection = new ReflectionClass($db);
$pdoProperty = $reflection->getProperty('pdo');
$pdoProperty->setValue($db, $pdo);

$pdo->exec("INSERT INTO tblinventory_item (lid, lsession, lpartno, litemcode, ldescription, lbrand, lmain_id)
    VALUES (1, 'item-1', 'PN-001', 'IT-001', 'Test item', '', 7)");
$pdo->exec("INSERT INTO tblstock_adjustment_item
    (lid, litemsession, ladjust_qty, lold_qty, ldatetime, ladjustment_refno, lremarks, linv_value, lwarehouse, llocation)
    VALUES (11, 'item-1', 7, 10, '2026-09-30 10:00:00', 'AUDIT-11', 'Physical count correction', 30, 'MAIN', 'A-01')");

$repository = new StockMovementRepository($db);
$result = $repository->listLogs(7, 'item-1');
$adjustments = array_values(array_filter($result['logs'], static fn (array $row): bool => $row['transaction_type'] === 'Stock Adjustment'));

stock_movement_audit_expect($result['meta']['total'] === 1, 'audit-only adjustment is included in movement total');
stock_movement_audit_expect(count($adjustments) === 1, 'audit-only adjustment is listed exactly once');
stock_movement_audit_expect($adjustments[0]['reference_no'] === 'AUDIT-11', 'audit reference is retained');
stock_movement_audit_expect((float) $adjustments[0]['qty_out'] === 3.0, 'physical-count decrease is represented as quantity out');
stock_movement_audit_expect($adjustments[0]['notes'] === 'Physical count correction', 'audit remarks are shown as movement notes');

$pdo->exec("INSERT INTO tblinventory_logs
    (lid, linvent_id, lin, lout, ltotal, ldateadded, lprocess_by, lstatus_logs, lnote, linventory_id,
     ltransaction_item_id, lpurchase_item_id, lprice, lrefno, llocation, lcustomer_id, lsupplier_id,
     lupdated, lwarehouse, lphysical_count, ltransaction_type, litemcode, lpartno)
    VALUES (99, 'item-1', 0, 3, 3, '2026-09-30 10:00:00', 'AUDIT-11', '-', 'Physical count correction',
     '1', NULL, NULL, 10, 'AUDIT-11', 'A-01', NULL, NULL, NULL, 'MAIN', 7, 'Stock Adjustment', 'IT-001', 'PN-001')");

$resultWithLog = $repository->listLogs(7, 'item-1');
$adjustmentsWithLog = array_values(array_filter($resultWithLog['logs'], static fn (array $row): bool => $row['transaction_type'] === 'Stock Adjustment'));
stock_movement_audit_expect($resultWithLog['meta']['total'] === 1, 'matching inventory log suppresses the synthetic duplicate');
stock_movement_audit_expect(count($adjustmentsWithLog) === 1, 'logged adjustment is displayed once after duplicate suppression');
stock_movement_audit_expect($adjustmentsWithLog[0]['id'] === '99', 'existing inventory log remains the displayed movement');

echo "Tests passed.\n";
