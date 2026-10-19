<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\DailyCallMonitoringRepository;

function dashboard_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
}

$db = new Database(new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'test_db', 'test', 'test'));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$previousErrorReporting = error_reporting();
error_reporting($previousErrorReporting & ~E_DEPRECATED);
$pdo->sqliteCreateFunction('MONTH', static fn(string $value): int => (int) substr($value, 5, 2));
$pdo->sqliteCreateFunction('CONCAT', static fn(...$values): string => implode('', $values));
error_reporting($previousErrorReporting);

$pdo->exec('CREATE TABLE tblledger (lmainid INTEGER, ldatetime TEXT, ltype TEXT, lref_name TEXT, ldebit REAL, lcredit REAL, lcustomerid TEXT)');
$pdo->exec('CREATE TABLE tblpatient (lmain_id INTEGER, lsessionid TEXT, lcompany TEXT, lsales_person INTEGER, ldeleted INTEGER, lactive TEXT, lstatus INTEGER, lprofile_type TEXT)');
$pdo->exec('CREATE TABLE tblaccount (lid INTEGER, lfname TEXT, llname TEXT, lstatus INTEGER)');
$pdo->exec('CREATE TABLE tbltransaction (lid INTEGER PRIMARY KEY AUTOINCREMENT, lrefno TEXT, lmain_id INTEGER, ldate TEXT, lcancel INTEGER, limported INTEGER, ltax_type TEXT, lcustomerid TEXT, lcompany TEXT)');
$pdo->exec('CREATE TABLE tbltransaction_item (lrefno TEXT, lqty REAL, lprice REAL, lcancel INTEGER, lremark TEXT, ltype TEXT, lcategory TEXT, ltransaction_date TEXT)');
$pdo->exec('CREATE TABLE tblinvoice_list (lmain_id INTEGER, ldate TEXT, lrefno TEXT, ltax_type TEXT, lcancel TEXT, lcancel_invoice INTEGER, lstatus TEXT)');
$pdo->exec('CREATE TABLE tblinvoice_itemrec (linvoice_refno TEXT, litemcode TEXT, lpartno TEXT, ldesc TEXT, lqty REAL, lprice REAL, lcategory TEXT)');
$pdo->exec('CREATE TABLE tbldelivery_receipt (lmain_id INTEGER, ldate TEXT, lrefno TEXT, lcancel TEXT, lstatus TEXT)');
$pdo->exec('CREATE TABLE tbldelivery_receipt_items (lor_refno TEXT, litemcode TEXT, lpartno TEXT, ldesc TEXT, lqty REAL, lprice REAL, lcategory TEXT)');

$property = (new ReflectionClass($db))->getProperty('pdo');
$property->setValue($db, $pdo);

$pdo->exec("INSERT INTO tblpatient VALUES (1, 'customer-1', 'Test Customer', 7, 0, 'active', 1, 'customer')");
$pdo->exec("INSERT INTO tblaccount VALUES (7, 'Test', 'Agent', 1)");
$pdo->exec("INSERT INTO tblledger VALUES
    (1, '2025-06-15 10:00:00', 'Debit', 'Invoice', 999999, 0, 'customer-1'),
    (1, '2025-06-20 10:00:00', 'Credit', 'DCR', 0, 800, 'customer-1'),
    (1, '2025-06-22 10:00:00', 'Credit', 'Credit Memo', 0, 50, 'customer-1'),
    (1, '2025-06-21 10:00:00', 'Debit', 'Freight Charges', 75, 0, 'customer-1'),
    (1, '2024-12-31 10:00:00', 'Debit', 'Invoice', 9000, 0, 'customer-1')");

$pdo->exec("INSERT INTO tbltransaction (lrefno, lmain_id, ldate, lcancel, limported, ltax_type) VALUES
    ('SO-JAN', 1, '2025-01-15', 0, 1, 'Inclusive'),
    ('SO-JUN-EXCLUSIVE', 1, '2025-06-10', 0, 1, 'Exclusive'),
    ('SO-JUN-INCLUSIVE', 1, '2025-06-11', 0, 1, 'Inclusive'),
    ('SO-JUN-NONIMPORTED', 1, '2025-06-12', 0, 0, 'Inclusive'),
    ('SO-JUN-CANCELLED', 1, '2025-06-15', 1, 1, 'Inclusive'),
    ('SO-OTHER-YEAR', 1, '2024-12-31', 0, 1, 'Inclusive')");
$topCustomerDate = sprintf('2025-%02d-10', (int) date('n'));
$topCustomerStmt = $pdo->prepare('INSERT INTO tbltransaction (lrefno, lmain_id, ldate, lcancel, limported, ltax_type, lcustomerid, lcompany) VALUES (?, 1, ?, 0, 1, ?, ?, ?)');
$topCustomerStmt->execute(['SO-DWI-LEGACY-EXPECTED', $topCustomerDate, 'Inclusive', 'customer-dwi', 'DWI DIESEL CAL. SERVICES CO.']);
$topCustomerStmt->execute(['SO-DWI-SECOND', $topCustomerDate, 'Inclusive', 'customer-dwi', 'DWI DIESEL CAL. SERVICES CO.']);
$topCustomerStmt->execute(['SO-DWI-THIRD', $topCustomerDate, 'Inclusive', 'customer-dwi', 'DWI DIESEL CAL. SERVICES CO.']);
$pdo->exec("INSERT INTO tbltransaction_item VALUES
    ('SO-JAN', 1, 1000, 0, 'OnStock', 'Sales Order', 'Parts', '2025-01-15'),
    ('SO-JUN-EXCLUSIVE', 1, 1000, 0, 'OnStock', 'Sales Order', 'Parts', '2025-06-10'),
    ('SO-JUN-INCLUSIVE', 1, 2500, 0, 'OnStock', 'Sales Order', 'Parts', '2025-06-11'),
    ('SO-JUN-NONIMPORTED', 1, 29460, 0, 'OnStock', 'Sales Order', 'Parts', '2025-06-12'),
    ('SO-JUN-CANCELLED', 1, 9000, 0, 'OnStock', 'Sales Order', 'Parts', '2025-06-15'),
    ('SO-OTHER-YEAR', 1, 5000, 0, 'OnStock', 'Sales Order', 'Parts', '2024-12-31')");
$pdo->exec("INSERT INTO tbltransaction_item VALUES
    ('SO-DWI-LEGACY-EXPECTED', 1, 112140, 0, 'OnStock', 'Sales Order', 'Parts', '{$topCustomerDate}'),
    ('SO-DWI-SECOND', 1, 43100, 0, 'OnStock', 'Sales Order', 'Parts', '{$topCustomerDate}'),
    ('SO-DWI-THIRD', 1, 104820, 0, 'OnStock', 'Sales Order', 'Parts', '{$topCustomerDate}')");

$pdo->exec("INSERT INTO tblinvoice_list VALUES
    (1, '2025-01-15', 'INV-JAN', 'Inclusive', NULL, 0, 'Posted'),
    (1, '2025-06-10', 'INV-JUN', 'Exclusive', '0', 0, 'Posted'),
    (1, '2025-06-15', 'INV-CANCELLED', 'Inclusive', '1', 1, 'Cancelled'),
    (1, '2024-12-31', 'INV-OTHER-YEAR', 'Inclusive', '0', 0, 'Posted')");
$pdo->exec("INSERT INTO tblinvoice_itemrec VALUES
    ('INV-JAN', 'ITEM-1', 'PART-1', 'Test item', 1, 1000, 'Parts'),
    ('INV-JUN', 'ITEM-1', 'PART-1', 'Test item', 1, 1000, 'Parts'),
    ('INV-CANCELLED', 'ITEM-1', 'PART-1', 'Test item', 1, 9000, 'Parts'),
    ('INV-OTHER-YEAR', 'ITEM-1', 'PART-1', 'Test item', 1, 5000, 'Parts')");
$pdo->exec("INSERT INTO tbldelivery_receipt VALUES
    (1, '2025-06-12', 'DR-JUN', '0', 'Posted'),
    (1, '2025-06-18', 'DR-CANCELLED', '1', 'Cancelled')");
$pdo->exec("INSERT INTO tbldelivery_receipt_items VALUES
    ('DR-JUN', 'ITEM-2', 'PART-2', 'Receipt item', 1, 2500, 'Parts'),
    ('DR-CANCELLED', 'ITEM-2', 'PART-2', 'Receipt item', 1, 6000, 'Parts')");

$result = (new DailyCallMonitoringRepository($db))->getSalesPerformanceDashboard(1, 2025);
$monthly = $result['monthly_sales'] ?? [];

dashboard_expect(count($monthly) === 12, 'response contains one row for every calendar month');
dashboard_expect(array_column($monthly, 'month') === range(1, 12), 'every monthly row has the correct month number in order');
dashboard_expect((float) $monthly[0]['sales'] === 1000.0, 'January legacy transaction sales are assigned to January');
dashboard_expect((float) $monthly[5]['sales'] === 42080.0, 'June sales follow the legacy total, including transactions with the cancel flag');
dashboard_expect((float) $monthly[5]['collections'] === 800.0, 'June collections are assigned from ledger DCR credits');
$expectedYearSales = 303140.0;
dashboard_expect((float) $result['kpis']['total_sales_ytd'] === $expectedYearSales, 'YTD sales follows legacy transaction calculation and includes every transaction in the selected year');
dashboard_expect(count(array_filter($result['top_customers'], static fn(array $row): bool => ($row['customer_id'] ?? '') === 'customer-dwi')) === 1, 'top customers contain one ranked row per customer');
dashboard_expect((float) array_values(array_filter($result['top_customers'], static fn(array $row): bool => ($row['customer_id'] ?? '') === 'customer-dwi'))[0]['amount'] === 112140.0, 'top customers match the legacy single-transaction amount for DWI Diesel');
dashboard_expect((float) $result['kpis']['total_collections_ytd'] === 800.0, 'YTD collections sum the selected year ledger credits');
dashboard_expect((float) $result['kpis']['outstanding_receivables'] === 1008224.0, 'outstanding receivables include all ledger debits less all credits');

echo "Management dashboard sales performance: 11/11 passed\n";
