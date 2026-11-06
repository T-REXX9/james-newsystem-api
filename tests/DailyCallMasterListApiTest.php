<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * Daily Call Master List API regression test.
 *
 * Run: php api/tests/DailyCallMasterListApiTest.php
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$ROOT = dirname(__DIR__);
$vars = [];
foreach (file($ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $vars[trim($key)] = trim($value);
}
$MAIN_ID = (int) (getenv('DAILY_CALL_TEST_MAIN_ID') ?: 1);
$USER_ID = (int) (getenv('DAILY_CALL_TEST_USER_ID') ?: 1);
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $vars['DB_HOST'] ?? '127.0.0.1', $vars['DB_PORT'] ?? '3306', $vars['DB_NAME'] ?? 'topnotch_migrate'),
    $vars['DB_USER'] ?? 'root',
    $vars['DB_PASS'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$accountQuery = $pdo->prepare('SELECT ltype, lsession_version FROM tblaccount WHERE lid = :id AND COALESCE(lstatus, 0) = 1 LIMIT 1');
$accountQuery->execute(['id' => $USER_ID]);
$account = $accountQuery->fetch(PDO::FETCH_ASSOC);
if (!$account) {
    fwrite(STDERR, "DAILY_CALL_TEST_USER_ID must identify an active API user.\n");
    exit(2);
}
$AUTH_TOKEN = (new App\Security\TokenService((string) ($vars['AUTH_SECRET'] ?? $vars['APP_KEY'] ?? 'change-me-in-env')))->issue([
    'sub' => $USER_ID,
    'main_userid' => $MAIN_ID,
    'user_type' => (string) ($account['ltype'] ?? '1'),
    'session_version' => (int) ($account['lsession_version'] ?? 0),
]);

$passed = 0;
$failed = 0;
$errors = [];

function request(string $url): array
{
    global $AUTH_TOKEN;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $AUTH_TOKEN],
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
assert_true(
    count(array_filter($rows, static fn (array $row): bool => array_key_exists('is_overridden', $row) && array_key_exists('override_reason', $row))) === count($rows),
    'Master list rows include Priority List override state and reason',
    $passed,
    $failed,
    $errors
);

$badCategoryRows = array_values(array_filter($rows, static function (array $row): bool {
    $category = (string) ($row['list_category'] ?? '');
    $priorityTransactions = (int) ($row['priority_transaction_count'] ?? 0);
    $ledgerTransactions = (int) ($row['ledger_transaction_count'] ?? 0);

    if ($category === 'priority') {
        return $priorityTransactions < 1;
    }
    if ($category === 'recovery') {
        return $priorityTransactions !== 0 || $ledgerTransactions < 1;
    }
    if ($category === 'no_purchase') {
        return $ledgerTransactions !== 0;
    }
    return true;
}));
assert_true(count($badCategoryRows) === 0, 'Rows use mutually exclusive priority and recovery rules', $passed, $failed, $errors);

$validAgeGroups = ['recent', 'two_weeks_to_one_month', 'over_one_month', 'no_purchase'];
$badAgeGroupRows = array_values(array_filter($rows, static function (array $row) use ($validAgeGroups): bool {
    $days = (int) ($row['days_since_last_purchase'] ?? -1);
    $group = (string) ($row['purchase_age_group'] ?? '');
    if ($days < 0 || !in_array($group, $validAgeGroups, true)) {
        return true;
    }
    if ((int) ($row['ledger_transaction_count'] ?? 0) === 0) {
        return $group !== 'no_purchase';
    }
    if ($days < 15) {
        return $group !== 'recent';
    }
    if ($days <= 30) {
        return $group !== 'two_weeks_to_one_month';
    }
    return $group !== 'over_one_month';
}));
assert_true(count($badAgeGroupRows) === 0, 'Rows include correct purchase-age groups', $passed, $failed, $errors);

$bmcRows = array_values(array_filter($rows, static function (array $row): bool {
    return (string) ($row['shopName'] ?? '') === 'BMC CALIBRATION';
}));
assert_true(count($bmcRows) === 1, 'BMC calibration fixture is present', $passed, $failed, $errors);
if (count($bmcRows) === 1) {
    $bmc = $bmcRows[0];
    assert_true(
        (string) ($bmc['last_purchase_date_raw'] ?? '') === '2026-07-18'
            && (int) ($bmc['average_monthly_sales_year'] ?? 0) === 2026
            && (int) ($bmc['average_monthly_sales_month_count'] ?? 0) === 2
            && abs((float) ($bmc['average_monthly_sales'] ?? 0) - 16240.0) < 0.01,
        'BMC recovery average uses the latest purchase window',
        $passed,
        $failed,
        $errors
    );
}

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
