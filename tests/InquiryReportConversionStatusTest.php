<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\InquiryReportRepository;

function inquiry_report_conversion_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$db = new Database(new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'test_db', 'test', 'test'));
$pdo = class_exists(\Pdo\Sqlite::class)
    ? new \Pdo\Sqlite('sqlite::memory:')
    : new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($pdo instanceof \Pdo\Sqlite) {
    $pdo->createFunction('CONCAT', static fn(...$parts): string => implode('', $parts));
} else {
    $pdo->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', $parts));
}
$pdo->exec('CREATE TABLE tblinquiry (
    lid INTEGER PRIMARY KEY,
    lmain_id INTEGER,
    IsCancel INTEGER,
    lrefno TEXT,
    lso_refno TEXT,
    linqno TEXT,
    lcustomerid TEXT,
    lcompany TEXT,
    ldate TEXT,
    ltime TEXT
)');
$pdo->exec('CREATE TABLE tblinquiry_item (
    lid INTEGER PRIMARY KEY,
    linq_refno TEXT,
    lqty REAL,
    lprice REAL
)');
$pdo->exec('CREATE TABLE tbltransaction (
    lid INTEGER PRIMARY KEY,
    lmain_id INTEGER,
    lrefno TEXT,
    linquiry_refno TEXT,
    lcancel INTEGER,
    IsInquiry INTEGER
)');

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$pdo->prepare('INSERT INTO tblinquiry VALUES
    (1, 1, 0, :converted_ref, "", "INQ-1", "C-1", "Converted Customer", :today, "10:00:00"),
    (2, 1, 0, "inq-unconverted", "", "INQ-2", "C-2", "Open Customer", :today, "11:00:00"),
    (3, 1, 0, "inq-cancelled-order", "", "INQ-3", "C-3", "Cancelled Order Customer", :today, "12:00:00"),
    (4, 1, 0, "inq-other-tenant", "", "INQ-4", "C-4", "Other Tenant Customer", :today, "13:00:00"),
    (5, 1, 0, "legacy-inquiry", "legacy-order", "INQ-5", "C-5", "Legacy Converted Customer", :today, "14:00:00"),
    (6, 1, 0, "inq-legacy-flag", "", "INQ-6", "C-6", "Legacy Flagged Customer", :today, "15:00:00")')
    ->execute(['converted_ref' => 'inq-converted', 'today' => $today]);
$pdo->exec('INSERT INTO tbltransaction VALUES
    (1, 1, "order-1", "inq-converted", 0, 0),
    (2, 1, "order-2", "inq-cancelled-order", 1, 0),
    (3, 2, "order-3", "inq-other-tenant", 0, 0),
    (4, 1, "legacy-order", "", 0, 1),
    (5, 1, "legacy-flagged-order", "inq-legacy-flag", 0, 1)');

$property = (new ReflectionClass($db))->getProperty('pdo');
$property->setValue($db, $pdo);
$report = (new InquiryReportRepository($db))->getInquiryReport(1, 'summary', 'custom', $today, $today, null, 100);
$itemsByNo = array_column($report['items'], null, 'inquiry_no');

inquiry_report_conversion_expect(($itemsByNo['INQ-1']['converted_to_order'] ?? null) === true, 'linked active sales order marks inquiry as converted');
inquiry_report_conversion_expect(($itemsByNo['INQ-2']['converted_to_order'] ?? null) === false, 'inquiry without a sales order is marked unconverted');
inquiry_report_conversion_expect(($itemsByNo['INQ-3']['converted_to_order'] ?? null) === false, 'cancelled sales order does not count as a real order');
inquiry_report_conversion_expect(($itemsByNo['INQ-4']['converted_to_order'] ?? null) === false, 'another tenant sales order does not mark inquiry as converted');
inquiry_report_conversion_expect(($itemsByNo['INQ-5']['converted_to_order'] ?? null) === true, 'legacy sales order linkage marks inquiry as converted');
inquiry_report_conversion_expect(($itemsByNo['INQ-6']['converted_to_order'] ?? null) === true, 'legacy linked sales documents remain recognized despite the IsInquiry flag');

echo "Inquiry report conversion status tests passed.\n";
