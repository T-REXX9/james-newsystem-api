<?php

declare(strict_types=1);

/**
 * Promotion API Integration Tests
 *
 * Run: php api/tests/PromotionApiTest.php
 *
 * Tests all promotion endpoints against the running API.
 * Cleans up test data after each run.
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://localhost:3305', '/');

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
        CURLOPT_TIMEOUT => 10,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $responseBody = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error !== '') {
        return ['http_code' => 0, 'body' => null, 'error' => $error];
    }

    $decoded = json_decode((string) $responseBody, true);

    return ['http_code' => $httpCode, 'body' => $decoded, 'raw' => $responseBody];
}

function assert_true(bool $condition, string $message, array &$passed, array &$failed, array &$errors): void
{
    if ($condition) {
        $passed[0]++;
        echo "  ✅ {$message}\n";
    } else {
        $failed[0]++;
        $errors[] = $message;
        echo "  ❌ {$message}\n";
    }
}

function assert_eq($expected, $actual, string $message, array &$passed, array &$failed, array &$errors): void
{
    if ($expected === $actual) {
        $passed[0]++;
        echo "  ✅ {$message}\n";
    } else {
        $failed[0]++;
        $errors[] = "{$message} (expected: " . json_encode($expected) . ", got: " . json_encode($actual) . ")";
        echo "  ❌ {$message} (expected: " . json_encode($expected) . ", got: " . json_encode($actual) . ")\n";
    }
}

$p = [&$passed];
$f = [&$failed];
$e = &$errors;

echo "==========================================================\n";
echo " Promotion API Integration Tests\n";
echo " Base URL: {$API_BASE}\n";
echo "==========================================================\n\n";

// ============================================================================
// 1. Health Check
// ============================================================================
echo "--- 1. Health Check ---\n";
$res = request('GET', "{$API_BASE}/api/v1/health");
assert_eq(200, $res['http_code'], 'Health endpoint returns 200', $p, $f, $e);
assert_eq(true, $res['body']['ok'] ?? false, 'Health response ok=true', $p, $f, $e);
if (($res['http_code'] ?? 0) === 0 || ($res['body']['ok'] ?? false) !== true) {
    echo "\n❌ API server not reachable at {$API_BASE}. Aborting tests.\n";
    exit(1);
}

// ============================================================================
// 2. Promotion Stats (empty state)
// ============================================================================
echo "\n--- 2. Stats (empty) ---\n";
$res = request('GET', "{$API_BASE}/api/v1/promotions/stats/summary");
assert_eq(200, $res['http_code'], 'Stats endpoint returns 200', $p, $f, $e);
assert_eq(true, $res['body']['ok'] ?? false, 'Stats response ok=true', $p, $f, $e);
assert_true(isset($res['body']['data']['total_active']), 'Stats has total_active field', $p, $f, $e);
assert_true(isset($res['body']['data']['pending_reviews']), 'Stats has pending_reviews field', $p, $f, $e);
assert_true(isset($res['body']['data']['expiring_soon']), 'Stats has expiring_soon field', $p, $f, $e);

// ============================================================================
// 3. List Promotions (empty state)
// ============================================================================
echo "\n--- 3. List Promotions (empty) ---\n";
$res = request('GET', "{$API_BASE}/api/v1/promotions?page=1&per_page=50");
assert_eq(200, $res['http_code'], 'List endpoint returns 200', $p, $f, $e);
assert_eq(true, $res['body']['ok'] ?? false, 'List response ok=true', $p, $f, $e);
assert_true(is_array($res['body']['data']['data'] ?? null), 'List has data array', $p, $f, $e);
assert_true(isset($res['body']['data']['pagination']), 'List has pagination', $p, $f, $e);

// ============================================================================
// 4. Create Promotion
// ============================================================================
echo "\n--- 4. Create Promotion ---\n";
$createPayload = [
    'campaign_title' => 'PHPUnit Test Campaign',
    'description' => 'Automated test campaign',
    'start_date' => '2026-03-01 00:00:00',
    'end_date' => '2026-06-01 00:00:00',
    'status' => 'Active',
    'assigned_to' => [],
    'target_all_clients' => false,
    'target_client_ids' => ['contact-001', 'contact-002'],
    'target_cities' => ['Makati'],
    'target_platforms' => ['Facebook', 'Instagram'],
    'products' => [
        ['product_id' => 'test-product-001', 'promo_price_aa' => 100.50, 'promo_price_bb' => 90.00],
        ['product_id' => 'test-product-002', 'promo_price_aa' => 80.00],
    ],
];
$res = request('POST', "{$API_BASE}/api/v1/promotions", $createPayload);
assert_eq(200, $res['http_code'], 'Create returns 200', $p, $f, $e);
assert_eq(true, $res['body']['ok'] ?? false, 'Create response ok=true', $p, $f, $e);

$promotionId = $res['body']['data']['id'] ?? null;
assert_true($promotionId !== null, 'Created promotion has an id', $p, $f, $e);
assert_eq('PHPUnit Test Campaign', $res['body']['data']['campaign_title'] ?? '', 'Title matches', $p, $f, $e);
assert_eq('Active', $res['body']['data']['status'] ?? '', 'Status is Active', $p, $f, $e);
assert_true(isset($res['body']['data']['target_all_clients']), 'Create response includes target_all_clients field', $p, $f, $e);
assert_eq(2, count($res['body']['data']['products'] ?? []), 'Create also persists 2 promotion products', $p, $f, $e);
assert_eq(2, count($res['body']['data']['postings'] ?? []), 'Create also persists 2 promotion postings', $p, $f, $e);

// ============================================================================
// 4b. Create Validation - missing required fields
// ============================================================================
echo "\n--- 4b. Create Validation ---\n";
$res = request('POST', "{$API_BASE}/api/v1/promotions", ['description' => 'no title']);
assert_eq(422, $res['http_code'], 'Missing title returns 422', $p, $f, $e);

$res = request('POST', "{$API_BASE}/api/v1/promotions", ['campaign_title' => 'no end date']);
assert_eq(422, $res['http_code'], 'Missing end_date returns 422', $p, $f, $e);

// ============================================================================
// 5. Get Single Promotion
// ============================================================================
echo "\n--- 5. Get Promotion by ID ---\n";
if ($promotionId) {
    $res = request('GET', "{$API_BASE}/api/v1/promotions/{$promotionId}");
    assert_eq(200, $res['http_code'], 'Get by ID returns 200', $p, $f, $e);
    assert_eq('PHPUnit Test Campaign', $res['body']['data']['campaign_title'] ?? '', 'Title matches on get', $p, $f, $e);
}

$res = request('GET', "{$API_BASE}/api/v1/promotions/999999");
assert_eq(404, $res['http_code'], 'Non-existent ID returns 404', $p, $f, $e);

// ============================================================================
// 6. Update Promotion
// ============================================================================
echo "\n--- 6. Update Promotion ---\n";
if ($promotionId) {
    $res = request('PATCH', "{$API_BASE}/api/v1/promotions/{$promotionId}", [
        'campaign_title' => 'Updated Test Campaign',
        'description' => 'Updated description',
    ]);
    assert_eq(200, $res['http_code'], 'Update returns 200', $p, $f, $e);
    assert_eq('Updated Test Campaign', $res['body']['data']['campaign_title'] ?? '', 'Title updated', $p, $f, $e);
    assert_eq('Updated description', $res['body']['data']['description'] ?? '', 'Description updated', $p, $f, $e);
}

// ============================================================================
// 7. Stats After Create (should have 1 active)
// ============================================================================
echo "\n--- 7. Stats (after create) ---\n";
$res = request('GET', "{$API_BASE}/api/v1/promotions/stats/summary");
assert_eq(200, $res['http_code'], 'Stats still returns 200', $p, $f, $e);
$totalActive = $res['body']['data']['total_active'] ?? -1;
assert_true($totalActive >= 1, "Stats shows at least 1 active promotion (got {$totalActive})", $p, $f, $e);

// ============================================================================
// 8. List With Pagination
// ============================================================================
echo "\n--- 8. List With Pagination ---\n";
$res = request('GET', "{$API_BASE}/api/v1/promotions?page=1&per_page=1");
assert_eq(200, $res['http_code'], 'Paginated list returns 200', $p, $f, $e);
assert_eq(1, $res['body']['data']['pagination']['per_page'] ?? 0, 'per_page=1 respected', $p, $f, $e);
assert_true(count($res['body']['data']['data'] ?? []) <= 1, 'At most 1 item returned', $p, $f, $e);

// ============================================================================
// 9. Create Second Promotion (Draft)
// ============================================================================
echo "\n--- 9. Create Draft Promotion ---\n";
$res = request('POST', "{$API_BASE}/api/v1/promotions", [
    'campaign_title' => 'Draft Campaign',
    'description' => 'Draft test',
    'end_date' => '2026-07-01 00:00:00',
    'status' => 'Draft',
    'assigned_to' => [],
    'target_platforms' => ['TikTok'],
]);
assert_eq(200, $res['http_code'], 'Create draft returns 200', $p, $f, $e);
$draftId = $res['body']['data']['id'] ?? null;
assert_true($draftId !== null, 'Draft promotion has an id', $p, $f, $e);

// ============================================================================
// 10. Filter by Status
// ============================================================================
echo "\n--- 10. Filter by Status ---\n";
$res = request('GET', "{$API_BASE}/api/v1/promotions?status=Active&per_page=500");
assert_eq(200, $res['http_code'], 'Status filter returns 200', $p, $f, $e);
$activeItems = $res['body']['data']['data'] ?? [];
foreach ($activeItems as $item) {
    assert_eq('Active', $item['status'], "Filtered item status is Active (id={$item['id']})", $p, $f, $e);
}

// ============================================================================
// 11. Promotion Postings
// ============================================================================
echo "\n--- 11. Promotion Postings ---\n";
$postingId = null;
if ($promotionId) {
    // Create posting
    $res = request('POST', "{$API_BASE}/api/v1/promotions/{$promotionId}/postings", [
        'platform_name' => 'Facebook',
        'status' => 'Not Posted',
    ]);
    assert_eq(200, $res['http_code'], 'Create posting returns 200', $p, $f, $e);
    $postingId = $res['body']['data']['id'] ?? null;
    assert_true($postingId !== null, 'Posting has an id', $p, $f, $e);
    assert_eq('Facebook', $res['body']['data']['platform_name'] ?? '', 'Posting platform matches', $p, $f, $e);

    // List postings
    $res = request('GET', "{$API_BASE}/api/v1/promotions/{$promotionId}/postings");
    assert_eq(200, $res['http_code'], 'List postings returns 200', $p, $f, $e);
    assert_true(count($res['body']['data']['data'] ?? []) >= 1, 'At least 1 posting listed', $p, $f, $e);

    // Get single posting
    if ($postingId) {
        $res = request('GET', "{$API_BASE}/api/v1/promotion-postings/{$postingId}");
        assert_eq(200, $res['http_code'], 'Get single posting returns 200', $p, $f, $e);
    }

    // Update posting
    if ($postingId) {
        $res = request('PATCH', "{$API_BASE}/api/v1/promotion-postings/{$postingId}", [
            'status' => 'Pending Review',
            'screenshot_url' => 'https://example.com/screenshot.png',
        ]);
        assert_eq(200, $res['http_code'], 'Update posting returns 200', $p, $f, $e);
        assert_eq('Pending Review', $res['body']['data']['status'] ?? '', 'Posting status updated', $p, $f, $e);
    }

    // Review posting (approve)
    if ($postingId) {
        $res = request('POST', "{$API_BASE}/api/v1/promotion-postings/{$postingId}/review", [
            'status' => 'Approved',
            'reviewed_by' => '1',
        ]);
        assert_eq(200, $res['http_code'], 'Review posting returns 200', $p, $f, $e);
        assert_eq('Approved', $res['body']['data']['status'] ?? '', 'Posting is now Approved', $p, $f, $e);
    }
}

// ============================================================================
// 12. Promotion Products
// ============================================================================
echo "\n--- 12. Promotion Products ---\n";
$promoProductId = null;
if ($promotionId) {
    // Add product
    $res = request('POST', "{$API_BASE}/api/v1/promotions/{$promotionId}/products", [
        'product_id' => 'test-product-003',
        'promo_price_aa' => 100.50,
        'promo_price_bb' => 90.00,
    ]);
    assert_eq(200, $res['http_code'], 'Add product returns 200', $p, $f, $e);
    $promoProductId = $res['body']['data']['id'] ?? null;
    assert_true($promoProductId !== null, 'Promotion product has an id', $p, $f, $e);

    // List products
    $res = request('GET', "{$API_BASE}/api/v1/promotions/{$promotionId}/products");
    assert_eq(200, $res['http_code'], 'List products returns 200', $p, $f, $e);
    assert_true(count($res['body']['data']['data'] ?? []) >= 1, 'At least 1 product listed', $p, $f, $e);

    // Update product
    if ($promoProductId) {
        $res = request('PATCH', "{$API_BASE}/api/v1/promotion-products/{$promoProductId}", [
            'promo_price_aa' => 110.00,
        ]);
        assert_eq(200, $res['http_code'], 'Update product price returns 200', $p, $f, $e);
    }

    // Delete product
    if ($promoProductId) {
        $res = request('DELETE', "{$API_BASE}/api/v1/promotion-products/{$promoProductId}");
        assert_eq(200, $res['http_code'], 'Delete product returns 200', $p, $f, $e);
    }
}

// ============================================================================
// 13. Batch Add Products
// ============================================================================
echo "\n--- 13. Batch Add Products ---\n";
if ($promotionId) {
    $res = request('POST', "{$API_BASE}/api/v1/promotions/{$promotionId}/products/batch", [
        'products' => [
            ['product_id' => 'batch-prod-001', 'promo_price_aa' => 50.00],
            ['product_id' => 'batch-prod-002', 'promo_price_aa' => 60.00],
        ],
    ]);
    assert_eq(200, $res['http_code'], 'Batch add returns 200', $p, $f, $e);
    $batchData = $res['body']['data']['data'] ?? [];
    assert_eq(2, count($batchData), 'Batch created 2 products', $p, $f, $e);
}

// ============================================================================
// 14. Extend Promotion
// ============================================================================
echo "\n--- 14. Extend Promotion ---\n";
if ($promotionId) {
    $res = request('POST', "{$API_BASE}/api/v1/promotions/{$promotionId}/extend", [
        'end_date' => '2026-12-31 00:00:00',
    ]);
    assert_eq(200, $res['http_code'], 'Extend returns 200', $p, $f, $e);
    assert_eq('2026-12-31 00:00:00', $res['body']['data']['end_date'] ?? '', 'End date extended', $p, $f, $e);
}

// ============================================================================
// 15. Active Promotions List
// ============================================================================
echo "\n--- 15. Active Promotions ---\n";
$res = request('GET', "{$API_BASE}/api/v1/promotions/active/list?limit=10");
assert_eq(200, $res['http_code'], 'Active list returns 200', $p, $f, $e);
assert_true(is_array($res['body']['data']['data'] ?? null), 'Active list has data array', $p, $f, $e);
assert_true(!isset($res['body']['error']) || $res['body']['error'] !== 'Promotion not found', 'Active list is handled by the static route', $p, $f, $e);

// ============================================================================
// 16. Promotions by Status
// ============================================================================
echo "\n--- 16. Promotions by Status ---\n";
$res = request('GET', "{$API_BASE}/api/v1/promotions/status/Draft?limit=10");
assert_eq(200, $res['http_code'], 'By status returns 200', $p, $f, $e);
assert_true(is_array($res['body']['data']['data'] ?? null), 'By status has data array', $p, $f, $e);
assert_true(!isset($res['body']['error']) || $res['body']['error'] !== 'Promotion not found', 'Status list is handled by the static route', $p, $f, $e);

// ============================================================================
// 17. Pending Review Postings
// ============================================================================
echo "\n--- 17. Pending Review Postings ---\n";
$res = request('GET', "{$API_BASE}/api/v1/promotion-postings/review/pending?limit=10");
assert_eq(200, $res['http_code'], 'Pending review returns 200', $p, $f, $e);

// ============================================================================
// 18. Search Promotions
// ============================================================================
echo "\n--- 18. Search Promotions ---\n";
$res = request('GET', "{$API_BASE}/api/v1/promotions?search=Updated&per_page=50");
assert_eq(200, $res['http_code'], 'Search returns 200', $p, $f, $e);
$searchResults = $res['body']['data']['data'] ?? [];
assert_true(count($searchResults) >= 1, 'Search found at least 1 result', $p, $f, $e);

// ============================================================================
// 19. Delete Promotion (soft delete)
// ============================================================================
echo "\n--- 19. Delete Promotion ---\n";
if ($draftId) {
    $res = request('DELETE', "{$API_BASE}/api/v1/promotions/{$draftId}");
    assert_eq(200, $res['http_code'], 'Delete returns 200', $p, $f, $e);
    assert_eq(true, $res['body']['data']['ok'] ?? false, 'Delete response ok=true', $p, $f, $e);

    // Verify soft-deleted promotion is not in list
    $res = request('GET', "{$API_BASE}/api/v1/promotions/{$draftId}");
    assert_eq(404, $res['http_code'], 'Deleted promotion returns 404', $p, $f, $e);
}

// ============================================================================
// 20. Delete Non-existent
// ============================================================================
echo "\n--- 20. Delete Non-existent ---\n";
$res = request('DELETE', "{$API_BASE}/api/v1/promotions/999999");
assert_eq(404, $res['http_code'], 'Delete non-existent returns 404', $p, $f, $e);

// ============================================================================
// Cleanup test data
// ============================================================================
echo "\n--- Cleanup ---\n";
if ($promotionId) {
    // Cascade delete handles products and postings
    request('DELETE', "{$API_BASE}/api/v1/promotions/{$promotionId}");
}
// Hard delete from DB to fully clean up
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
$dbName = getenv('DB_NAME') ?: 'topnotch';

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName}", $dbUser, $dbPass);
    $pdo->exec("DELETE FROM promotion_postings WHERE promotion_id IN (SELECT id FROM promotions WHERE campaign_title LIKE '%Test Campaign%' OR campaign_title LIKE '%Updated Test Campaign%' OR campaign_title LIKE '%Draft Campaign%')");
    $pdo->exec("DELETE FROM promotion_products WHERE promotion_id IN (SELECT id FROM promotions WHERE campaign_title LIKE '%Test Campaign%' OR campaign_title LIKE '%Updated Test Campaign%' OR campaign_title LIKE '%Draft Campaign%')");
    $pdo->exec("DELETE FROM promotions WHERE campaign_title LIKE '%Test Campaign%' OR campaign_title LIKE '%Updated Test Campaign%' OR campaign_title LIKE '%Draft Campaign%'");
    echo "  ✅ Test data cleaned up\n";
} catch (\Exception $ex) {
    echo "  ⚠️  Could not clean up test data: {$ex->getMessage()}\n";
}

// ============================================================================
// Summary
// ============================================================================
echo "\n==========================================================\n";
echo " Results: {$passed} passed, {$failed} failed\n";
echo "==========================================================\n";

if ($failed > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as $err) {
        echo "  ❌ {$err}\n";
    }
    exit(1);
}

echo "\n🎉 All tests passed!\n";
exit(0);
