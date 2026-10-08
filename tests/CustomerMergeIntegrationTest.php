<?php

declare(strict_types=1);

/**
 * Live MySQL verification for the Phase 2 customer merge workflow.
 *
 * Run with: php tests/CustomerMergeIntegrationTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Services\CustomerMergeService;

$db = new Database(app_config());
$pdo = $db->pdo();
$service = new CustomerMergeService($db);
$mainId = 1;
$stamp = date('YmdHis') . '-' . random_int(1000, 9999);
$survivor = "MERGE-S-{$stamp}";
$duplicate = "MERGE-D-{$stamp}";
$invoiceRef = "MERGE-I-{$stamp}";
$ledgerRef = "MERGE-L-{$stamp}";
$transactionRef = "MERGE-T-{$stamp}";
$collectionRef = "MERGE-C-{$stamp}";
$idempotencyKey = "merge-integration-{$stamp}";
$mergeId = null;

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: {$label}");
    }
    echo "PASS: {$label}\n";
};

$count = static function (string $table, string $column, string $value) use ($pdo): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :value");
    $stmt->execute(['value' => $value]);
    return (int) $stmt->fetchColumn();
};

try {
    $insertCustomer = $pdo->prepare(
        'INSERT INTO tblpatient
            (lmain_id, lsessionid, lcompany, lstatus, lprofile_type, lverification,
             lvat_type, lterms, lprice_group, lsales_person, ldeleted)
         VALUES (:main_id, :session_id, :company, 1, \'Old\', \'Verified\',
                 :vat_type, :terms, :price_group, \'1\', 0)'
    );
    foreach ([[$survivor, "Survivor {$stamp}"], [$duplicate, "Duplicate {$stamp}"]] as [$sessionId, $company]) {
        $insertCustomer->execute([
            'main_id' => $mainId,
            'session_id' => $sessionId,
            'company' => $company,
            'vat_type' => 'VAT',
            'terms' => '30 days',
            'price_group' => 'Retail',
        ]);
    }

    $pdo->prepare(
        'INSERT INTO tblinvoice_list (linvoice_no, lmain_id, lrefno, lstatus, lcustomerid)
         VALUES (:invoice_no, :main_id, :refno, \'Posted\', :customer_id)'
    )->execute(['invoice_no' => $invoiceRef, 'refno' => $invoiceRef, 'main_id' => (string) $mainId, 'customer_id' => $duplicate]);
    $pdo->prepare(
        'INSERT INTO tblinvoice_itemrec (lrefno, lprice, lqty, luser, lpatient_id, linvoice_refno)
         VALUES (:refno, 25.50, 2, \'merge-test\', :patient_id, :invoice_refno)'
    )->execute(['refno' => $invoiceRef, 'patient_id' => $duplicate, 'invoice_refno' => $invoiceRef]);
    $pdo->prepare(
        'INSERT INTO tblledger (lcustomerid, lrefno, lmainid, ldebit, lcredit, ltype)
         VALUES (:customer_id, :refno, :main_id, 51.00, 0.00, \'Merge test\')'
    )->execute(['customer_id' => $duplicate, 'refno' => $ledgerRef, 'main_id' => (string) $mainId]);
    $pdo->prepare(
        'INSERT INTO tbltransaction (lcustomerid, lmain_id, lrefno, lpayment_status)
         VALUES (:customer_id, :main_id, :refno, \'Unpaid\')'
    )->execute(['customer_id' => $duplicate, 'main_id' => (string) $mainId, 'refno' => $transactionRef]);
    $pdo->prepare(
        'INSERT INTO tbltransaction_item (lrefno, luser, lpatient_id, lprice, lqty)
         VALUES (:refno, \'merge-test\', :patient_id, 51.00, 1)'
    )->execute(['refno' => $transactionRef, 'patient_id' => $duplicate]);
    $pdo->prepare(
        'INSERT INTO tblcollection_item (lcustomer, lrefno, lamt, lmainid)
         VALUES (:customer_id, :refno, \'51.00\', :main_id)'
    )->execute(['customer_id' => $duplicate, 'refno' => $collectionRef, 'main_id' => (string) $mainId]);

    $preview = $service->preview($mainId, $survivor, $duplicate, "Merged {$stamp}", 'Integration test merge', 1, $idempotencyKey);
    $assert($preview['executable'] === true, 'live preview is executable');
    $assert(($preview['financial_totals']['invoice_value'] ?? null) === 51.0, 'preview includes invoice value');
    $assert(($preview['financial_totals']['ledger_debit'] ?? null) === 51.0, 'preview includes ledger debit');

    $result = $service->execute($mainId, $survivor, $duplicate, "Merged {$stamp}", 'Integration test merge', 'MERGE CUSTOMER RECORDS', $idempotencyKey, 1);
    $mergeId = (int) $result['merge_id'];
    $assert($result['status'] === 'completed', 'live merge completes');

    $customer = $pdo->prepare('SELECT lcompany, ldeleted FROM tblpatient WHERE lmain_id = :main_id AND lsessionid = :session_id');
    $customer->execute(['main_id' => $mainId, 'session_id' => $survivor]);
    $survivorRow = $customer->fetch(PDO::FETCH_ASSOC);
    $assert(($survivorRow['lcompany'] ?? '') === "Merged {$stamp}", 'survivor keeps the selected final company name');
    $customer->execute(['main_id' => $mainId, 'session_id' => $duplicate]);
    $duplicateRow = $customer->fetch(PDO::FETCH_ASSOC);
    $assert((int) ($duplicateRow['ldeleted'] ?? 0) === 1, 'duplicate is retired');
    $redirect = $pdo->prepare('SELECT surviving_customer_session_id FROM customer_merge_redirects WHERE main_id = :main_id AND old_customer_session_id = :session_id');
    $redirect->execute(['main_id' => $mainId, 'session_id' => $duplicate]);
    $assert((string) $redirect->fetchColumn() === $survivor, 'duplicate points to survivor');

    foreach ([
        ['tblinvoice_list', 'lcustomerid'],
        ['tblinvoice_itemrec', 'lpatient_id'],
        ['tblledger', 'lcustomerid'],
        ['tbltransaction', 'lcustomerid'],
        ['tbltransaction_item', 'lpatient_id'],
        ['tblcollection_item', 'lcustomer'],
    ] as [$table, $column]) {
        $assert($count($table, $column, $duplicate) === 0, "{$table}.{$column} has no duplicate reference");
    }

    $redirect = $pdo->prepare('SELECT surviving_customer_session_id FROM customer_merge_redirects WHERE main_id = :main_id AND old_customer_session_id = :old_id');
    $redirect->execute(['main_id' => $mainId, 'old_id' => $duplicate]);
    $assert($redirect->fetchColumn() === $survivor, 'redirect points to survivor');

    $audit = $pdo->prepare("SELECT COUNT(*) FROM tblaudit_trail WHERE lmain_id = :main_id AND lrefno = :refno AND laction = 'Merge'");
    $audit->execute(['main_id' => $mainId, 'refno' => 'merge:' . $mergeId]);
    $assert((int) $audit->fetchColumn() === 1, 'merge audit is persisted');

    $retry = $service->execute($mainId, $survivor, $duplicate, "Merged {$stamp}", 'Integration test merge', 'MERGE CUSTOMER RECORDS', $idempotencyKey, 1);
    $assert((int) $retry['merge_id'] === $mergeId && $retry['status'] === 'completed', 'completed merge is idempotent');
} finally {
    if ($mergeId !== null) {
        $pdo->prepare('DELETE FROM tblaudit_trail WHERE lmain_id = :main_id AND lrefno = :refno')->execute(['main_id' => $mainId, 'refno' => 'merge:' . $mergeId]);
        $pdo->prepare('DELETE FROM customer_merge_transfer_log WHERE merge_id = :merge_id')->execute(['merge_id' => $mergeId]);
        $pdo->prepare('DELETE FROM customer_merge_redirects WHERE merge_id = :merge_id')->execute(['merge_id' => $mergeId]);
        $pdo->prepare('DELETE FROM customer_merge_requests WHERE id = :merge_id')->execute(['merge_id' => $mergeId]);
    } else {
        $pdo->prepare('DELETE FROM customer_merge_requests WHERE main_id = :main_id AND idempotency_key = :key')->execute(['main_id' => $mainId, 'key' => $idempotencyKey]);
    }
    foreach ([$invoiceRef, $ledgerRef, $transactionRef, $collectionRef] as $refno) {
        foreach (['tblcollection_item', 'tbltransaction_item', 'tbltransaction', 'tblledger', 'tblinvoice_itemrec', 'tblinvoice_list'] as $table) {
            $pdo->prepare("DELETE FROM `{$table}` WHERE lrefno = :refno")->execute(['refno' => $refno]);
        }
    }
    $pdo->prepare('DELETE FROM tblpatient WHERE lmain_id = :main_id AND lsessionid IN (:survivor, :duplicate)')->execute(['main_id' => $mainId, 'survivor' => $survivor, 'duplicate' => $duplicate]);
}

echo "All live customer merge checks passed.\n";
