<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\AccountsReceivableRepository;

function expectSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf(
            "FAIL: %s (expected %s, got %s)\n",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
        exit(1);
    }
}

$database = new Database(new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'test_db', 'test', 'test'));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE tblpatient (
    lid INTEGER PRIMARY KEY,
    lsessionid TEXT,
    lpatient_code TEXT,
    lcompany TEXT,
    lmain_id INTEGER,
    lstatus INTEGER,
    ldebt_type TEXT
)');
$pdo->exec('CREATE TABLE tblledger (
    lid INTEGER PRIMARY KEY,
    lcustomerid TEXT,
    lrefno TEXT,
    lmesssage TEXT,
    ldebit REAL,
    lcredit REAL,
    ldatetime TEXT,
    ltype TEXT,
    lref_name TEXT
)');
$pdo->exec('CREATE TABLE tbltransaction (
    lid INTEGER PRIMARY KEY,
    invoice_refno TEXT,
    ldr_refno TEXT,
    lterms TEXT
)');

$reflection = new ReflectionClass($database);
$property = $reflection->getProperty('pdo');
$property->setValue($database, $pdo);

$customerInsert = $pdo->prepare(
    'INSERT INTO tblpatient (lid, lsessionid, lpatient_code, lcompany, lmain_id, lstatus, ldebt_type)
     VALUES (:lid, :session_id, :code, :company, 1, 1, :debt_type)'
);
$ledgerInsert = $pdo->prepare(
    'INSERT INTO tblledger (lid, lcustomerid, lrefno, lmesssage, ldebit, lcredit, ldatetime, ltype, lref_name)
     VALUES (:lid, :customer_id, :refno, :reference, 10, 0, :occurred_at, \'Debit\', NULL)'
);
$transactionInsert = $pdo->prepare(
    'INSERT INTO tbltransaction (lid, invoice_refno, ldr_refno, lterms)
     VALUES (:lid, :invoice_refno, \'\', \'30 Days\')'
);

for ($index = 1; $index <= 1005; $index++) {
    $sessionId = 'customer-' . $index;
    $reference = 'invoice-' . $index;
    $customerInsert->execute([
        'lid' => $index,
        'session_id' => $sessionId,
        'code' => 'C-' . $index,
        'company' => sprintf('Company %03d', $index),
        'debt_type' => 'Good',
    ]);
    $ledgerInsert->execute([
        'lid' => $index,
        'customer_id' => $sessionId,
        'refno' => $reference,
        'reference' => 'INV-' . $index,
        'occurred_at' => '2026-08-01 12:00:00',
    ]);
    $transactionInsert->execute([
        'lid' => $index,
        'invoice_refno' => $reference,
    ]);
}

$repository = new AccountsReceivableRepository($database);
$report = $repository->getReport(1, '', 'All', 'custom', '2000-01-01', '2026-08-25');

expectSameValue(1005, count($report['customers']), 'all active customers must be included');
expectSameValue(10050.0, $report['grand_total_balance'], 'grand total must include balances beyond customer 200');

echo "PASS: accounts receivable includes all 1005 active customers and batches terms lookups.\n";
