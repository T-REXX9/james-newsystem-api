<?php

declare(strict_types=1);

/**
 * Incident Items Report API Integration Tests
 *
 * Run: php api/tests/IncidentItemsReportApiTest.php
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$MAIN_ID = 1;

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
        CURLOPT_TIMEOUT => 15,
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

function assert_true(bool $condition, string $message, int &$passed, int &$failed, array &$errors): void
{
    if ($condition) {
        $passed++;
        echo "  [PASS] {$message}\n";
        return;
    }

    $failed++;
    $errors[] = $message;
    echo "  [FAIL] {$message}\n";
}

function assert_eq($expected, $actual, string $message, int &$passed, int &$failed, array &$errors): void
{
    if ($expected === $actual) {
        $passed++;
        echo "  [PASS] {$message}\n";
        return;
    }

    $failed++;
    $errors[] = "{$message} (expected: " . json_encode($expected) . ", got: " . json_encode($actual) . ")";
    echo "  [FAIL] {$message} (expected: " . json_encode($expected) . ", got: " . json_encode($actual) . ")\n";
}

echo "==========================================================\n";
echo " Incident Items Report API Integration Tests\n";
echo " Base URL: {$API_BASE}\n";
echo "==========================================================\n\n";

echo "--- 1. Health Check ---\n";
$health = request('GET', "{$API_BASE}/api/v1/health");
assert_eq(200, $health['http_code'], 'Health endpoint returns 200', $passed, $failed, $errors);
assert_eq(true, $health['body']['ok'] ?? false, 'Health response ok=true', $passed, $failed, $errors);

if (($health['http_code'] ?? 0) === 0 || ($health['body']['ok'] ?? false) !== true) {
    echo "\n[FAIL] API server not reachable at {$API_BASE}. Aborting tests.\n";
    exit(1);
}

echo "\n--- 2. Report Shape ---\n";
$report = request('GET', "{$API_BASE}/api/v1/incident-items-report?main_id={$MAIN_ID}&page=1&per_page=5");
assert_eq(200, $report['http_code'], 'Incident items report returns 200', $passed, $failed, $errors);
assert_eq(true, $report['body']['ok'] ?? false, 'Incident items response ok=true', $passed, $failed, $errors);
assert_true(is_array($report['body']['data']['items'] ?? null), 'Response includes items array', $passed, $failed, $errors);
assert_true(is_array($report['body']['data']['summary'] ?? null), 'Response includes summary object', $passed, $failed, $errors);
assert_true(is_array($report['body']['data']['meta'] ?? null), 'Response includes meta object', $passed, $failed, $errors);

$items = $report['body']['data']['items'] ?? [];
assert_true(count($items) > 0, 'Seeded demo report returns at least one grouped row', $passed, $failed, $errors);
if (count($items) > 0) {
    $first = $items[0];
    assert_true(isset($first['supplier_name']), 'Rows include supplier_name', $passed, $failed, $errors);
    assert_true(isset($first['item_code']), 'Rows include item_code', $passed, $failed, $errors);
    assert_true(isset($first['part_no']), 'Rows include part_no', $passed, $failed, $errors);
    assert_true(isset($first['incident_count']), 'Rows include incident_count', $passed, $failed, $errors);
}

echo "\n--- 3. Missing main_id Validation ---\n";
$missingMain = request('GET', "{$API_BASE}/api/v1/incident-items-report");
assert_eq(422, $missingMain['http_code'], 'Missing main_id returns 422', $passed, $failed, $errors);

echo "\n==========================================================\n";
echo "Passed: {$passed} | Failed: {$failed}\n";
if ($failed > 0) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    exit(1);
}

echo "All Incident Items Report API tests passed.\n";
