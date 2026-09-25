<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\InvoiceRepository;
use App\Services\InvoiceNumberSequenceStore;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$database = new Database(new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'test_db', 'test', 'test'));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE tblinvoice_list (lmain_id INTEGER, linvoice_no TEXT)');
$pdo->exec('CREATE TABLE tblnumber_generator (ltransaction_type TEXT, lmax_no INTEGER)');
$pdo->exec("INSERT INTO tblinvoice_list (lmain_id, linvoice_no) VALUES (1, 'T-12929'), (1, 'T-12930')");

$databaseReflection = new ReflectionClass($database);
$pdoProperty = $databaseReflection->getProperty('pdo');
$pdoProperty->setValue($database, $pdo);

$sequencePath = tempnam(sys_get_temp_dir(), 'invoice-number-sequence-');
if ($sequencePath === false) {
    throw new RuntimeException('FAIL: unable to create sequence test file');
}
file_put_contents($sequencePath, "{\n  \"1\": {\n    \"prefix\": \"T-\",\n    \"pad_width\": 0,\n    \"next_number\": 12929\n  }\n}");

try {
    $repo = new InvoiceRepository($database, new InvoiceNumberSequenceStore($sequencePath));
    $allocate = new ReflectionMethod($repo, 'allocateNextInvoiceNumber');
    $allocated = $allocate->invoke($repo, 1);

    $assert(($allocated['invoice_no'] ?? null) === 'T-12931', 'allocation skips occupied numbers');
    $assert((int) ($allocated['number'] ?? 0) === 12931, 'allocation returns the selected numeric sequence');
    $assert((int) $pdo->query("SELECT lmax_no FROM tblnumber_generator WHERE ltransaction_type = 'Invoice'")->fetchColumn() === 12931, 'number generator advances to the selected number');

    $saved = (new InvoiceNumberSequenceStore($sequencePath))->loadForMain(1);
    $assert($saved['next_invoice_no'] === 'T-12932', 'following allocation begins after the selected number');

    echo "PASS: invoice allocation skips stored-number collisions\n";
} finally {
    unlink($sequencePath);
}
