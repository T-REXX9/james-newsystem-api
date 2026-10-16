<?php

declare(strict_types=1);

$repository = file_get_contents(__DIR__ . '/../src/Repositories/FreightChargesRepository.php');
$view = file_get_contents(__DIR__ . '/../../james-newsystem/components/FreightChargesDebitView.tsx');
if ($repository === false || $view === false) {
    fwrite(STDERR, "FAIL unable to read freight charges sources\n");
    exit(1);
}

$checks = [
    'linked transaction lookup is used in list and detail queries' => substr_count($repository, '{$transactionNumberSql} AS linvoice_no') === 2,
    'invoice references resolve to their displayed invoice number' => str_contains($repository, 'FROM tblinvoice_list invoice') && str_contains($repository, 'invoice.lrefno = deb.ltrans_refno'),
    'order slip references resolve to their displayed order number' => str_contains($repository, 'FROM tbldelivery_receipt delivery') && str_contains($repository, 'delivery.lrefno = deb.ltrans_refno'),
    'linked document lookup is scoped to the freight record tenant' => substr_count($repository, 'CAST(COALESCE(deb.lmain_id, 0) AS SIGNED)') >= 2,
    'page does not display the internal reference as a fallback' => str_contains($view, "if (row.linvoice_no) return row.linvoice_no;") && !str_contains($view, 'row.ltrans_refno || row.ltransaction_type'),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    if ($passed) {
        echo "  PASS {$label}\n";
        continue;
    }

    fwrite(STDERR, "  FAIL {$label}\n");
    $failed++;
}

echo 'Results: ' . (count($checks) - $failed) . ' passed, ' . $failed . " failed\n";
if ($failed > 0) {
    exit(1);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "FAIL SQLite PDO driver is required for the linked-number query check\n");
    exit(1);
}

if (preg_match("/return <<<\'SQL\'\\n(.*?)\\nSQL;/s", $repository, $matches) !== 1) {
    fwrite(STDERR, "FAIL unable to extract linked transaction number expression\n");
    exit(1);
}

$expression = str_replace('SIGNED', 'INTEGER', $matches[1]);
$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE tbldebit_memo (lid INTEGER, lmain_id TEXT, ltransaction_type TEXT, ltrans_refno TEXT, linvoice_no TEXT)');
$pdo->exec('CREATE TABLE tblinvoice_list (lrefno TEXT, lmain_id TEXT, linvoice_no TEXT)');
$pdo->exec('CREATE TABLE tbldelivery_receipt (lrefno TEXT, lmain_id TEXT, linvoice_no TEXT)');
$pdo->exec("INSERT INTO tbldebit_memo VALUES (1, '1', 'Order Slip', 'order-ref', ''), (2, '1', 'Invoice', 'invoice-ref', ''), (3, '1', 'Order Slip', 'other-tenant-ref', ''), (4, '1', NULL, 'legacy-order-ref', '')");
$pdo->exec("INSERT INTO tbldelivery_receipt VALUES ('order-ref', '1', 'N-D39139'), ('other-tenant-ref', '2', 'WRONG-TENANT'), ('legacy-order-ref', '1', 'N-OLD-123')");
$pdo->exec("INSERT INTO tblinvoice_list VALUES ('invoice-ref', '1', 'INV-1234')");

$statement = $pdo->prepare("SELECT {$expression} AS linvoice_no FROM tbldebit_memo deb WHERE deb.lid = :id");
$expected = [1 => 'N-D39139', 2 => 'INV-1234', 3 => '', 4 => 'N-OLD-123'];
foreach ($expected as $id => $expectedNumber) {
    $statement->execute(['id' => $id]);
    $actualNumber = (string) $statement->fetchColumn();
    if ($actualNumber !== $expectedNumber) {
        fwrite(STDERR, "FAIL linked number for fixture {$id}: expected '{$expectedNumber}', got '{$actualNumber}'\n");
        exit(1);
    }
}

echo "  PASS linked-number query resolves invoices and order slips, and rejects cross-tenant matches\n";
echo "Results: 1 query behavior check passed\n";
