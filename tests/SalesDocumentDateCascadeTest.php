<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/SalesDocumentDateCascade.php';

use App\Support\SalesDocumentDateCascade;

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE tblinquiry (
  lmain_id TEXT, lrefno TEXT, ldate TEXT, ltransaction_status TEXT, IsCancel INTEGER DEFAULT 0
)');
$pdo->exec('CREATE TABLE tbltransaction (
  lmain_id TEXT, lrefno TEXT, linquiry_refno TEXT, ldate TEXT, ltransaction_status TEXT, lsubmitstat TEXT
)');
$pdo->exec('CREATE TABLE tbldelivery_receipt (
  lmain_id TEXT, lrefno TEXT, lsales_refno TEXT, ldate TEXT, lstatus TEXT
)');
$pdo->exec('CREATE TABLE tblinvoice_list (
  lmain_id TEXT, lrefno TEXT, lsales_refno TEXT, ldate TEXT, lstatus TEXT
)');

$pdo->exec("INSERT INTO tblinquiry VALUES ('1', 'INQ-1', '2026-09-12', 'converted', 0)");
$pdo->exec("INSERT INTO tbltransaction VALUES ('1', 'SO-1', 'INQ-1', '2026-09-12', 'Submitted', '')");
$pdo->exec("INSERT INTO tbldelivery_receipt VALUES ('1', 'OS-1', 'SO-1', '2026-09-12', 'Pending')");
$pdo->exec("INSERT INTO tblinvoice_list VALUES ('1', 'INV-1', 'SO-1', '2026-09-12', 'Draft')");
$pdo->exec("INSERT INTO tblinvoice_list VALUES ('1', 'INV-POSTED', 'SO-1', '2026-09-12', 'posted')");

SalesDocumentDateCascade::apply($pdo, 1, '2026-09-01', ['inquiry_refno' => 'INQ-1']);

$assert = static function (bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo "PASS: {$label}\n";
};

$assert($pdo->query("SELECT ldate FROM tblinquiry WHERE lrefno='INQ-1'")->fetchColumn() === '2026-09-01', 'converted inquiry still receives cascade');
$assert($pdo->query("SELECT ldate FROM tbltransaction WHERE lrefno='SO-1'")->fetchColumn() === '2026-09-01', 'sales order date cascaded');
$assert($pdo->query("SELECT ldate FROM tbldelivery_receipt WHERE lrefno='OS-1'")->fetchColumn() === '2026-09-01', 'order slip date cascaded');
$assert($pdo->query("SELECT ldate FROM tblinvoice_list WHERE lrefno='INV-1'")->fetchColumn() === '2026-09-01', 'draft invoice date cascaded');
$assert($pdo->query("SELECT ldate FROM tblinvoice_list WHERE lrefno='INV-POSTED'")->fetchColumn() === '2026-09-12', 'posted invoice date left alone');

$pdo->exec("UPDATE tbltransaction SET ldate = '2026-09-12', ltransaction_status = 'posted' WHERE lrefno = 'SO-1'");
SalesDocumentDateCascade::apply($pdo, 1, '2026-08-01', ['sales_order_refno' => 'SO-1']);
$assert($pdo->query("SELECT ldate FROM tbltransaction WHERE lrefno='SO-1'")->fetchColumn() === '2026-09-12', 'posted sales order date left alone');
