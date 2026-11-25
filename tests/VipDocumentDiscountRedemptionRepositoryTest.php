<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\SalesInquiryRepository;
use App\Repositories\VipDocumentDiscountRepository;

$db = new Database(new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'test_db', 'test', 'test'));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$property = (new ReflectionClass($db))->getProperty('pdo');
$property->setValue($db, $pdo);

$pdo->exec('CREATE TABLE tblvip_document_discount (
    lid INTEGER PRIMARY KEY,
    lmain_id INTEGER,
    ldocument_type TEXT,
    ldocument_refno TEXT,
    lcustomerid TEXT,
    lsales_date TEXT,
    lapplied INTEGER,
    ltier TEXT
)');
$pdo->exec('CREATE TABLE tbldelivery_receipt (
    lmain_id INTEGER,
    lrefno TEXT,
    lcustomerid TEXT,
    ldate TEXT,
    lcancel INTEGER,
    lstatus TEXT
)');
$pdo->exec('CREATE TABLE tblinvoice_list (
    lmain_id INTEGER,
    lrefno TEXT,
    lcustomerid TEXT,
    ldate TEXT,
    lcancel TEXT,
    lcancel_invoice INTEGER,
    lstatus TEXT
)');
$pdo->exec('CREATE TABLE tblnumber_generator (
    lid INTEGER PRIMARY KEY,
    ltransaction_type TEXT,
    lmax_no INTEGER
)');
$pdo->exec('CREATE TABLE tblledger (
    lmainid INTEGER,
    lcustomerid TEXT,
    ltype TEXT,
    lref_name TEXT,
    ldatetime TEXT,
    ldebit REAL,
    lrefno TEXT
)');
$pdo->exec('CREATE TABLE customer_merge_redirects (
    main_id INTEGER,
    old_customer_session_id TEXT,
    surviving_customer_session_id TEXT
)');
$pdo->exec("INSERT INTO tblvip_document_discount VALUES
    (1, 7, 'order_slip', 'os-posted', 'customer-1', '2026-09-30', 1, 'silver'),
    (2, 7, 'order_slip', 'os-cancelled', 'customer-1', '2026-08-30', 1, 'silver'),
    (3, 7, 'invoice', 'inv-posted', 'customer-1', '2026-10-02', 1, 'silver'),
    (4, 7, 'invoice', 'inv-cancelled', 'customer-1', '2026-07-02', 1, 'silver'),
    (5, 7, 'sales_inquiry', 'inquiry-pending', 'customer-1', '2026-11-01', 1, 'silver'),
    (6, 7, 'sales_order', 'so-unposted', 'customer-1', '2026-12-01', 1, 'silver'),
    (7, 8, 'invoice', 'inv-other-tenant', 'customer-1', '2026-06-01', 1, 'silver')");
$pdo->exec("INSERT INTO tbldelivery_receipt VALUES
    (7, 'os-posted', 'customer-1', '2026-09-30', 0, 'Posted'),
    (7, 'os-cancelled', 'customer-1', '2026-08-30', 1, 'Cancelled')");
$pdo->exec("INSERT INTO tblinvoice_list VALUES
    (7, 'inv-posted', 'customer-1', '2026-10-02', '0', 0, 'Posted'),
    (7, 'inv-cancelled', 'customer-1', '2026-07-02', '0', 1, 'Posted'),
    (7, 'inv-gold-legacy', 'customer-1', '2026-09-18', '0', 0, 'Posted')");

$insertPostedInvoiceSpend = static function (string $customerId, float $spend, string $invoiceRefno) use ($pdo): void {
    $ledger = $pdo->prepare(
        "INSERT INTO tblledger (lmainid, lcustomerid, ltype, lref_name, ldatetime, ldebit, lrefno)
         VALUES (7, :customer_id, 'Debit', 'Invoice', '2026-08-15 09:00:00', :spend, :refno)"
    );
    $ledger->execute(['customer_id' => $customerId, 'spend' => $spend, 'refno' => $invoiceRefno]);
    $invoice = $pdo->prepare(
        "INSERT INTO tblinvoice_list VALUES (7, :refno, :customer_id, '2026-08-15', '0', 0, 'Posted')"
    );
    $invoice->execute(['refno' => $invoiceRefno, 'customer_id' => $customerId]);
};
$insertPostedInvoiceSpend('customer-1', 30000, 'inv-gold-august');
$insertPostedInvoiceSpend('customer-silver-redeemed', 15000, 'inv-silver-redeemed-august');
$insertPostedInvoiceSpend('customer-silver-unused', 15000, 'inv-silver-unused-august');
$insertPostedInvoiceSpend('customer-silver-legacy', 15000, 'inv-silver-legacy-august');
$insertPostedInvoiceSpend('customer-silver-legacy-order-slip', 15000, 'inv-silver-legacy-order-slip-august');
$insertPostedInvoiceSpend('customer-silver-merged', 15000, 'inv-silver-merged-august');
$insertPostedInvoiceSpend('old-customer-silver-merged', 15000, 'inv-silver-legacy-merged-august');
$insertPostedInvoiceSpend('customer-gold-legacy', 30000, 'inv-gold-legacy-august');
$pdo->exec("INSERT INTO tblinvoice_list VALUES
    (7, 'inv-silver-legacy-september', 'customer-silver-legacy', '2026-09-20', '0', 0, 'Posted'),
    (7, 'inv-gold-legacy-september', 'customer-gold-legacy', '2026-09-20', '0', 0, 'Posted')");
$pdo->exec("INSERT INTO tbldelivery_receipt VALUES
    (7, 'os-silver-legacy-september', 'customer-silver-legacy-order-slip', '2026-09-21', 0, 'Posted')");
$pdo->exec("INSERT INTO tblvip_document_discount VALUES
    (8, 7, 'order_slip', 'os-silver-redeemed', 'customer-silver-redeemed', '2026-09-10', 1, 'silver'),
    (9, 7, 'sales_inquiry', 'inquiry-silver-pending', 'customer-silver-unused', '2026-09-12', 1, 'silver'),
    (10, 7, 'order_slip', 'os-silver-merged-september', 'customer-silver-merged', '2026-09-10', 1, 'silver')");
$pdo->exec("INSERT INTO tbldelivery_receipt VALUES
    (7, 'os-silver-redeemed', 'customer-silver-redeemed', '2026-09-10', 0, 'Posted'),
    (7, 'os-silver-merged-september', 'old-customer-id', '2026-09-10', 0, 'Posted'),
    (7, 'os-silver-legacy-merged-september', 'old-customer-silver-merged', '2026-09-11', 0, 'Posted')");
$pdo->exec("INSERT INTO customer_merge_redirects VALUES
    (7, 'old-customer-silver-merged', 'customer-silver-merged-legacy')");

$vipRepository = new VipDocumentDiscountRepository($db);
$months = $vipRepository->redeemedSilverBenefitMonths(7, 'customer-1');
if ($months !== ['2026-09', '2026-10']) {
    throw new RuntimeException('Unexpected redeemed months: ' . json_encode($months));
}

$inquiryRepository = new SalesInquiryRepository($db);
$resolveForCustomer = static fn(string $customerId): array => $inquiryRepository->resolveVipPayloadForInquiry(7, 'pending-inquiry', [
    'contact_id' => $customerId,
    'sales_date' => '2026-09-15',
    'grand_total' => 1000,
]);
$goldPayload = $resolveForCustomer('customer-1');
if (($goldPayload['vip_tier'] ?? '') !== 'gold' || (int) ($goldPayload['vip_applied'] ?? 0) !== 1) {
    throw new RuntimeException('VIP Gold was incorrectly limited after Silver redemption: ' . json_encode($goldPayload));
}
$redeemedSilverPayload = $resolveForCustomer('customer-silver-redeemed');
if (($redeemedSilverPayload['vip_tier'] ?? '') !== 'silver' || (int) ($redeemedSilverPayload['vip_applied'] ?? 0) !== 0) {
    throw new RuntimeException('Already-redeemed VIP Silver month remained eligible: ' . json_encode($redeemedSilverPayload));
}
$unusedSilverPayload = $resolveForCustomer('customer-silver-unused');
if (($unusedSilverPayload['vip_tier'] ?? '') !== 'silver' || (int) ($unusedSilverPayload['vip_applied'] ?? 0) !== 1) {
    throw new RuntimeException('Unused VIP Silver month was not eligible: ' . json_encode($unusedSilverPayload));
}
$legacySilverPayload = $resolveForCustomer('customer-silver-legacy');
if (($legacySilverPayload['vip_tier'] ?? '') !== 'silver' || (int) ($legacySilverPayload['vip_applied'] ?? 0) !== 0) {
    throw new RuntimeException('Legacy posted Silver invoice without VIP metadata was not recognized: ' . json_encode($legacySilverPayload));
}
$legacySilverOrderSlipPayload = $resolveForCustomer('customer-silver-legacy-order-slip');
if (($legacySilverOrderSlipPayload['vip_tier'] ?? '') !== 'silver' || (int) ($legacySilverOrderSlipPayload['vip_applied'] ?? 0) !== 0) {
    throw new RuntimeException('Legacy posted Silver Order Slip without VIP metadata was not recognized: ' . json_encode($legacySilverOrderSlipPayload));
}
$pendingSilverPayload = $resolveForCustomer('customer-silver-unused');
if (($pendingSilverPayload['vip_tier'] ?? '') !== 'silver' || (int) ($pendingSilverPayload['vip_applied'] ?? 0) !== 1) {
    throw new RuntimeException('Pending Silver inquiry incorrectly consumed the monthly benefit: ' . json_encode($pendingSilverPayload));
}
$legacyGoldPayload = $resolveForCustomer('customer-gold-legacy');
if (($legacyGoldPayload['vip_tier'] ?? '') !== 'gold' || (int) ($legacyGoldPayload['vip_applied'] ?? 0) !== 1) {
    throw new RuntimeException('Legacy VIP Gold invoice incorrectly consumed or limited Gold: ' . json_encode($legacyGoldPayload));
}
$mergedCustomerPayload = $resolveForCustomer('customer-silver-merged');
if (($mergedCustomerPayload['vip_tier'] ?? '') !== 'silver' || (int) ($mergedCustomerPayload['vip_applied'] ?? 0) !== 0) {
    throw new RuntimeException('Posted VIP Silver Order Slip with a historical customer ID did not redeem the current customer month: ' . json_encode($mergedCustomerPayload));
}
$legacyMergedMonths = $vipRepository->redeemedSilverBenefitMonths(7, 'customer-silver-merged-legacy');
if (!in_array('2026-09', $legacyMergedMonths, true)) {
    throw new RuntimeException('Legacy posted VIP Silver Order Slip without metadata did not redeem after customer merge: ' . json_encode($legacyMergedMonths));
}

echo "PASS: only uncancelled Order Slips and Invoices redeem VIP Silver\n";
echo "PASS: VIP Gold remains unlimited when Silver redemption exists\n";
echo "PASS: pending inquiries do not consume an unused VIP Silver benefit\n";
echo "PASS: legacy posted Silver invoices without VIP metadata redeem the month\n";
echo "PASS: legacy posted Silver Order Slips without VIP metadata redeem the month\n";
echo "PASS: pending VIP Silver inquiries do not consume the monthly benefit\n";
echo "PASS: legacy VIP Gold invoices remain unlimited\n";
echo "PASS: posted VIP Silver documents remain linked after customer ID changes\n";
echo "PASS: legacy Silver redemption follows customer merge history without VIP metadata\n";
