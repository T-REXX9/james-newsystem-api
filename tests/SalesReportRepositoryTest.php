<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\SalesReportRepository;

function sales_report_expect(bool $condition, string $message): void
{
    if (!$condition) {
        echo "FAIL: {$message}\n";
        exit(1);
    }
}

$db = new Database(new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'test_db', 'test', 'test'));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE tblpatient (lid INTEGER PRIMARY KEY, lmain_id INTEGER, lsessionid TEXT, lcompany TEXT, lpatient_code TEXT, lstatus INTEGER, lsince TEXT, ldatereg TEXT, lsales_person TEXT)');
$pdo->exec('ALTER TABLE tblpatient ADD COLUMN ldeleted INTEGER DEFAULT 0');
$pdo->exec('CREATE TABLE tblpatient_stars (lmain_id INTEGER, lsessionid TEXT, lis_starred INTEGER)');
$pdo->exec('CREATE TABLE tblledger (lid INTEGER PRIMARY KEY, lcustomerid TEXT, ldatetime TEXT)');
$pdo->exec('CREATE TABLE tblaccount (lid INTEGER PRIMARY KEY, lfname TEXT, llname TEXT, ltype TEXT, larchieve INTEGER, lmother_id INTEGER, lstatus INTEGER)');
$pdo->exec('CREATE TABLE tblcategory (lid INTEGER PRIMARY KEY, lname TEXT, lmain_id INTEGER)');
$pdo->exec('CREATE TABLE tbltransaction (lrefno TEXT PRIMARY KEY, lsaleno TEXT, lmain_id INTEGER, lsales_person_id TEXT, ltax_type TEXT)');
$pdo->exec('CREATE TABLE tbltransaction_item (lid INTEGER PRIMARY KEY, lrefno TEXT, lqty REAL, lprice REAL, ltype TEXT, lcancel INTEGER, lremark TEXT, lcategory TEXT, ltransaction_date TEXT)');
$pdo->exec('CREATE TABLE tblinvoice_list (lid INTEGER PRIMARY KEY, lrefno TEXT, lmain_id INTEGER, ldatetime TEXT, ldate TEXT, lcustomer_name TEXT, lcustomerid TEXT, lterms TEXT, linvoice_no TEXT, lsales_refno TEXT, ltax_type TEXT, lsales_person TEXT, lcancel TEXT, lcancel_invoice INTEGER, lstatus TEXT)');
$pdo->exec('CREATE TABLE tblinvoice_itemrec (lid INTEGER PRIMARY KEY, linvoice_refno TEXT, lqty REAL, lprice REAL, lcategory TEXT)');
$pdo->exec('CREATE TABLE tbldelivery_receipt (lid INTEGER PRIMARY KEY, lrefno TEXT, lmain_id INTEGER, ldate TEXT, lcustomer_name TEXT, lcustomerid TEXT, lterms TEXT, linvoice_no TEXT, lsales_refno TEXT, ltax_type TEXT, lsales_person TEXT, lcancel INTEGER, lstatus TEXT)');
$pdo->exec('CREATE TABLE tbldelivery_receipt_items (lid INTEGER PRIMARY KEY, lor_refno TEXT, lqty REAL, lprice REAL, lcategory TEXT)');

$reflection = new ReflectionClass($db);
$property = $reflection->getProperty('pdo');
$property->setValue($db, $pdo);

$pdo->exec("INSERT INTO tblpatient VALUES
    (1, 1, 'cust-a', 'Alpha', 'A-1', 1, '2020-01-01', NULL, '12', 0),
    (2, 1, 'cust-b', 'Beta', 'B-1', 1, NULL, '" . date('Y-m-d') . "', '13', 0),
    (3, 1, 'cust-c', 'Gamma', 'C-1', 1, NULL, NULL, '12', 0),
    (4, 1, 'cust-hidden', 'Hidden', 'H-1', 0, '2020-01-01', NULL, NULL, 0)");
$pdo->exec("INSERT INTO tblpatient_stars VALUES (1, 'cust-a', 1), (1, 'cust-b', 0)");
$pdo->exec("INSERT INTO tblledger VALUES (1, 'cust-c', '" . date('Y-m-d') . " 09:00:00')");

$pdo->exec("INSERT INTO tblaccount VALUES
    (12, 'Alice', 'Agent', '2', 0, 1, 1),
    (13, 'Archived', 'Agent', '2', 1, 1, 0),
    (14, 'Chris', 'Agent', '2', 0, 1, 1),
    (15, 'Chris', 'Agent', '2', 0, 1, 1),
    (16, 'Inactive', 'Agent', '2', 0, 1, 0)");
$pdo->exec("INSERT INTO tblcategory VALUES (1, 'Parts', 1), (2, 'Service', 1)");
$pdo->exec("INSERT INTO tbltransaction VALUES
    ('so-1', 'SO-1', 1, '12', 'Exclusive'),
    ('so-unposted', 'SO-UNPOSTED', 1, '12', 'Inclusive')");
$pdo->exec("INSERT INTO tbltransaction_item VALUES
    (1, 'so-1', 1, 100, 'Parts', 0, 'OnStock', 'Parts', '2026-09-10'),
    (2, 'so-unposted', 1, 999, 'Parts', 0, 'Draft', 'Parts', '2026-09-10')");
$pdo->exec("INSERT INTO tblinvoice_list VALUES
    (1, 'inv-1', 1, '2026-10-01 12:00:00', '2026-09-10', 'Alpha', 'cust-a', '30 DAYS', 'INV-1', 'so-1', 'Inclusive', 'Alice', NULL, 0, 'Posted'),
    (2, 'inv-cancelled', 1, '2026-10-01 12:00:00', '2026-09-10', 'Alpha', 'cust-a', '30 DAYS', 'INV-C', 'so-1', 'Inclusive', 'Alice', '1', 0, 'Posted'),
    (3, 'inv-exclusive', 1, '2026-10-01 13:00:00', '2026-09-10', 'Alpha', 'cust-a', '30 DAYS', 'INV-2', 'so-1', 'Exclusive', 'Alice', NULL, 1, 'Cancelled'),
    (4, 'inv-before-legacy-cutoff', 1, '2026-10-01 00:30:00', '2026-09-09', 'Alpha', 'cust-a', '30 DAYS', 'INV-3', 'so-1', 'Inclusive', 'Alice', NULL, 0, 'Posted')");
$pdo->exec("INSERT INTO tblinvoice_itemrec VALUES
    (1, 'inv-1', 2, 100, 'Parts'),
    (2, 'inv-cancelled', 1, 500, 'Parts'),
    (3, 'inv-exclusive', 1, 100, 'Parts'),
    (4, 'inv-before-legacy-cutoff', 1, 700, 'Parts')");
$pdo->exec("INSERT INTO tbldelivery_receipt VALUES
    (1, 'dr-1', 1, '2026-09-11', 'Beta', 'cust-b', 'LBC COD', 'DR-1', 'so-1', 'Inclusive', 'Bob', NULL, 'Posted'),
    (2, 'dr-zero-cancel-marker', 1, '2026-09-11', 'Beta', 'cust-b', 'LBC COD', 'DR-2', 'so-1', 'Inclusive', 'Bob', 1, 'Posted')");
$pdo->exec("INSERT INTO tbldelivery_receipt_items VALUES
    (1, 'dr-1', 1, 150, 'Parts'),
    (2, 'dr-zero-cancel-marker', 1, 75, 'Parts')");
$pdo->exec('ALTER TABLE tblinvoice_itemrec ADD COLUMN litemcode TEXT');
$pdo->exec('ALTER TABLE tblinvoice_itemrec ADD COLUMN lpartno TEXT');
$pdo->exec('ALTER TABLE tblinvoice_itemrec ADD COLUMN lbrand TEXT');
$pdo->exec('ALTER TABLE tblinvoice_itemrec ADD COLUMN ldesc TEXT');
$pdo->exec('ALTER TABLE tbldelivery_receipt_items ADD COLUMN litemcode TEXT');
$pdo->exec('ALTER TABLE tbldelivery_receipt_items ADD COLUMN lpartno TEXT');
$pdo->exec('ALTER TABLE tbldelivery_receipt_items ADD COLUMN lbrand TEXT');
$pdo->exec('ALTER TABLE tbldelivery_receipt_items ADD COLUMN ldesc TEXT');
$pdo->exec("UPDATE tblinvoice_itemrec SET litemcode = 'P-1', lpartno = 'PART-1', lbrand = 'Brand', ldesc = 'Engine Part'");
$pdo->exec("UPDATE tbldelivery_receipt_items SET litemcode = 'P-1', lpartno = 'PART-1', lbrand = 'Brand', ldesc = 'Engine Part'");

$repo = new SalesReportRepository($db);
$customers = $repo->listCustomers(1);
sales_report_expect(count($customers) === 3, 'customer list includes every active legacy customer without a report-page cap');

$report = $repo->getSalesReport(1, 'custom', '2026-09-10', '2026-09-11', 'All');
$transactionCount = count($report['transactions']);
sales_report_expect($transactionCount === 3, "report matches legacy invoice and delivery-receipt cancellation rules (got {$transactionCount})");
sales_report_expect(($report['summary']['grandTotal']['soAmount'] ?? null) === 300.0, 'sales-order amount remains a reference column for each source document');
sales_report_expect(($report['summary']['grandTotal']['drAmount'] ?? null) === 150.0, 'delivery-receipt total is included');
sales_report_expect(($report['summary']['grandTotal']['invoiceAmount'] ?? null) === 312.0, 'VAT-exclusive invoices receive the legacy 12 percent display adjustment');
sales_report_expect(($report['summary']['grandTotal']['total'] ?? null) === 462.0, 'total sales uses legacy invoice and delivery-receipt display amounts only');
sales_report_expect(($report['summary']['starredCustomerSales']['total'] ?? null) === 312.0, 'starred-customer sales include only posted invoice and DR values for currently starred customers');
sales_report_expect(($report['summary']['starredCustomerSales']['customerCount'] ?? null) === 1, 'starred-customer count includes unique customers with matching posted sales');
$starredCustomerReport = $repo->getSalesReport(1, 'custom', '2026-09-10', '2026-09-11', 'cust-a');
sales_report_expect(($starredCustomerReport['summary']['starredCustomerSales']['total'] ?? null) === 312.0, 'starred-sales total follows the selected-customer filter');
$starredAgentReport = $repo->getSalesReport(1, 'custom', '2026-09-10', '2026-09-11', 'All', 1200, '12');
sales_report_expect(($starredAgentReport['summary']['starredCustomerSales']['total'] ?? null) === 312.0, 'starred-sales total follows the assigned-agent filter');
sales_report_expect(($report['summary']['productTotals'][0]['product'] ?? null) === 'Engine Part', 'product breakdown names the leading product');
sales_report_expect(abs((float) ($report['summary']['productTotals'][0]['total'] ?? 0) - 462.0) < 0.001, 'product breakdown reconciles to posted invoice and delivery-receipt sales including exclusive VAT');
sales_report_expect(($report['transactions'][0]['date'] ?? null) === '2026-09-10', 'invoice report date uses the true sales date, not the import timestamp');
sales_report_expect(($report['transactions'][0]['customer_type'] ?? null) === 'old', 'sales report classifies pre-2026 customers as existing');
sales_report_expect(($report['transactions'][2]['customer_type'] ?? null) === 'new', 'sales report classifies customers since 2026 as new');
sales_report_expect(($report['summary']['salespersonTotals'][0]['salesperson'] ?? null) === 'Alice Agent', 'salesperson display uses the legacy active sales-account list');
sales_report_expect(($report['summary']['salespersonTotals'][2]['salesperson'] ?? null) === 'Chris Agent', 'active sales agents with no sales still appear in the period summary');
sales_report_expect(($report['summary']['salespersonTotals'][2]['id'] ?? null) === '14', 'summary-only salesperson rows include the stable staff ID for quota matching');
sales_report_expect(in_array('15', array_column($report['summary']['salespersonTotals'], 'id'), true), 'agents sharing a display name retain distinct stable IDs in the summary');
sales_report_expect(!in_array('16', array_column($report['summary']['salespersonTotals'], 'id'), true), 'inactive non-archived accounts are excluded like the Staff page');
sales_report_expect(($report['summary']['salespersonTotals'][2]['total'] ?? null) === 0.0, 'agents with no sales receive an accurate zero performance total');
sales_report_expect(($report['summary']['salespersonTotals'][0]['categories'][0]['category'] ?? null) === 'Parts', 'salesperson display groups legacy OnStock Sales Order items by category');
sales_report_expect(abs((float) ($report['summary']['salespersonTotals'][0]['total'] ?? 0) - 312.0) < 0.001, 'salesperson total reflects posted invoice value including the exclusive-VAT adjustment');

$monthReport = $repo->getSalesReport(1, 'month', null, null, 'All');
sales_report_expect(($monthReport['date_from'] ?? null) === date('Y-m-01'), 'month starts on the first day like the legacy report');
sales_report_expect(($monthReport['date_to'] ?? null) === date('Y-m-t'), 'month ends on the last day like the legacy report');

$allReport = $repo->getSalesReport(1, 'all', null, null, 'All');
sales_report_expect(($allReport['date_from'] ?? null) === '2013-06-01', 'all-time coverage starts on the legacy cutoff date');
sales_report_expect(($allReport['date_to'] ?? null) === date('Y-m-d'), 'all-time coverage stops on today like the legacy report');

echo "Tests passed.\n";
