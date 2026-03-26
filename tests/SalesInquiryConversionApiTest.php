<?php

declare(strict_types=1);

/**
 * Sales Inquiry -> Sales Order conversion regression test.
 *
 * Run:
 *   API_BASE_URL=http://127.0.0.1:8081 php api/tests/SalesInquiryConversionApiTest.php
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$MAIN_ID = 1;
$USER_ID = 1;

$passed = 0;
$failed = 0;
$errors = [];

function request(string $method, string $url, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $responseBody = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    if ($error !== '') {
        return ['http_code' => 0, 'body' => null, 'error' => $error];
    }

    return [
        'http_code' => $httpCode,
        'body' => json_decode((string) $responseBody, true),
        'raw' => $responseBody,
    ];
}

function assert_true(bool $condition, string $message, array &$passed, array &$failed, array &$errors): void
{
    if ($condition) {
        $passed[0]++;
        echo "  PASS {$message}\n";
        return;
    }

    $failed[0]++;
    $errors[] = $message;
    echo "  FAIL {$message}\n";
}

function assert_eq(mixed $expected, mixed $actual, string $message, array &$passed, array &$failed, array &$errors): void
{
    assert_true($expected === $actual, $message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual), $passed, $failed, $errors);
}

function find_item_by_description(array $items, string $description): ?array
{
    foreach ($items as $item) {
        if ((string) ($item['description'] ?? '') === $description) {
            return $item;
        }
    }

    return null;
}

$p = [&$passed];
$f = [&$failed];
$e = &$errors;

echo "==========================================================\n";
echo " Sales Inquiry Conversion API Test\n";
echo " Base URL: {$API_BASE}\n";
echo "==========================================================\n\n";

$health = request('GET', "{$API_BASE}/api/v1/health");
assert_eq(200, $health['http_code'], 'Health endpoint returns 200', $p, $f, $e);
assert_eq(true, $health['body']['ok'] ?? false, 'Health response ok=true', $p, $f, $e);
if (($health['http_code'] ?? 0) !== 200 || ($health['body']['ok'] ?? false) !== true) {
    exit(1);
}

$contacts = request('GET', "{$API_BASE}/api/v1/customer-database?main_id={$MAIN_ID}&status=all&page=1&per_page=1");
assert_eq(200, $contacts['http_code'], 'Customer database returns 200', $p, $f, $e);
$contactId = (string) ($contacts['body']['data']['items'][0]['session_id'] ?? '');
assert_true($contactId !== '', 'Found a contact id for test', $p, $f, $e);

$products = request('GET', "{$API_BASE}/api/v1/products?main_id={$MAIN_ID}&status=all&page=1&per_page=2");
assert_eq(200, $products['http_code'], 'Products list returns 200', $p, $f, $e);
$approvedItemRefno = (string) ($products['body']['data']['items'][0]['product_session'] ?? $products['body']['data']['items'][0]['id'] ?? '');
$pendingItemRefno = (string) ($products['body']['data']['items'][1]['product_session'] ?? $products['body']['data']['items'][1]['id'] ?? '');
assert_true($approvedItemRefno !== '' && $pendingItemRefno !== '', 'Found two product ids for test', $p, $f, $e);

$seed = (string) time();
$create = request('POST', "{$API_BASE}/api/v1/sales-inquiries", [
    'main_id' => $MAIN_ID,
    'user_id' => $USER_ID,
    'contact_id' => $contactId,
    'sales_date' => date('Y-m-d'),
    'sales_person' => 'API Test',
    'delivery_address' => 'Integration Address',
    'reference_no' => "IT-INQ-{$seed}",
    'customer_reference' => "IT-INQ-{$seed}",
            'price_group' => 'gold',
            'terms' => 'gold',
    'status' => 'Submitted',
    'items' => [
        [
            'item_id' => $approvedItemRefno,
            'item_refno' => $approvedItemRefno,
            'description' => 'approved item',
            'qty' => 1,
            'unit_price' => 10.5,
            'remark' => 'OnStock',
            'approved' => 1,
        ],
        [
            'item_id' => $pendingItemRefno,
            'item_refno' => $pendingItemRefno,
            'description' => 'pending item',
            'qty' => 2,
            'unit_price' => 20,
            'remark' => 'OnStock',
            'approved' => 0,
        ],
    ],
]);
assert_eq(200, $create['http_code'], 'Create inquiry returns 200', $p, $f, $e);
$inquiryRefno = (string) ($create['body']['data']['inquiry_refno'] ?? '');
assert_true($inquiryRefno !== '', 'Created inquiry has inquiry_refno', $p, $f, $e);

$convert = request('POST', "{$API_BASE}/api/v1/sales-inquiries/{$inquiryRefno}/actions/convert-to-order", [
    'main_id' => $MAIN_ID,
    'user_id' => $USER_ID,
]);
assert_eq(200, $convert['http_code'], 'Convert inquiry returns 200', $p, $f, $e);
assert_eq(true, $convert['body']['ok'] ?? false, 'Convert response ok=true', $p, $f, $e);
$convertedItems = $convert['body']['data']['items'] ?? [];
assert_eq(1, count($convertedItems), 'Only approved items are converted', $p, $f, $e);
assert_eq($approvedItemRefno, (string) ($convertedItems[0]['item_refno'] ?? ''), 'Converted item is the approved product', $p, $f, $e);
$salesRefno = (string) ($convert['body']['data']['order']['sales_refno'] ?? '');
assert_true($salesRefno !== '', 'Converted inquiry returns a sales order refno', $p, $f, $e);

$updateInquiry = request('PATCH', "{$API_BASE}/api/v1/sales-inquiries/{$inquiryRefno}", [
    'main_id' => $MAIN_ID,
    'sales_person' => 'Legacy Sync Header',
    'delivery_address' => 'Legacy Sync Address',
    'reference_no' => "SYNC-REF-{$seed}",
    'customer_reference' => "SYNC-CUST-{$seed}",
    'terms' => 'net30',
]);
assert_eq(200, $updateInquiry['http_code'], 'Update inquiry header returns 200', $p, $f, $e);

$salesAfterHeader = request('GET', "{$API_BASE}/api/v1/sales-orders/{$salesRefno}?main_id={$MAIN_ID}");
assert_eq(200, $salesAfterHeader['http_code'], 'Sales order fetch after header sync returns 200', $p, $f, $e);
assert_eq('Legacy Sync Header', (string) ($salesAfterHeader['body']['data']['order']['sales_person'] ?? ''), 'Linked sales order sales person is synced from inquiry', $p, $f, $e);
assert_eq('Legacy Sync Address', (string) ($salesAfterHeader['body']['data']['order']['delivery_address'] ?? ''), 'Linked sales order address is synced from inquiry', $p, $f, $e);
assert_eq("SYNC-REF-{$seed}", (string) ($salesAfterHeader['body']['data']['order']['reference_no'] ?? ''), 'Linked sales order reference no is synced from inquiry', $p, $f, $e);
assert_eq("SYNC-CUST-{$seed}", (string) ($salesAfterHeader['body']['data']['order']['customer_reference'] ?? ''), 'Linked sales order customer reference is synced from inquiry', $p, $f, $e);

$addApproved = request('POST', "{$API_BASE}/api/v1/sales-inquiries/{$inquiryRefno}/items", [
    'main_id' => $MAIN_ID,
    'item_id' => $pendingItemRefno,
    'item_refno' => $pendingItemRefno,
    'description' => 'sync approved item',
    'qty' => 3,
    'unit_price' => 33.25,
    'remark' => 'OnStock',
    'approved' => 1,
]);
assert_eq(200, $addApproved['http_code'], 'Add approved inquiry item returns 200', $p, $f, $e);
$approvedSyncItemId = (int) ($addApproved['body']['data']['id'] ?? 0);
assert_true($approvedSyncItemId > 0, 'Added approved inquiry item returns an id', $p, $f, $e);

$addNotListed = request('POST', "{$API_BASE}/api/v1/sales-inquiries/{$inquiryRefno}/items", [
    'main_id' => $MAIN_ID,
    'item_id' => $pendingItemRefno,
    'item_refno' => $pendingItemRefno,
    'description' => 'sync blocked item',
    'qty' => 1,
    'unit_price' => 99,
    'remark' => 'NotListed',
    'approved' => 1,
]);
assert_eq(200, $addNotListed['http_code'], 'Add not listed inquiry item returns 200', $p, $f, $e);

$salesAfterAdd = request('GET', "{$API_BASE}/api/v1/sales-orders/{$salesRefno}?main_id={$MAIN_ID}");
assert_eq(200, $salesAfterAdd['http_code'], 'Sales order fetch after inquiry item add returns 200', $p, $f, $e);
$itemsAfterAdd = $salesAfterAdd['body']['data']['items'] ?? [];
assert_true(find_item_by_description($itemsAfterAdd, 'sync approved item') !== null, 'Approved inquiry item is synced into sales order', $p, $f, $e);
assert_true(find_item_by_description($itemsAfterAdd, 'sync blocked item') === null, 'NotListed inquiry item is not synced into sales order', $p, $f, $e);

$updateApproved = request('PATCH', "{$API_BASE}/api/v1/sales-inquiry-items/{$approvedSyncItemId}", [
    'main_id' => $MAIN_ID,
    'description' => 'sync approved item updated',
    'qty' => 7,
    'unit_price' => 44.5,
]);
assert_eq(200, $updateApproved['http_code'], 'Update inquiry item returns 200', $p, $f, $e);

$salesAfterItemUpdate = request('GET', "{$API_BASE}/api/v1/sales-orders/{$salesRefno}?main_id={$MAIN_ID}");
assert_eq(200, $salesAfterItemUpdate['http_code'], 'Sales order fetch after inquiry item update returns 200', $p, $f, $e);
$updatedSyncedItem = find_item_by_description($salesAfterItemUpdate['body']['data']['items'] ?? [], 'sync approved item updated');
assert_true($updatedSyncedItem !== null, 'Updated inquiry item description is synced into sales order', $p, $f, $e);
assert_eq(7, (int) ($updatedSyncedItem['qty'] ?? 0), 'Updated inquiry item qty is synced into sales order', $p, $f, $e);
assert_eq(44.5, (float) ($updatedSyncedItem['unit_price'] ?? 0), 'Updated inquiry item price is synced into sales order', $p, $f, $e);

$deleteApproved = request('DELETE', "{$API_BASE}/api/v1/sales-inquiry-items/{$approvedSyncItemId}?main_id={$MAIN_ID}");
assert_eq(200, $deleteApproved['http_code'], 'Delete inquiry item returns 200', $p, $f, $e);

$salesAfterDelete = request('GET', "{$API_BASE}/api/v1/sales-orders/{$salesRefno}?main_id={$MAIN_ID}");
assert_eq(200, $salesAfterDelete['http_code'], 'Sales order fetch after inquiry item delete returns 200', $p, $f, $e);
assert_true(find_item_by_description($salesAfterDelete['body']['data']['items'] ?? [], 'sync approved item updated') === null, 'Deleted inquiry item is removed from sales order', $p, $f, $e);

$convertAgain = request('POST', "{$API_BASE}/api/v1/sales-inquiries/{$inquiryRefno}/actions/convert-to-order", [
    'main_id' => $MAIN_ID,
    'user_id' => $USER_ID,
]);
assert_eq(200, $convertAgain['http_code'], 'Repeat convert inquiry returns 200', $p, $f, $e);
assert_eq(
    (string) ($convert['body']['data']['order']['sales_refno'] ?? ''),
    (string) ($convertAgain['body']['data']['order']['sales_refno'] ?? ''),
    'Repeat conversion reuses the same sales order',
    $p,
    $f,
    $e
);

$approveSales = request('POST', "{$API_BASE}/api/v1/sales-orders/{$salesRefno}/actions/approve", [
    'main_id' => $MAIN_ID,
    'user_id' => $USER_ID,
]);
assert_eq(200, $approveSales['http_code'], 'Approve sales order returns 200', $p, $f, $e);

$convertOrderSlip = request('POST', "{$API_BASE}/api/v1/sales-orders/{$salesRefno}/convert/order-slip", [
    'main_id' => $MAIN_ID,
    'user_id' => $USER_ID,
]);
assert_eq(200, $convertOrderSlip['http_code'], 'Convert sales order to order slip returns 200', $p, $f, $e);

$salesAfterOrderSlip = request('GET', "{$API_BASE}/api/v1/sales-orders/{$salesRefno}?main_id={$MAIN_ID}");
assert_eq(200, $salesAfterOrderSlip['http_code'], 'Sales order fetch after order slip conversion returns 200', $p, $f, $e);
$lockedOrderSlipCount = count($salesAfterOrderSlip['body']['data']['items'] ?? []);
$lockedOrderSlipRef = (string) ($salesAfterOrderSlip['body']['data']['order']['order_slip_refno'] ?? '');
assert_true($lockedOrderSlipRef !== '', 'Sales order is linked to an order slip', $p, $f, $e);

$editAfterOrderSlip = request('PATCH', "{$API_BASE}/api/v1/sales-inquiries/{$inquiryRefno}", [
    'main_id' => $MAIN_ID,
    'sales_person' => 'Should Not Sync Order Slip',
]);
assert_eq(200, $editAfterOrderSlip['http_code'], 'Inquiry update after order slip returns 200', $p, $f, $e);

$salesAfterOrderSlipEdit = request('GET', "{$API_BASE}/api/v1/sales-orders/{$salesRefno}?main_id={$MAIN_ID}");
assert_eq(200, $salesAfterOrderSlipEdit['http_code'], 'Sales order fetch after locked order slip edit returns 200', $p, $f, $e);
assert_eq($lockedOrderSlipCount, count($salesAfterOrderSlipEdit['body']['data']['items'] ?? []), 'Sales order items stop syncing once order slip exists', $p, $f, $e);
assert_eq('Legacy Sync Header', (string) ($salesAfterOrderSlipEdit['body']['data']['order']['sales_person'] ?? ''), 'Sales order header stops syncing once order slip exists', $p, $f, $e);

$invoiceSeed = $seed . '-inv';
$createInvoiceInquiry = request('POST', "{$API_BASE}/api/v1/sales-inquiries", [
    'main_id' => $MAIN_ID,
    'user_id' => $USER_ID,
    'contact_id' => $contactId,
    'sales_date' => date('Y-m-d'),
    'sales_person' => 'Invoice Guard',
    'delivery_address' => 'Invoice Guard Address',
    'reference_no' => "IT-INQ-{$invoiceSeed}",
    'customer_reference' => "IT-INQ-{$invoiceSeed}",
    'price_group' => 'gold',
    'terms' => 'gold',
    'status' => 'Submitted',
    'items' => [
        [
            'item_id' => $approvedItemRefno,
            'item_refno' => $approvedItemRefno,
            'description' => 'invoice sync source item',
            'qty' => 1,
            'unit_price' => 15.75,
            'remark' => 'OnStock',
            'approved' => 1,
        ],
    ],
]);
assert_eq(200, $createInvoiceInquiry['http_code'], 'Create invoice-guard inquiry returns 200', $p, $f, $e);
$invoiceInquiryRefno = (string) ($createInvoiceInquiry['body']['data']['inquiry_refno'] ?? '');
assert_true($invoiceInquiryRefno !== '', 'Invoice-guard inquiry has inquiry_refno', $p, $f, $e);

$convertInvoiceInquiry = request('POST', "{$API_BASE}/api/v1/sales-inquiries/{$invoiceInquiryRefno}/actions/convert-to-order", [
    'main_id' => $MAIN_ID,
    'user_id' => $USER_ID,
]);
assert_eq(200, $convertInvoiceInquiry['http_code'], 'Convert invoice-guard inquiry returns 200', $p, $f, $e);
$invoiceSalesRefno = (string) ($convertInvoiceInquiry['body']['data']['order']['sales_refno'] ?? '');
assert_true($invoiceSalesRefno !== '', 'Invoice-guard conversion returns sales refno', $p, $f, $e);

$approveInvoiceSales = request('POST', "{$API_BASE}/api/v1/sales-orders/{$invoiceSalesRefno}/actions/approve", [
    'main_id' => $MAIN_ID,
    'user_id' => $USER_ID,
]);
assert_eq(200, $approveInvoiceSales['http_code'], 'Approve invoice-guard sales order returns 200', $p, $f, $e);

$convertInvoice = request('POST', "{$API_BASE}/api/v1/sales-orders/{$invoiceSalesRefno}/convert/invoice", [
    'main_id' => $MAIN_ID,
    'user_id' => $USER_ID,
]);
assert_eq(200, $convertInvoice['http_code'], 'Convert sales order to invoice returns 200', $p, $f, $e);

$salesAfterInvoice = request('GET', "{$API_BASE}/api/v1/sales-orders/{$invoiceSalesRefno}?main_id={$MAIN_ID}");
assert_eq(200, $salesAfterInvoice['http_code'], 'Sales order fetch after invoice conversion returns 200', $p, $f, $e);
$lockedInvoiceCount = count($salesAfterInvoice['body']['data']['items'] ?? []);
$lockedInvoiceRef = (string) ($salesAfterInvoice['body']['data']['order']['invoice_refno'] ?? '');
assert_true($lockedInvoiceRef !== '', 'Sales order is linked to an invoice', $p, $f, $e);

$editAfterInvoice = request('PATCH', "{$API_BASE}/api/v1/sales-inquiries/{$invoiceInquiryRefno}", [
    'main_id' => $MAIN_ID,
    'sales_person' => 'Should Not Sync Invoice',
]);
assert_eq(200, $editAfterInvoice['http_code'], 'Inquiry update after invoice returns 200', $p, $f, $e);

$salesAfterInvoiceEdit = request('GET', "{$API_BASE}/api/v1/sales-orders/{$invoiceSalesRefno}?main_id={$MAIN_ID}");
assert_eq(200, $salesAfterInvoiceEdit['http_code'], 'Sales order fetch after locked invoice edit returns 200', $p, $f, $e);
assert_eq($lockedInvoiceCount, count($salesAfterInvoiceEdit['body']['data']['items'] ?? []), 'Sales order items stop syncing once invoice exists', $p, $f, $e);
assert_eq('Invoice Guard', (string) ($salesAfterInvoiceEdit['body']['data']['order']['sales_person'] ?? ''), 'Sales order header stops syncing once invoice exists', $p, $f, $e);

echo "\n==========================================================\n";
echo " Passed: {$passed}\n";
echo " Failed: {$failed}\n";
echo "==========================================================\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    exit(1);
}
