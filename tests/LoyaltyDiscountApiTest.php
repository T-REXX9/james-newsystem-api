<?php

declare(strict_types=1);

/**
 * Loyalty Discount API integration test script.
 *
 * Run:
 *   php api/tests/LoyaltyDiscountApiTest.php
 *
 * Optionally set:
 *   API_BASE_URL=http://localhost:3305
 *   MAIN_ID=1
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://localhost:3305', '/');
$MAIN_ID = (int) (getenv('MAIN_ID') ?: 1);
$passed = 0;
$failed = 0;
$errors = [];

function request(string $method, string $url, ?array $body = null): array
{
    $headers = ['Content-Type: application/json', 'Accept: application/json'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
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

    return [
        'http_code' => $httpCode,
        'body' => json_decode((string) $responseBody, true),
    ];
}

function assert_true(bool $condition, string $message, int &$passed, int &$failed, array &$errors): void
{
    if ($condition) {
        $passed++;
        echo "  ✅ {$message}\n";
        return;
    }
    $failed++;
    $errors[] = $message;
    echo "  ❌ {$message}\n";
}

function assert_eq($expected, $actual, string $message, int &$passed, int &$failed, array &$errors): void
{
    assert_true(
        $expected === $actual,
        "{$message} (expected " . json_encode($expected) . ', got ' . json_encode($actual) . ')',
        $passed, $failed, $errors
    );
}

echo "\n=== Loyalty Discount API Tests ===\n";
echo "Base URL: {$API_BASE}\n";
echo "Main ID:  {$MAIN_ID}\n\n";

// ────────────────────────────────────────────────────────────────────────────
// 1. List rules (empty state)
// ────────────────────────────────────────────────────────────────────────────
echo "── List rules (initial) ──\n";
$res = request('GET', "{$API_BASE}/api/v1/loyalty-discounts?main_id={$MAIN_ID}");
assert_eq(200, $res['http_code'], 'GET /loyalty-discounts returns 200', $passed, $failed, $errors);
assert_true(isset($res['body']['data']['items']), 'Response has items array', $passed, $failed, $errors);

// ────────────────────────────────────────────────────────────────────────────
// 2. Create — validation errors
// ────────────────────────────────────────────────────────────────────────────
echo "\n── Create validation ──\n";

$res = request('POST', "{$API_BASE}/api/v1/loyalty-discounts", ['main_id' => $MAIN_ID]);
assert_eq(422, $res['http_code'], 'Create without name returns 422', $passed, $failed, $errors);

$res = request('POST', "{$API_BASE}/api/v1/loyalty-discounts", [
    'main_id' => $MAIN_ID, 'name' => 'Test', 'min_purchase_amount' => 0, 'discount_percentage' => 5,
]);
assert_eq(422, $res['http_code'], 'Create with min_purchase_amount=0 returns 422', $passed, $failed, $errors);

$res = request('POST', "{$API_BASE}/api/v1/loyalty-discounts", [
    'main_id' => $MAIN_ID, 'name' => 'Test', 'min_purchase_amount' => 1000, 'discount_percentage' => 0,
]);
assert_eq(422, $res['http_code'], 'Create with discount_percentage=0 returns 422', $passed, $failed, $errors);

$res = request('POST', "{$API_BASE}/api/v1/loyalty-discounts", [
    'main_id' => $MAIN_ID, 'name' => 'Test', 'min_purchase_amount' => 1000, 'discount_percentage' => 150,
]);
assert_eq(422, $res['http_code'], 'Create with discount_percentage=150 returns 422', $passed, $failed, $errors);

// ────────────────────────────────────────────────────────────────────────────
// 3. Create — success
// ────────────────────────────────────────────────────────────────────────────
echo "\n── Create rule ──\n";
$res = request('POST', "{$API_BASE}/api/v1/loyalty-discounts", [
    'main_id' => $MAIN_ID,
    'name' => 'Gold Tier Test',
    'description' => 'Test gold tier discount',
    'min_purchase_amount' => 30000,
    'discount_percentage' => 5,
    'evaluation_period' => 'calendar_month',
    'priority' => 10,
    'created_by' => 'test-user',
]);
assert_eq(200, $res['http_code'], 'Create rule returns 200', $passed, $failed, $errors);
$rule = $res['body']['data'] ?? null;
assert_true($rule !== null && !empty($rule['id']), 'Created rule has an id', $passed, $failed, $errors);
assert_eq('Gold Tier Test', $rule['name'] ?? null, 'Created rule has correct name', $passed, $failed, $errors);
assert_eq(true, $rule['is_active'] ?? false, 'Created rule is active', $passed, $failed, $errors);
$ruleId = $rule['id'] ?? '';

// Create a second rule for stats testing
$res2 = request('POST', "{$API_BASE}/api/v1/loyalty-discounts", [
    'main_id' => $MAIN_ID,
    'name' => 'Silver Tier Test',
    'description' => 'Test silver tier discount',
    'min_purchase_amount' => 15000,
    'discount_percentage' => 3,
    'evaluation_period' => 'calendar_month',
    'priority' => 5,
]);
$rule2Id = $res2['body']['data']['id'] ?? '';

// Create customer-specific rule
$resCustomer = request('POST', "{$API_BASE}/api/v1/loyalty-discounts", [
    'main_id' => $MAIN_ID,
    'name' => 'VIP Customer Test',
    'description' => 'Assigned customer discount',
    'discount_type' => 'customer_specific',
    'min_purchase_amount' => 0,
    'discount_percentage' => 8,
    'target_customer_ids' => ['VIP-CUST-001'],
    'target_customer_names' => ['VIP Customer'],
    'priority' => 20,
]);
assert_eq(200, $resCustomer['http_code'], 'Create customer-specific rule returns 200', $passed, $failed, $errors);
$customerRuleId = $resCustomer['body']['data']['id'] ?? '';
assert_eq('customer_specific', $resCustomer['body']['data']['discount_type'] ?? null, 'Customer-specific rule stores discount_type', $passed, $failed, $errors);

// Create date-range rule
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$resDate = request('POST', "{$API_BASE}/api/v1/loyalty-discounts", [
    'main_id' => $MAIN_ID,
    'name' => 'Holiday Promo Test',
    'description' => 'Date based discount',
    'discount_type' => 'date_range',
    'min_purchase_amount' => 0,
    'discount_percentage' => 4,
    'start_date' => $today,
    'end_date' => $tomorrow,
    'priority' => 15,
]);
assert_eq(200, $resDate['http_code'], 'Create date-range rule returns 200', $passed, $failed, $errors);
$dateRuleId = $resDate['body']['data']['id'] ?? '';
assert_eq('date_range', $resDate['body']['data']['discount_type'] ?? null, 'Date-range rule stores discount_type', $passed, $failed, $errors);

// ────────────────────────────────────────────────────────────────────────────
// 4. List rules (should contain created rules)
// ────────────────────────────────────────────────────────────────────────────
echo "\n── List rules (after create) ──\n";
$res = request('GET', "{$API_BASE}/api/v1/loyalty-discounts?main_id={$MAIN_ID}");
assert_eq(200, $res['http_code'], 'List returns 200', $passed, $failed, $errors);
$items = $res['body']['data']['items'] ?? [];
assert_true(count($items) >= 2, 'List contains at least 2 rules', $passed, $failed, $errors);

// Check sort order (priority DESC — Gold=10 should come first)
$names = array_map(fn($r) => $r['name'], $items);
$goldIdx = array_search('Gold Tier Test', $names);
$silverIdx = array_search('Silver Tier Test', $names);
assert_true($goldIdx !== false && $silverIdx !== false && $goldIdx < $silverIdx, 'Rules sorted by priority (desc)', $passed, $failed, $errors);

// ────────────────────────────────────────────────────────────────────────────
// 5. Update rule
// ────────────────────────────────────────────────────────────────────────────
echo "\n── Update rule ──\n";
$res = request('PATCH', "{$API_BASE}/api/v1/loyalty-discounts/{$ruleId}", [
    'main_id' => $MAIN_ID,
    'name' => 'Gold Tier Updated',
    'discount_percentage' => 7,
]);
assert_eq(200, $res['http_code'], 'Update rule returns 200', $passed, $failed, $errors);
assert_eq('Gold Tier Updated', $res['body']['data']['name'] ?? null, 'Name updated', $passed, $failed, $errors);
assert_true(abs(($res['body']['data']['discount_percentage'] ?? 0) - 7.0) < 0.01, 'Discount updated', $passed, $failed, $errors);

// Update non-existent
$res = request('PATCH', "{$API_BASE}/api/v1/loyalty-discounts/nonexistent-id-abc", [
    'main_id' => $MAIN_ID,
    'name' => 'Nope',
]);
assert_eq(404, $res['http_code'], 'Update non-existent returns 404', $passed, $failed, $errors);

// ────────────────────────────────────────────────────────────────────────────
// 6. Toggle status
// ────────────────────────────────────────────────────────────────────────────
echo "\n── Toggle status ──\n";
$res = request('PATCH', "{$API_BASE}/api/v1/loyalty-discounts/{$ruleId}/status", [
    'main_id' => $MAIN_ID,
    'is_active' => false,
]);
assert_eq(200, $res['http_code'], 'Toggle status returns 200', $passed, $failed, $errors);
assert_eq(false, $res['body']['data']['is_active'] ?? true, 'Rule is now inactive', $passed, $failed, $errors);

// Re-enable
$res = request('PATCH', "{$API_BASE}/api/v1/loyalty-discounts/{$ruleId}/status", [
    'main_id' => $MAIN_ID,
    'is_active' => true,
]);
assert_eq(200, $res['http_code'], 'Re-enable returns 200', $passed, $failed, $errors);
assert_eq(true, $res['body']['data']['is_active'] ?? false, 'Rule is active again', $passed, $failed, $errors);

// ────────────────────────────────────────────────────────────────────────────
// 7. Stats endpoint
// ────────────────────────────────────────────────────────────────────────────
echo "\n── Stats ──\n";
$res = request('GET', "{$API_BASE}/api/v1/loyalty-discounts/stats?main_id={$MAIN_ID}");
assert_eq(200, $res['http_code'], 'Stats returns 200', $passed, $failed, $errors);
$statsData = $res['body']['data'] ?? [];
assert_true(isset($statsData['total_active_rules']), 'Stats has total_active_rules', $passed, $failed, $errors);
assert_true(isset($statsData['clients_eligible_this_month']), 'Stats has clients_eligible_this_month', $passed, $failed, $errors);
assert_true(array_key_exists('total_discount_given_this_month', $statsData), 'Stats has total_discount_given_this_month', $passed, $failed, $errors);
assert_true(isset($statsData['top_qualifying_clients']), 'Stats has top_qualifying_clients', $passed, $failed, $errors);
assert_eq(0, $statsData['total_discount_given_this_month'] ?? -1, 'Discount given is 0 (no usage log)', $passed, $failed, $errors);

// ────────────────────────────────────────────────────────────────────────────
// 8. Customer active discount
// ────────────────────────────────────────────────────────────────────────────
echo "\n── Customer active discount ──\n";
$res = request('GET', "{$API_BASE}/api/v1/loyalty-discounts/customer/VIP-CUST-001/active-discount?main_id={$MAIN_ID}");
assert_eq(200, $res['http_code'], 'Customer active discount returns 200', $passed, $failed, $errors);
assert_eq(true, $res['body']['data']['qualifies'] ?? false, 'Customer-specific target qualifies', $passed, $failed, $errors);
assert_eq('customer_specific', $res['body']['data']['rule']['discount_type'] ?? null, 'Customer-specific rule is returned', $passed, $failed, $errors);

// ────────────────────────────────────────────────────────────────────────────
// 9. Delete rule (soft delete)
// ────────────────────────────────────────────────────────────────────────────
echo "\n── Delete rule ──\n";
$res = request('DELETE', "{$API_BASE}/api/v1/loyalty-discounts/{$ruleId}?main_id={$MAIN_ID}");
assert_eq(200, $res['http_code'], 'Delete returns 200', $passed, $failed, $errors);
assert_eq(true, $res['body']['data']['deleted'] ?? false, 'Response confirms deletion', $passed, $failed, $errors);

// Deleted rule should no longer appear in list
$res = request('GET', "{$API_BASE}/api/v1/loyalty-discounts?main_id={$MAIN_ID}");
$items = $res['body']['data']['items'] ?? [];
$deletedIds = array_map(fn($r) => $r['id'], $items);
assert_true(!in_array($ruleId, $deletedIds), 'Deleted rule not in list', $passed, $failed, $errors);

// Delete non-existent
$res = request('DELETE', "{$API_BASE}/api/v1/loyalty-discounts/nonexistent-id-abc?main_id={$MAIN_ID}");
assert_eq(404, $res['http_code'], 'Delete non-existent returns 404', $passed, $failed, $errors);

// ────────────────────────────────────────────────────────────────────────────
// 10. Missing main_id validation
// ────────────────────────────────────────────────────────────────────────────
echo "\n── Missing main_id ──\n";
$res = request('GET', "{$API_BASE}/api/v1/loyalty-discounts");
assert_eq(422, $res['http_code'], 'GET without main_id returns 422', $passed, $failed, $errors);

$res = request('GET', "{$API_BASE}/api/v1/loyalty-discounts/stats");
assert_eq(422, $res['http_code'], 'Stats without main_id returns 422', $passed, $failed, $errors);

// ────────────────────────────────────────────────────────────────────────────
// Cleanup: delete second test rule
// ────────────────────────────────────────────────────────────────────────────
if ($rule2Id !== '') {
    request('DELETE', "{$API_BASE}/api/v1/loyalty-discounts/{$rule2Id}?main_id={$MAIN_ID}");
}
if ($customerRuleId !== '') {
    request('DELETE', "{$API_BASE}/api/v1/loyalty-discounts/{$customerRuleId}?main_id={$MAIN_ID}");
}
if ($dateRuleId !== '') {
    request('DELETE', "{$API_BASE}/api/v1/loyalty-discounts/{$dateRuleId}?main_id={$MAIN_ID}");
}

// ────────────────────────────────────────────────────────────────────────────
// Summary
// ────────────────────────────────────────────────────────────────────────────
echo "\n=== Results ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

if ($failed > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
    exit(1);
}

echo "\nAll tests passed!\n";
exit(0);
