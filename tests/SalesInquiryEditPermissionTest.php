<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/SalesInquiryEditPolicy.php';
require_once __DIR__ . '/../src/Support/Exceptions/HttpException.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Controllers/SalesInquiryPrintController.php';

use App\Config;
use App\Controllers\SalesInquiryPrintController;
use App\Database;
use App\Support\SalesInquiryEditPolicy;

$checks = [
    'Master User can edit confirmed inquiry without staff approval' => SalesInquiryEditPolicy::canEditConfirmedInquiry(true, false),
    'staff with matching inquiry approval can edit confirmed inquiry' => SalesInquiryEditPolicy::canEditConfirmedInquiry(false, true),
    'staff without inquiry approval cannot edit confirmed inquiry' => !SalesInquiryEditPolicy::canEditConfirmedInquiry(false, false),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('FAIL: sqlite PDO driver is required for controller regression coverage');
}

$database = new Database(new Config('test', true, '*', 'test', 60, '', 0, '', '', ''));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE tblinquiry (lmain_id INTEGER, lrefno TEXT)');
$pdo->exec('CREATE TABLE sales_inquiry_confirmations (main_id INTEGER, inquiry_refno TEXT)');
$pdo->exec('CREATE TABLE sales_inquiry_edit_requests (main_id INTEGER, inquiry_refno TEXT, requested_by INTEGER, status TEXT)');
$pdo->exec("INSERT INTO tblinquiry (lmain_id, lrefno) VALUES (7, 'inq-1')");
$pdo->exec("INSERT INTO sales_inquiry_confirmations (main_id, inquiry_refno) VALUES (7, 'inq-1')");
$databasePdo = new ReflectionProperty(Database::class, 'pdo');
$databasePdo->setValue($database, $pdo);

$status = (new SalesInquiryPrintController($database))->editAccessStatus(
    ['inquiryRefno' => 'inq-1'],
    [],
    ['__auth_claims' => ['main_userid' => 7, 'sub' => 42, 'user_type' => '2']]
);
if (($status['confirmed'] ?? false) !== true || ($status['can_edit'] ?? true) !== false) {
    throw new RuntimeException('FAIL: confirmed inquiry edit status should resolve policy and deny unapproved staff');
}
echo "PASS: edit-access controller resolves policy and denies unapproved staff\n";
