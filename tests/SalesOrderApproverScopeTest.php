<?php

declare(strict_types=1);

/**
 * Verifies that Sales Order approval checks do not fall back to Collection approvers.
 *
 * Run:
 *   API_BASE_URL=http://127.0.0.1:8081 php api/tests/SalesOrderApproverScopeTest.php
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$MAIN_ID = 1;
$VIEWER_USER_ID = 1;

$passed = 0;
$failed = 0;
$errors = [];

function request(string $method, string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);

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
echo " Sales Order Approver Scope Test\n";
echo " Base URL: {$API_BASE}\n";
echo "==========================================================\n\n";

$health = request('GET', "{$API_BASE}/api/v1/health");
assert_eq(200, $health['http_code'], 'Health endpoint returns 200', $p, $f, $e);
assert_eq(true, $health['body']['ok'] ?? false, 'Health response ok=true', $p, $f, $e);
if (($health['http_code'] ?? 0) !== 200 || ($health['body']['ok'] ?? false) !== true) {
    exit(1);
}

$list = request(
    'GET',
    "{$API_BASE}/api/v1/sales-orders?main_id={$MAIN_ID}&status=all&page=1&per_page=1&viewer_user_id={$VIEWER_USER_ID}"
);
assert_eq(200, $list['http_code'], 'Sales order list returns 200', $p, $f, $e);
$items = $list['body']['data']['items'] ?? [];
assert_true(is_array($items) && count($items) > 0, 'At least one sales order is available for scope check', $p, $f, $e);
$salesRefno = (string) ($items[0]['sales_refno'] ?? '');
assert_true($salesRefno !== '', 'List returned a sales_refno', $p, $f, $e);

$detail = request(
    'GET',
    "{$API_BASE}/api/v1/sales-orders/{$salesRefno}?main_id={$MAIN_ID}&viewer_user_id={$VIEWER_USER_ID}"
);
assert_eq(200, $detail['http_code'], 'Sales order detail returns 200', $p, $f, $e);
assert_eq(
    true,
    (bool) ($detail['body']['data']['order']['viewer_is_approver'] ?? false),
    'Owner/master account can approve Sales Orders without an approver assignment row',
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
