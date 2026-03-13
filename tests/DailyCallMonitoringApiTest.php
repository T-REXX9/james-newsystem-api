<?php

declare(strict_types=1);

/**
 * Daily Call Monitoring API Integration Tests
 *
 * Run: php api/tests/DailyCallMonitoringApiTest.php
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');

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
        CURLOPT_TIMEOUT => 10,
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
echo " Daily Call Monitoring API Integration Tests\n";
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

echo "\n--- 2. Agent Snapshot ---\n";
$res = request('GET', "{$API_BASE}/api/v1/daily-call-monitoring/agent-snapshot?main_id=1&viewer_user_id=63");
assert_eq(200, $res['http_code'], 'Agent snapshot returns 200', $passed, $failed, $errors);
assert_eq(true, $res['body']['ok'] ?? false, 'Agent snapshot response ok=true', $passed, $failed, $errors);
assert_true(is_array($res['body']['data']['contacts'] ?? null), 'Agent snapshot includes contacts array', $passed, $failed, $errors);
assert_true(is_array($res['body']['data']['team_messages'] ?? null), 'Agent snapshot includes team_messages array', $passed, $failed, $errors);

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
