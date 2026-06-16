<?php

declare(strict_types=1);

/**
 * Daily Call Master List API regression test.
 *
 * Run: php api/tests/DailyCallMasterListApiTest.php
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$MAIN_ID = 1;

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
echo " Daily Call Master List API Test\n";
echo " Base URL: {$API_BASE}\n";
echo "==========================================================\n\n";

$res = request("{$API_BASE}/api/v1/daily-call-monitoring/master-list?main_id={$MAIN_ID}&from_date=2025-10-01");
assert_true($res['http_code'] === 200, 'Master list endpoint returns 200', $passed, $failed, $errors);
assert_true(($res['body']['ok'] ?? false) === true, 'Master list response ok=true', $passed, $failed, $errors);

$rows = is_array($res['body']['data']['items'] ?? null) ? $res['body']['data']['items'] : [];
$meta = is_array($res['body']['data']['meta'] ?? null) ? $res['body']['data']['meta'] : [];

assert_true(($meta['from_date'] ?? '') === '2025-10-01', 'Master list uses October 2025 start date', $passed, $failed, $errors);

$badDateRows = array_values(array_filter($rows, static function (array $row): bool {
    return ((string) ($row['last_purchase_date_raw'] ?? '')) < '2025-10-01';
}));
assert_true(count($badDateRows) === 0, 'Rows only include purchases from October 2025 onward', $passed, $failed, $errors);

$badCountRows = array_values(array_filter($rows, static fn(array $row): bool => (int) ($row['purchase_count'] ?? 0) < 1));
assert_true(count($badCountRows) === 0, 'Rows have at least one purchase', $passed, $failed, $errors);

$validAgeGroups = ['recent', 'two_weeks_to_one_month', 'over_one_month'];
$badAgeGroupRows = array_values(array_filter($rows, static function (array $row) use ($validAgeGroups): bool {
    $days = (int) ($row['days_since_last_purchase'] ?? -1);
    $group = (string) ($row['purchase_age_group'] ?? '');
    if ($days < 0 || !in_array($group, $validAgeGroups, true)) {
        return true;
    }
    if ($days < 14) {
        return $group !== 'recent';
    }
    if ($days <= 30) {
        return $group !== 'two_weeks_to_one_month';
    }
    return $group !== 'over_one_month';
}));
assert_true(count($badAgeGroupRows) === 0, 'Rows include correct purchase-age groups', $passed, $failed, $errors);

$sorted = true;
for ($i = 1; $i < count($rows); $i++) {
    if ((string) ($rows[$i - 1]['last_purchase_date_raw'] ?? '') < (string) ($rows[$i]['last_purchase_date_raw'] ?? '')) {
        $sorted = false;
        break;
    }
}
assert_true($sorted, 'Rows are sorted by latest purchase first', $passed, $failed, $errors);

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
