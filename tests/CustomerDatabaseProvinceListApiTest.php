<?php

declare(strict_types=1);

/**
 * Customer database list vs province-summary consistency test.
 *
 * Regression guard for the Sales Map "yellow province shows 1 customer"
 * bug: the map colour counts (province-summary) and the sidebar customer
 * list (customer-database) must use the same province resolution so the
 * numbers match.
 *
 * Strategy:
 *   1. Pull the province-summary counts.
 *   2. Pull every active customer from the list endpoint (all pages).
 *   3. Group by `province` and compare to summary counts per GeoJSON
 *      canonical name.
 *   4. For every province in the summary, the list-grouped count must
 *      equal the summary count.
 *   5. Customers whose province resolves to the same canonical name
 *      must all carry that same value (no mixed-case / aliasing leaks).
 *
 * Run:
 *   API_BASE_URL=http://127.0.0.1:8081 php api/tests/CustomerDatabaseProvinceListApiTest.php
 */

require_once __DIR__ . '/../src/Repositories/CustomerDatabaseRepository.php';

use App\Repositories\CustomerDatabaseRepository;

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$MAIN_ID = (int) (getenv('MAIN_ID') ?: 1);

$passed = 0;
$failed = 0;
$errors = [];

$assert = static function (bool $cond, string $msg) use (&$passed, &$failed, &$errors): void {
    if ($cond) {
        $passed++;
        echo "  PASS {$msg}\n";
        return;
    }
    $failed++;
    $errors[] = $msg;
    echo "  FAIL {$msg}\n";
};

$assert_eq = static function (mixed $expected, mixed $actual, string $msg) use (&$passed, &$failed, &$errors): void {
    if ($expected === $actual) {
        $passed++;
        echo "  PASS {$msg}\n";
        return;
    }
    $failed++;
    $errors[] = "{$msg} expected=" . json_encode($expected) . " actual=" . json_encode($actual);
    echo "  FAIL {$msg} expected=" . json_encode($expected) . " actual=" . json_encode($actual) . "\n";
};

function request(string $method, string $url, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 60,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $responseBody = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    if ($err !== '') {
        return ['http_code' => 0, 'body' => null, 'error' => $err];
    }
    return [
        'http_code' => $httpCode,
        'body' => json_decode((string) $responseBody, true),
        'raw' => $responseBody,
    ];
}

echo "==========================================================\n";
echo " Customer Database Province List Consistency Test\n";
echo " Base URL: {$API_BASE}\n";
echo " Main ID:  {$MAIN_ID}\n";
echo "==========================================================\n\n";

// ---- Pull summary ----

$summaryRes = request('GET', "{$API_BASE}/api/v1/customer-database/province-summary?main_id={$MAIN_ID}");
$assert_eq(200, $summaryRes['http_code'], 'province-summary returns 200');
$summary = $summaryRes['body']['data']['data'] ?? [];
$assert(is_array($summary) && $summary !== [], 'province-summary returns a non-empty list');
$summaryMap = [];
foreach ($summary as $row) {
    $summaryMap[(string) $row['province']] = (int) $row['customer_count'];
}
echo "  (summary has " . count($summaryMap) . " provinces, total = " . array_sum($summaryMap) . " customers)\n";

// ---- Pull all active customers via list endpoint (all pages) ----

$listAll = [];
$page = 1;
$totalPages = null;
while ($totalPages === null || $page <= $totalPages) {
    $res = request('GET', "{$API_BASE}/api/v1/customer-database?main_id={$MAIN_ID}&status=active&page={$page}&per_page=500");
    if ($page === 1) {
        $assert_eq(200, $res['http_code'], 'list endpoint returns 200');
    }
    $items = $res['body']['data']['items'] ?? [];
    foreach ($items as $it) {
        $listAll[] = $it;
    }
    $totalPages = (int) ($res['body']['data']['meta']['total_pages'] ?? 1);
    $page++;
    if ($page > 50) { // safety stop
        break;
    }
}
echo "  (list endpoint returned " . count($listAll) . " active customers across {$totalPages} pages)\n";
$assert(count($listAll) > 0, 'list endpoint returned at least one active customer');

// ---- Group by `province` and compare ----

$listMap = [];
$emptyCount = 0;
foreach ($listAll as $row) {
    $p = trim((string) ($row['province'] ?? ''));
    if ($p === '') {
        $emptyCount++;
        continue;
    }
    $listMap[$p] = ($listMap[$p] ?? 0) + 1;
}
echo "  (list has " . count($listMap) . " distinct province values, {$emptyCount} customers with empty province)\n";

// ---- For every province in the summary, the list count must match ----

$mismatches = 0;
foreach ($summaryMap as $province => $summaryCount) {
    $listCount = $listMap[$province] ?? 0;
    if ($listCount !== $summaryCount) {
        $mismatches++;
        echo "  MISMATCH province={$province} summary={$summaryCount} list={$listCount}\n";
    }
}
$assert_eq(0, $mismatches, "every summary province count matches the list-grouped count (checked " . count($summaryMap) . " provinces)");

// ---- The list should not produce province values that are unknown to the alias map ----
// (i.e. no "raw" DB names like "QUEZON" or "City of Isabela" should leak through)

$reflection = new ReflectionClass(CustomerDatabaseRepository::class);
$map = $reflection->getReflectionConstant('GEOJSON_PROVINCE_MAP')->getValue();
$knownCanonical = array_values($map);
$knownCanonical = array_unique($knownCanonical);

$leaked = [];
foreach (array_keys($listMap) as $p) {
    if (!in_array($p, $knownCanonical, true)) {
        $leaked[] = $p;
    }
}
$assert_eq([], $leaked, "list endpoint province values are all GeoJSON-canonical (no raw alias leaks)");

// ---- The sum of all listed (non-empty) provinces must equal the sum of all summary counts ----
// (only when summary has a non-zero count; empty list values are expected for unmatched customers)

$listTotal = array_sum($listMap);
$summaryTotal = array_sum($summaryMap);
echo "  (totals: summary={$summaryTotal} list-non-empty={$listTotal} empty-province={$emptyCount})\n";
$assert_eq($summaryTotal, $listTotal, "total customers counted per province matches between summary and list");

// ---- Spot-check a specific known province to make the bug's regression very visible ----
// Summary says Quezon=75. If this regression test ever returns a different number,
// the original "click yellow province and see only 1 customer" bug is back.

$assert_eq(75, $listMap['Quezon'] ?? 0, "Quezon list count equals summary count (75)");
$assert_eq(57, $listMap['Rizal']  ?? 0, "Rizal list count equals summary count (57)");
$assert_eq(42, $listMap['Cagayan'] ?? 0, "Cagayan list count equals summary count (42)");

echo "\n==========================================================\n";
echo " Passed: {$passed}\n";
echo " Failed: {$failed}\n";
echo "==========================================================\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $e) {
        echo " - {$e}\n";
    }
    exit(1);
}

echo "\nAll province consistency assertions passed.\n";
exit(0);
