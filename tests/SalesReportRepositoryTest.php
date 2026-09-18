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

$pdo->exec('CREATE TABLE tblpatient (lid INTEGER PRIMARY KEY, lmain_id INTEGER, lsessionid TEXT, lcompany TEXT, lpatient_code TEXT, lstatus INTEGER)');
$pdo->exec('CREATE TABLE tbltransaction (lrefno TEXT PRIMARY KEY, lsaleno TEXT)');
$pdo->exec('CREATE TABLE tbltransaction_item (lid INTEGER PRIMARY KEY, lrefno TEXT, lqty REAL, lprice REAL, ltype TEXT, lcancel INTEGER)');
$pdo->exec('CREATE TABLE tblinvoice_list (lid INTEGER PRIMARY KEY, lrefno TEXT, lmain_id INTEGER, ldatetime TEXT, lcustomer_name TEXT, lcustomerid TEXT, lterms TEXT, linvoice_no TEXT, lsales_refno TEXT, ltax_type TEXT, lsales_person TEXT, lcancel TEXT, lcancel_invoice INTEGER, lstatus TEXT)');
$pdo->exec('CREATE TABLE tblinvoice_itemrec (lid INTEGER PRIMARY KEY, linvoice_refno TEXT, lqty REAL, lprice REAL, lcategory TEXT)');
$pdo->exec('CREATE TABLE tbldelivery_receipt (lid INTEGER PRIMARY KEY, lrefno TEXT, lmain_id INTEGER, ldate TEXT, lcustomer_name TEXT, lcustomerid TEXT, lterms TEXT, linvoice_no TEXT, lsales_refno TEXT, ltax_type TEXT, lsales_person TEXT, lcancel INTEGER, lstatus TEXT)');
$pdo->exec('CREATE TABLE tbldelivery_receipt_items (lid INTEGER PRIMARY KEY, lor_refno TEXT, lqty REAL, lprice REAL, lcategory TEXT)');

$reflection = new ReflectionClass($db);
$property = $reflection->getProperty('pdo');
$property->setValue($db, $pdo);

$pdo->exec("INSERT INTO tblpatient VALUES
    (1, 1, 'cust-a', 'Alpha', 'A-1', 1),
    (2, 1, 'cust-b', 'Beta', 'B-1', 1),
    (3, 1, 'cust-c', 'Gamma', 'C-1', 1),
    (4, 1, 'cust-hidden', 'Hidden', 'H-1', 0)");

$pdo->exec("INSERT INTO tbltransaction VALUES ('so-1', 'SO-1'), ('so-unposted', 'SO-UNPOSTED')");
$pdo->exec("INSERT INTO tbltransaction_item VALUES
    (1, 'so-1', 1, 100, 'Parts', 0),
    (2, 'so-unposted', 1, 999, 'Parts', 0)");
$pdo->exec("INSERT INTO tblinvoice_list VALUES
    (1, 'inv-1', 1, '2026-09-10 12:00:00', 'Alpha', 'cust-a', '30 DAYS', 'INV-1', 'so-1', 'Inclusive', 'Alice', NULL, 0, 'Posted'),
    (2, 'inv-cancelled', 1, '2026-09-10 12:00:00', 'Alpha', 'cust-a', '30 DAYS', 'INV-C', 'so-1', 'Inclusive', 'Alice', NULL, 1, 'Cancelled')");
$pdo->exec("INSERT INTO tblinvoice_itemrec VALUES
    (1, 'inv-1', 2, 100, 'Parts'),
    (2, 'inv-cancelled', 1, 500, 'Parts')");
$pdo->exec("INSERT INTO tbldelivery_receipt VALUES
    (1, 'dr-1', 1, '2026-09-11', 'Beta', 'cust-b', 'LBC COD', 'DR-1', 'so-1', 'Inclusive', 'Bob', 0, 'Posted')");
$pdo->exec("INSERT INTO tbldelivery_receipt_items VALUES (1, 'dr-1', 1, 150, 'Parts')");

$repo = new SalesReportRepository($db);
$customers = $repo->listCustomers(1);
sales_report_expect(count($customers) === 3, 'customer list includes every active legacy customer without a report-page cap');

$report = $repo->getSalesReport(1, 'custom', '2026-09-10', '2026-09-11', 'All');
$transactionCount = count($report['transactions']);
sales_report_expect($transactionCount === 2, "report uses only invoice and delivery-receipt rows, not standalone sales orders (got {$transactionCount})");
sales_report_expect(($report['summary']['grandTotal']['soAmount'] ?? null) === 200.0, 'sales-order amount remains a reference column for source documents');
sales_report_expect(($report['summary']['grandTotal']['drAmount'] ?? null) === 150.0, 'delivery-receipt total is included');
sales_report_expect(($report['summary']['grandTotal']['invoiceAmount'] ?? null) === 200.0, 'invoice total is included');
sales_report_expect(($report['summary']['grandTotal']['total'] ?? null) === 350.0, 'total sales uses invoices plus delivery receipts and does not add sales-order amounts');

echo "Tests passed.\n";
