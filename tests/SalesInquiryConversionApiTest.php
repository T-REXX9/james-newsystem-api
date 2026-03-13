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
    'price_group' => 'VIP2',
    'terms' => 'VIP2',
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
