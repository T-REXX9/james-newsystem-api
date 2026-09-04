<?php

declare(strict_types=1);

/**
 * Preferred brand HTTP API regression test.
 *
 * Run:
 *   API_BASE_URL=http://127.0.0.1:8081 php api/tests/CustomerPreferredBrandApiTest.php
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$MAIN_ID = 1;

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
        return ['http_code' => 0, 'body' => null, 'error' => $error, 'raw' => ''];
    }

    return [
        'http_code' => $httpCode,
        'body' => json_decode((string) $responseBody, true),
        'raw' => (string) $responseBody,
    ];
}

function assert_true(bool $condition, string $message, int &$passed, int &$failed, array &$errors): void
{
    if ($condition) {
        $passed++;
        echo "  PASS {$message}\n";
        return;
    }
    $failed++;
    $errors[] = $message;
    echo "  FAIL {$message}\n";
}

function assert_eq(mixed $expected, mixed $actual, string $message, int &$passed, int &$failed, array &$errors): void
{
    assert_true(
        $expected === $actual,
        $message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual),
        $passed,
        $failed,
        $errors
    );
}

echo "==========================================================\n";
echo " Customer Preferred Brand API Test\n";
echo " Base URL: {$API_BASE}\n";
echo "==========================================================\n\n";

$list = request('GET', "{$API_BASE}/api/v1/customer-database?main_id={$MAIN_ID}&status=all&page=1&per_page=5");
assert_eq(200, $list['http_code'], 'customer-database list returns 200 (no unknown column error)', $passed, $failed, $errors);
assert_eq(true, $list['body']['ok'] ?? false, 'customer-database list ok=true', $passed, $failed, $errors);
$items = $list['body']['data']['items'] ?? [];
assert_true(is_array($items) && $items !== [], 'customer-database list returns items', $passed, $failed, $errors);
if ($items !== []) {
    assert_true(array_key_exists('preferred_brand', $items[0]), 'list items include preferred_brand key', $passed, $failed, $errors);
}

$sessionId = 'API-PREFBRAND-' . date('YmdHis') . '-' . random_int(1000, 9999);
$create = request('POST', "{$API_BASE}/api/v1/customer-database", [
    'main_id' => $MAIN_ID,
    'user_id' => 1,
    'session_id' => $sessionId,
    'company' => 'API Preferred Brand Co',
    'preferred_brand' => 'Ishinomoto',
    'phone' => '09170001111',
    'mobile' => '09170001111',
    'status' => 1,
    'debt_type' => 'Good',
    'profile_type' => 'Old',
]);
assert_eq(200, $create['http_code'], 'create customer with preferred_brand returns 200', $passed, $failed, $errors);
assert_eq('Ishinomoto', (string) ($create['body']['data']['preferred_brand'] ?? ''), 'create response preferred_brand=Ishinomoto', $passed, $failed, $errors);

$detail = request('GET', "{$API_BASE}/api/v1/customer-database/{$sessionId}?main_id={$MAIN_ID}");
assert_eq(200, $detail['http_code'], 'get customer returns 200', $passed, $failed, $errors);
assert_eq('Ishinomoto', (string) ($detail['body']['data']['preferred_brand'] ?? ''), 'get customer preferred_brand=Ishinomoto', $passed, $failed, $errors);

$patch = request('PATCH', "{$API_BASE}/api/v1/customer-database/{$sessionId}", [
    'main_id' => $MAIN_ID,
    'user_id' => 1,
    'preferred_brand' => 'Others',
]);
assert_eq(200, $patch['http_code'], 'patch preferred_brand returns 200', $passed, $failed, $errors);
assert_eq('Others', (string) ($patch['body']['data']['preferred_brand'] ?? ''), 'patch preferred_brand=Others', $passed, $failed, $errors);

$excel = request('GET', "{$API_BASE}/api/v1/daily-call-monitoring/excel?main_id={$MAIN_ID}&status=all&search=" . rawurlencode('API Preferred Brand Co'));
assert_eq(200, $excel['http_code'], 'daily-call excel returns 200 (no unknown column error)', $passed, $failed, $errors);
$excelPayload = $excel['body']['data'] ?? null;
$excelRows = [];
if (is_array($excelPayload)) {
    if (array_is_list($excelPayload)) {
        $excelRows = $excelPayload;
    } else {
        $excelRows = $excelPayload['rows'] ?? $excelPayload['items'] ?? $excelPayload['customers'] ?? [];
    }
}
$excelMatch = null;
foreach ((array) $excelRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    if ((string) ($row['id'] ?? '') === $sessionId || (string) ($row['shopName'] ?? '') === 'API Preferred Brand Co') {
        $excelMatch = $row;
        break;
    }
}
assert_true($excelMatch !== null, 'daily-call excel includes preferred-brand customer', $passed, $failed, $errors);
if ($excelMatch !== null) {
    assert_true(array_key_exists('preferredBrand', $excelMatch), 'daily-call excel row has preferredBrand key', $passed, $failed, $errors);
    assert_eq('Others', (string) ($excelMatch['preferredBrand'] ?? ''), 'daily-call excel preferredBrand=Others', $passed, $failed, $errors);
}

$agent = request('GET', "{$API_BASE}/api/v1/daily-call-monitoring/agent-snapshot?main_id={$MAIN_ID}&viewer_user_id=1");
assert_eq(200, $agent['http_code'], 'daily-call agent-snapshot returns 200', $passed, $failed, $errors);

// Cleanup best-effort via direct DB-less soft delete endpoint if available
request('DELETE', "{$API_BASE}/api/v1/customer-database/{$sessionId}?main_id={$MAIN_ID}", [
    'main_id' => $MAIN_ID,
    'user_id' => 1,
]);

echo "\n==========================================================\n";
echo " Results: {$passed} passed, {$failed} failed\n";
echo "==========================================================\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    exit(1);
}

echo "\nAll preferred brand API checks passed.\n";
