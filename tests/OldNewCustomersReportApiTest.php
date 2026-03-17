<?php

declare(strict_types=1);

/**
 * Old/New Customers Report API Integration Tests
 *
 * Run: php api/tests/OldNewCustomersReportApiTest.php
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
echo " Old/New Customers Report API Integration Tests\n";
echo " Base URL: {$API_BASE}\n";
echo "==========================================================\n\n";

echo "--- 1. Health Check ---\n";
$res = request('GET', "{$API_BASE}/api/v1/health");
assert_eq(200, $res['http_code'], 'Health endpoint returns 200', $passed, $failed, $errors);
assert_eq(true, $res['body']['ok'] ?? false, 'Health response ok=true', $passed, $failed, $errors);

if (($res['http_code'] ?? 0) === 0 || ($res['body']['ok'] ?? false) !== true) {
    echo "\n[FAIL] API server not reachable at {$API_BASE}. Aborting tests.\n";
    exit(1);
}

echo "\n--- 2. List All ---\n";
$all = request('GET', "{$API_BASE}/api/v1/old-new-customers-report?main_id={$MAIN_ID}&status=all&page=1&per_page=5");
assert_eq(200, $all['http_code'], 'All customers report returns 200', $passed, $failed, $errors);
assert_eq(true, $all['body']['ok'] ?? false, 'All customers report ok=true', $passed, $failed, $errors);
assert_true(is_array($all['body']['data']['items'] ?? null), 'All customers report includes items array', $passed, $failed, $errors);
assert_true(is_array($all['body']['data']['summary'] ?? null), 'All customers report includes summary object', $passed, $failed, $errors);
assert_true(is_array($all['body']['data']['meta'] ?? null), 'All customers report includes meta object', $passed, $failed, $errors);
assert_eq(5, (int) ($all['body']['data']['meta']['per_page'] ?? 0), 'Per-page metadata is preserved', $passed, $failed, $errors);

$allItems = $all['body']['data']['items'] ?? [];
if (count($allItems) > 0) {
    assert_true(isset($allItems[0]['customer_type']), 'List rows include customer_type', $passed, $failed, $errors);
    assert_true(isset($allItems[0]['customer_since']), 'List rows include customer_since', $passed, $failed, $errors);
}

echo "\n--- 3. Filter Old ---\n";
$old = request('GET', "{$API_BASE}/api/v1/old-new-customers-report?main_id={$MAIN_ID}&status=old&page=1&per_page=5");
assert_eq(200, $old['http_code'], 'Old customers filter returns 200', $passed, $failed, $errors);
assert_eq('old', (string) ($old['body']['data']['meta']['status'] ?? ''), 'Old filter echoes status in meta', $passed, $failed, $errors);
$oldItems = $old['body']['data']['items'] ?? [];
if (count($oldItems) > 0) {
    assert_true(
        count(array_filter($oldItems, static fn(array $row): bool => (string) ($row['customer_type'] ?? '') !== 'old')) === 0,
        'Old filter only returns old rows',
        $passed,
        $failed,
        $errors
    );
}

echo "\n--- 4. Filter New ---\n";
$new = request('GET', "{$API_BASE}/api/v1/old-new-customers-report?main_id={$MAIN_ID}&status=new&page=1&per_page=5");
assert_eq(200, $new['http_code'], 'New customers filter returns 200', $passed, $failed, $errors);
assert_eq('new', (string) ($new['body']['data']['meta']['status'] ?? ''), 'New filter echoes status in meta', $passed, $failed, $errors);
$newItems = $new['body']['data']['items'] ?? [];
if (count($newItems) > 0) {
    assert_true(
        count(array_filter($newItems, static fn(array $row): bool => (string) ($row['customer_type'] ?? '') !== 'new')) === 0,
        'New filter only returns new rows',
        $passed,
        $failed,
        $errors
    );
}

echo "\n--- 5. Search ---\n";
$searchTerm = '';
if (count($allItems) > 0) {
    $searchTerm = substr((string) ($allItems[0]['customer_name'] ?? ''), 0, 3);
}
if ($searchTerm !== '') {
    $search = request(
        'GET',
        "{$API_BASE}/api/v1/old-new-customers-report?main_id={$MAIN_ID}&status=all&page=1&per_page=5&search=" . rawurlencode($searchTerm)
    );
    assert_eq(200, $search['http_code'], 'Search returns 200', $passed, $failed, $errors);
    assert_eq(trim($searchTerm), (string) ($search['body']['data']['meta']['search'] ?? ''), 'Search term is preserved in meta', $passed, $failed, $errors);
} else {
    echo "  [SKIP] Search test skipped because no seeded customer name was available\n";
}

echo "\n--- 6. Pagination Metadata ---\n";
$pageTwo = request('GET', "{$API_BASE}/api/v1/old-new-customers-report?main_id={$MAIN_ID}&status=all&page=2&per_page=1");
assert_eq(200, $pageTwo['http_code'], 'Page 2 returns 200', $passed, $failed, $errors);
assert_eq(2, (int) ($pageTwo['body']['data']['meta']['page'] ?? 0), 'Page metadata returns requested page', $passed, $failed, $errors);
assert_eq(1, (int) ($pageTwo['body']['data']['meta']['per_page'] ?? 0), 'Pagination metadata returns requested per_page', $passed, $failed, $errors);

echo "\n--- 7. Validation Errors ---\n";
$missingMainId = request('GET', "{$API_BASE}/api/v1/old-new-customers-report");
assert_eq(422, $missingMainId['http_code'], 'Missing main_id returns 422', $passed, $failed, $errors);

$invalidStatus = request('GET', "{$API_BASE}/api/v1/old-new-customers-report?main_id={$MAIN_ID}&status=recent");
assert_eq(422, $invalidStatus['http_code'], 'Invalid status returns 422', $passed, $failed, $errors);

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

exit(0);
