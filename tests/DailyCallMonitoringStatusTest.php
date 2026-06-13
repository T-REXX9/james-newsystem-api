<?php

declare(strict_types=1);

/**
 * Daily Call Monitoring status regression test.
 *
 * Run: php api/tests/DailyCallMonitoringStatusTest.php
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');

$passed = 0;
$failed = 0;
$errors = [];

function request(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 20,
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
        echo "  PASS {$message}\n";
        return;
    }

    $failed++;
    $errors[] = $message;
    echo "  FAIL {$message}\n";
}

echo "==========================================================\n";
echo " Daily Call Monitoring Status Test\n";
echo " Base URL: {$API_BASE}\n";
echo "==========================================================\n\n";

$res = request("{$API_BASE}/api/v1/daily-call-monitoring/excel?main_id=1&status=all&search=");
assert_true($res['http_code'] === 200, 'Excel endpoint returns 200', $passed, $failed, $errors);
assert_true(($res['body']['ok'] ?? false) === true, 'Excel endpoint response ok=true', $passed, $failed, $errors);

$rows = is_array($res['body']['data'] ?? null) ? $res['body']['data'] : [];
$activeWithoutSales = array_values(array_filter($rows, static function (array $row): bool {
    return strtolower((string) ($row['status'] ?? '')) === 'active'
        && (float) ($row['monthlyOrder'] ?? 0) <= 0;
}));

assert_true(
    count($activeWithoutSales) === 0,
    'Active rows have current-month sales',
    $passed,
    $failed,
    $errors
);

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
