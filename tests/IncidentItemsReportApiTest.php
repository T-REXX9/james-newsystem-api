<?php

declare(strict_types=1);

/**
 * Incident Items Report API Integration Tests
 *
 * Run: php api/tests/IncidentItemsReportApiTest.php
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$MAIN_ID = 1;

require_once __DIR__ . '/../src/Support/Env.php';
\App\Support\Env::load(__DIR__ . '/../.env');
require_once __DIR__ . '/../src/Security/TokenService.php';
$testTokenService = new \App\Security\TokenService(
    (string) \App\Support\Env::get('AUTH_SECRET', (string) \App\Support\Env::get('APP_KEY', 'change-me-in-env')),
    3600
);
$testToken = $testTokenService->issue(['sub' => 1, 'main_userid' => $MAIN_ID]);
$authHeaders = ["Authorization: Bearer {$testToken}"];

$passed = 0;
$failed = 0;
$errors = [];

function request(string $method, string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
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
$vars = file_exists(__DIR__ . '/../.env') ? (parse_ini_file(__DIR__ . '/../.env') ?: []) : [];
$dbHost = (string) ($vars['DB_HOST'] ?? '127.0.0.1');
$dbPort = (int) ($vars['DB_PORT'] ?? 3306);
$dbName = (string) ($vars['DB_NAME'] ?? 'topnotch_migrate');
$dbUser = (string) ($vars['DB_USER'] ?? 'root');
$dbPass = (string) ($vars['DB_PASS'] ?? '');
$pdo = new PDO(
    "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$seedReportId = 'UT-INC-ITEMS-' . bin2hex(random_bytes(4));
$seedContactId = 'UT-INC-CONTACT-' . bin2hex(random_bytes(3));
$seedCleanup = static function () use ($pdo, $seedReportId): void {
    $pdo->prepare('DELETE FROM incident_report_items WHERE incident_report_id = :id')->execute(['id' => $seedReportId]);
    $pdo->prepare('DELETE FROM incident_reports WHERE id = :id')->execute(['id' => $seedReportId]);
};
$seedCleanup();

$pdo->prepare(
    'INSERT INTO incident_reports (
        id, main_id, contact_id, report_date, report_time, incident_date, incident_time,
        issue_type, description, reported_by, done_by, approval_status
    ) VALUES (
        :id, :main_id, :contact_id, CURDATE(), CURTIME(), CURDATE(), CURTIME(),
        "product_quality", "API test nozzle leak", "Unit Test", "Unit Test", "pending"
    )'
)->execute([
    'id' => $seedReportId,
    'main_id' => $MAIN_ID,
    'contact_id' => $seedContactId,
]);

$pdo->prepare(
    'INSERT INTO incident_report_items (
        main_id, incident_report_id, contact_id, product_id, item_code, part_no,
        description, supplier_id, supplier_name, quantity, issue_summary, match_source, confidence_score, metadata
    ) VALUES (
        :main_id, :incident_report_id, :contact_id, :product_id, :item_code, :part_no,
        :description, :supplier_id, :supplier_name, 1, :issue_summary, "manual", 1,
        JSON_OBJECT("seed", "incident-items-api-test")
    )'
)->execute([
    'main_id' => $MAIN_ID,
    'incident_report_id' => $seedReportId,
    'contact_id' => $seedContactId,
    'product_id' => 'ut-product-1',
    'item_code' => 'UT-NOZZLE',
    'part_no' => 'UT-PN-1',
    'description' => 'Unit Test Nozzle',
    'supplier_id' => 'ut-supplier-1',
    'supplier_name' => 'UT Supplier',
    'issue_summary' => 'API test nozzle leak',
]);

try {
$report = request('GET', "{$API_BASE}/api/v1/incident-items-report?main_id={$MAIN_ID}&page=1&per_page=5&search=UT-NOZZLE", $authHeaders);
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

echo "\n--- 3. Item Incident List ---\n";
if (count($items) > 0) {
    $first = $items[0];
    $listParams = http_build_query([
        'main_id' => $MAIN_ID,
        'supplier_id' => $first['supplier_id'] ?? '',
        'supplier_name' => $first['supplier_name'] ?? '',
        'product_id' => $first['product_id'] ?? '',
        'item_code' => $first['item_code'] ?? '',
        'part_no' => $first['part_no'] ?? '',
        'description' => $first['description'] ?? '',
    ]);
    $itemList = request('GET', "{$API_BASE}/api/v1/incident-items-report/incidents?{$listParams}", $authHeaders);
    assert_eq(200, $itemList['http_code'], 'Item incident list returns 200', $passed, $failed, $errors);
    assert_eq(true, $itemList['body']['ok'] ?? false, 'Item incident list ok=true', $passed, $failed, $errors);
    $incidents = $itemList['body']['data']['incidents'] ?? null;
    assert_true(is_array($incidents), 'Item incident list includes incidents array', $passed, $failed, $errors);
    assert_eq(
        (int) ($first['incident_count'] ?? -1),
        is_array($incidents) ? count($incidents) : -1,
        'Item incident list length matches incident_count',
        $passed,
        $failed,
        $errors
    );
    if (is_array($incidents) && count($incidents) > 0) {
        $detail = request(
            'GET',
            "{$API_BASE}/api/v1/incident-items-report/incidents/" . rawurlencode((string) $incidents[0]['incident_report_id']) . "?main_id={$MAIN_ID}",
            $authHeaders
        );
        assert_eq(200, $detail['http_code'], 'Warehouse incident detail returns 200', $passed, $failed, $errors);
        assert_eq(true, $detail['body']['ok'] ?? false, 'Warehouse incident detail ok=true', $passed, $failed, $errors);
        assert_eq(
            (string) $incidents[0]['incident_report_id'],
            (string) ($detail['body']['data']['id'] ?? ''),
            'Warehouse incident detail returns the selected report id',
            $passed,
            $failed,
            $errors
        );
    }
}

echo "\n--- 4. Search and Supplier Filters ---\n";
$filtered = request('GET', "{$API_BASE}/api/v1/incident-items-report?main_id={$MAIN_ID}&page=1&per_page=5&search=UT-NOZZLE&supplier=UT%20Supplier", $authHeaders);
assert_eq(200, $filtered['http_code'], 'Search and supplier filters return 200', $passed, $failed, $errors);
assert_eq(true, $filtered['body']['ok'] ?? false, 'Filtered response ok=true', $passed, $failed, $errors);
assert_true(is_array($filtered['body']['data']['items'] ?? null), 'Filtered response includes items array', $passed, $failed, $errors);
assert_true(count($filtered['body']['data']['items'] ?? []) > 0, 'Search filter finds the seeded item', $passed, $failed, $errors);

echo "\n--- 5. Local calendar date window includes today's report_date ---\n";
// Align with APP_TIMEZONE / business calendar (Asia/Manila), not PHP process UTC.
$businessTz = new DateTimeZone((string) (\App\Support\Env::get('APP_TIMEZONE', 'Asia/Manila') ?: 'Asia/Manila'));
$todayLocal = (new DateTimeImmutable('now', $businessTz))->format('Y-m-d');
$monthAgoLocal = (new DateTimeImmutable('now', $businessTz))->modify('-30 days')->format('Y-m-d');
$dateWindow = request(
    'GET',
    "{$API_BASE}/api/v1/incident-items-report?main_id={$MAIN_ID}&page=1&per_page=20&search=UT-NOZZLE"
        . "&date_from={$monthAgoLocal}&date_to={$todayLocal}",
    $authHeaders
);
assert_eq(200, $dateWindow['http_code'], 'Date window report returns 200', $passed, $failed, $errors);
assert_true(
    count($dateWindow['body']['data']['items'] ?? []) > 0,
    'Default local date window includes seeded report_date=today rows',
    $passed,
    $failed,
    $errors
);

echo "\n--- 6. Authenticated Tenant Fallback ---\n";
$missingMain = request('GET', "{$API_BASE}/api/v1/incident-items-report", $authHeaders);
assert_eq(200, $missingMain['http_code'], 'Authenticated report uses tenant from token when main_id is omitted', $passed, $failed, $errors);
assert_eq(true, $missingMain['body']['ok'] ?? false, 'Authenticated tenant fallback response ok=true', $passed, $failed, $errors);

echo "\n--- 7. Synchronization Authentication ---\n";
$unauthorizedSync = request('POST', "{$API_BASE}/api/v1/incident-report-items");
assert_eq(401, $unauthorizedSync['http_code'], 'Incident item synchronization requires authentication', $passed, $failed, $errors);
} finally {
    $seedCleanup();
}

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
