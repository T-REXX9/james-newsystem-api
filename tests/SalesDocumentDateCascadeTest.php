<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/SalesDocumentDateCascade.php';

use App\Support\SalesDocumentDateCascade;

/**
 * Lightweight smoke test: cascade helper rejects bad dates without throwing.
 * Full DB cascade is covered when integration DB is available.
 */
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE tblinquiry (lmain_id TEXT, lrefno TEXT, ldate TEXT)');
$pdo->exec('CREATE TABLE tbltransaction (lmain_id TEXT, lrefno TEXT, linquiry_refno TEXT, ldate TEXT)');
$pdo->exec('CREATE TABLE tbldelivery_receipt (lmain_id TEXT, lrefno TEXT, lsales_refno TEXT, ldate TEXT)');
$pdo->exec('CREATE TABLE tblinvoice_list (lmain_id TEXT, lrefno TEXT, lsales_refno TEXT, ldate TEXT)');

$pdo->exec("INSERT INTO tblinquiry VALUES ('1', 'INQ-1', '2026-09-12')");
$pdo->exec("INSERT INTO tbltransaction VALUES ('1', 'SO-1', 'INQ-1', '2026-09-12')");
$pdo->exec("INSERT INTO tbldelivery_receipt VALUES ('1', 'OS-1', 'SO-1', '2026-09-12')");
$pdo->exec("INSERT INTO tblinvoice_list VALUES ('1', 'INV-1', 'SO-1', '2026-09-12')");

SalesDocumentDateCascade::apply($pdo, 1, '2026-09-01', ['inquiry_refno' => 'INQ-1']);

$assert = static function (bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo "PASS: {$label}\n";
};

$assert($pdo->query("SELECT ldate FROM tblinquiry WHERE lrefno='INQ-1'")->fetchColumn() === '2026-09-01', 'inquiry date cascaded');
$assert($pdo->query("SELECT ldate FROM tbltransaction WHERE lrefno='SO-1'")->fetchColumn() === '2026-09-01', 'sales order date cascaded');
$assert($pdo->query("SELECT ldate FROM tbldelivery_receipt WHERE lrefno='OS-1'")->fetchColumn() === '2026-09-01', 'order slip date cascaded');
$assert($pdo->query("SELECT ldate FROM tblinvoice_list WHERE lrefno='INV-1'")->fetchColumn() === '2026-09-01', 'invoice date cascaded');
