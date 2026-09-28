<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\SalesReportRepository;
use App\Support\PostedSalesDocumentSql;

/**
 * Behavioral regression for a production class of mismatch: a posted receipt
 * can remain after its customer master is soft-deleted or absent. The Daily
 * Call customer universe must retain that sale as a repairable exception.
 */
function reconciliation_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
}

$db = new Database(new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'test_db', 'test', 'test'));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE tblpatient (lmain_id INTEGER, lsessionid TEXT, lcompany TEXT, ldeleted INTEGER)');
$pdo->exec('CREATE TABLE tblaccount (lid INTEGER PRIMARY KEY, lfname TEXT, ltype TEXT, larchieve INTEGER, lmother_id INTEGER)');
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

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$pdo->exec("INSERT INTO tblpatient VALUES (1, 'active-customer', 'Active Customer', 0), (1, 'orphan-crisjeff', 'Old CRISJEFF', 1), (1, 'greg-lcancel-zero', 'GREG CALIBRATION CENTER', 0)");
$pdo->exec("INSERT INTO tblinvoice_list VALUES (1, 'INV-ACTIVE', 1, '{$today} 08:00:00', '{$today}', 'Active Customer', 'active-customer', '', '', '', 'Inclusive', '', NULL, 0, 'Posted')");
$pdo->exec("INSERT INTO tblinvoice_itemrec VALUES (1, 'INV-ACTIVE', 1, 100, 'Parts')");
$pdo->exec("INSERT INTO tbldelivery_receipt VALUES (1, 'N-D38803', 1, '{$today}', 'CRISJEFF CALIBRATION SERVICES', 'orphan-crisjeff', '', '', '', 'Inclusive', '', NULL, 'Posted')");
$pdo->exec("INSERT INTO tbldelivery_receipt_items VALUES (1, 'N-D38803', 1, 10200, 'Parts')");
$pdo->exec("INSERT INTO tbldelivery_receipt VALUES (2, 'N-FUTURE', 1, '{$tomorrow}', 'Future Customer', 'future-customer', '', '', '', 'Inclusive', '', NULL, 'Posted')");
$pdo->exec("INSERT INTO tbldelivery_receipt_items VALUES (2, 'N-FUTURE', 1, 800, 'Parts')");
// Posted delivery receipt whose lcancel is the integer 0 (NOT NULL / '') --
// the real production shape (e.g. GREG's N-D39172). Must count as a sale.
$pdo->exec("INSERT INTO tbldelivery_receipt VALUES (3, 'N-D39172', 1, '{$today}', 'GREG CALIBRATION CENTER', 'greg-lcancel-zero', '', '', '', 'Inclusive', '', '0', 'Posted')");
$pdo->exec("INSERT INTO tbldelivery_receipt_items VALUES (3, 'N-D39172', 1, 12000, 'Parts')");

$salesReport = (new SalesReportRepository($db))->getSalesReport(1, 'custom', $today, $today, 'All');
$salesReportTotal = (float) ($salesReport['summary']['grandTotal']['total'] ?? 0);
reconciliation_expect($salesReportTotal === 22300.0, 'Sales Report includes the active invoice, orphaned receipt, and lcancel=0 posted receipt');

// SQLite does not implement MySQL date helpers. Anchor its replacements to the
// PHP test date instead of SQLite's UTC clock so the fixture remains stable in
// Asia/Manila around midnight.
$postedSalesCtes = str_replace(
    ["DATE_ADD(CURDATE(), INTERVAL 1 DAY)", "DATE_FORMAT(CURDATE(), '%Y-%m-01')"],
    ["date('{$today}', '+1 day')", "date('{$today}', 'start of month')"],
    PostedSalesDocumentSql::currentMonthCustomerSalesCtes()
);

$dailyCallUniverseSql = <<<SQL
WITH {$postedSalesCtes},
customer_universe AS (
    SELECT p.lsessionid AS customer_id, p.lcompany AS customer_name, 0 AS data_integrity_exception
    FROM tblpatient p
    WHERE p.lmain_id = 1 AND COALESCE(p.ldeleted, 0) = 0
    UNION ALL
    SELECT sales.customer_id, COALESCE(sales.customer_name, 'Unnamed posted-sales customer'), 1
    FROM sales_report_current_month sales
    LEFT JOIN tblpatient live_customer
      ON live_customer.lmain_id = 1
     AND live_customer.lsessionid = sales.customer_id
     AND COALESCE(live_customer.ldeleted, 0) = 0
    WHERE live_customer.lsessionid IS NULL
)
SELECT customer_universe.customer_id, customer_universe.customer_name,
       customer_universe.data_integrity_exception,
       COALESCE(sales.current_month_sales, 0) AS current_month_sales
FROM customer_universe
LEFT JOIN sales_report_current_month sales ON sales.customer_id = customer_universe.customer_id
ORDER BY customer_universe.customer_id
SQL;

$stmt = $pdo->prepare($dailyCallUniverseSql);
$stmt->execute([
    'sales_report_invoice_main_id' => 1,
    'sales_report_dr_main_id' => 1,
]);
$dailyCallRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$dailyCallTotal = array_sum(array_map(static fn(array $row): float => (float) $row['current_month_sales'], $dailyCallRows));
$orphan = array_values(array_filter($dailyCallRows, static fn(array $row): bool => $row['customer_id'] === 'orphan-crisjeff'))[0] ?? null;

reconciliation_expect($dailyCallTotal === $salesReportTotal, 'Daily Call current-month rows reconcile exactly with Sales Report');
reconciliation_expect($orphan !== null, 'soft-deleted customer remains in the Daily Call universe');
reconciliation_expect((int) $orphan['data_integrity_exception'] === 1, 'orphaned posted sale is identified as a data-integrity exception');
reconciliation_expect($orphan['customer_name'] === 'CRISJEFF CALIBRATION SERVICES', 'orphan uses the document customer name without inventing customer data');
reconciliation_expect((float) $orphan['current_month_sales'] === 10200.0, 'orphan retains delivery receipt N-D38803 amount');
$gregRow = array_values(array_filter($dailyCallRows, static fn(array $row): bool => $row['customer_id'] === 'greg-lcancel-zero'))[0] ?? null;
reconciliation_expect($gregRow !== null, 'lcancel=0 posted-receipt customer appears in the Daily Call universe');
reconciliation_expect((float) $gregRow['current_month_sales'] === 12000.0, 'lcancel=0 posted delivery receipt N-D39172 counts as current-month sales');
reconciliation_expect(!in_array('future-customer', array_column($dailyCallRows, 'customer_id'), true), 'future-dated posted documents are excluded through today');

echo "Posted sales / Daily Call reconciliation regression passed.\n";
