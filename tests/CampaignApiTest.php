<?php

declare(strict_types=1);

/**
 * Campaign API Integration Tests
 *
 * Run: php api/tests/CampaignApiTest.php
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
echo " Campaign API Integration Tests\n";
echo " Base URL: {$API_BASE}\n";
echo "==========================================================\n\n";

echo "--- 1. Health Check ---\n";
$res = request('GET', "{$API_BASE}/api/v1/health");
assert_eq(200, $res['http_code'], 'Health endpoint returns 200', $p, $f, $e);
assert_eq(true, $res['body']['ok'] ?? false, 'Health response ok=true', $p, $f, $e);
if (($res['http_code'] ?? 0) === 0 || ($res['body']['ok'] ?? false) !== true) {
    echo "\n❌ API server not reachable at {$API_BASE}. Aborting tests.\n";
    exit(1);
}

echo "\n--- 2. List Message Templates ---\n";
$res = request('GET', "{$API_BASE}/api/v1/message-templates");
assert_eq(200, $res['http_code'], 'Template list returns 200', $p, $f, $e);
assert_eq(true, $res['body']['ok'] ?? false, 'Template list response ok=true', $p, $f, $e);
assert_true(is_array($res['body']['data']['data'] ?? null), 'Template list has data array', $p, $f, $e);
assert_true(isset($res['body']['data']['pagination']), 'Template list has pagination', $p, $f, $e);

$res = request('GET', "{$API_BASE}/api/v1/message-templates?language=tagalog");
assert_eq(200, $res['http_code'], 'Filtered template list returns 200', $p, $f, $e);
$tagalogTemplates = $res['body']['data']['data'] ?? [];
foreach ($tagalogTemplates as $item) {
    assert_eq('tagalog', $item['language'] ?? '', 'Filtered template language is tagalog', $p, $f, $e);
}

$seededTemplateId = $tagalogTemplates[0]['id'] ?? (($res['body']['data']['data'][0]['id'] ?? null));

echo "\n--- 3. Get Single Template ---\n";
if ($seededTemplateId) {
    $res = request('GET', "{$API_BASE}/api/v1/message-templates/{$seededTemplateId}");
    assert_eq(200, $res['http_code'], 'Get template returns 200', $p, $f, $e);
    assert_true(isset($res['body']['data']['name']), 'Single template includes name', $p, $f, $e);
    assert_true(isset($res['body']['data']['template_type']), 'Single template includes template_type', $p, $f, $e);
}
$res = request('GET', "{$API_BASE}/api/v1/message-templates/999999");
assert_eq(404, $res['http_code'], 'Missing template returns 404', $p, $f, $e);

echo "\n--- 4. Create Message Template ---\n";
$templatePayload = [
    'name' => 'API Test Template',
    'language' => 'english',
    'template_type' => 'follow_up',
    'content' => 'Hello {client_name}, this is an API test template.',
    'variables' => ['client_name'],
];
$res = request('POST', "{$API_BASE}/api/v1/message-templates", $templatePayload);
assert_eq(200, $res['http_code'], 'Create template returns 200', $p, $f, $e);
assert_eq(true, $res['body']['ok'] ?? false, 'Create template response ok=true', $p, $f, $e);
$templateId = $res['body']['data']['id'] ?? null;
assert_true($templateId !== null, 'Created template has an id', $p, $f, $e);

$res = request('POST', "{$API_BASE}/api/v1/message-templates", [
    'language' => 'english',
    'template_type' => 'follow_up',
    'content' => 'Missing name',
]);
assert_eq(422, $res['http_code'], 'Create template missing name returns 422', $p, $f, $e);

echo "\n--- 5. Update Message Template ---\n";
if ($templateId) {
    $res = request('PATCH', "{$API_BASE}/api/v1/message-templates/{$templateId}", [
        'name' => 'API Test Template Updated',
    ]);
    assert_eq(200, $res['http_code'], 'Update template returns 200', $p, $f, $e);
    assert_eq('API Test Template Updated', $res['body']['data']['name'] ?? '', 'Updated template name matches', $p, $f, $e);
}

echo "\n--- 6. Delete Message Template ---\n";
if ($templateId) {
    $res = request('DELETE', "{$API_BASE}/api/v1/message-templates/{$templateId}");
    assert_eq(200, $res['http_code'], 'Delete template returns 200', $p, $f, $e);
    assert_eq(true, $res['body']['data']['ok'] ?? false, 'Delete template response ok=true', $p, $f, $e);

    $res = request('GET', "{$API_BASE}/api/v1/message-templates");
    $items = $res['body']['data']['data'] ?? [];
    $deletedStillListed = false;
    foreach ($items as $item) {
        if (($item['id'] ?? null) === $templateId) {
            $deletedStillListed = true;
            break;
        }
    }
    assert_true(!$deletedStillListed, 'Deleted template no longer appears in list', $p, $f, $e);
}

echo "\n--- 7. Create Campaign Outreach ---\n";
$promotionPayload = [
    'campaign_title' => 'Campaign API Test Promotion',
    'description' => 'Campaign API integration test promotion',
    'end_date' => '2026-12-31 00:00:00',
    'status' => 'Active',
    'assigned_to' => [],
    'target_platforms' => ['Facebook'],
];
$res = request('POST', "{$API_BASE}/api/v1/promotions", $promotionPayload);
assert_eq(200, $res['http_code'], 'Create test promotion returns 200', $p, $f, $e);
$promotionId = $res['body']['data']['id'] ?? null;
assert_true($promotionId !== null, 'Campaign test promotion has an id', $p, $f, $e);

$outreachPayload = [
    'records' => [
        [
            'client_id' => 'client-001',
            'outreach_type' => 'sms',
            'language' => 'tagalog',
            'message_content' => 'Campaign API test message',
            'scheduled_at' => '2026-03-06 08:00:00',
            'created_by' => '1',
        ],
        [
            'client_id' => 'client-002',
            'outreach_type' => 'sms',
            'language' => 'english',
            'message_content' => 'Campaign API test follow-up',
            'scheduled_at' => '2026-03-06 08:05:00',
            'created_by' => '1',
        ],
    ],
];
$res = request('POST', "{$API_BASE}/api/v1/campaigns/{$promotionId}/outreach", $outreachPayload);
assert_eq(200, $res['http_code'], 'Create outreach returns 200', $p, $f, $e);
assert_true(is_array($res['body']['data']['records'] ?? null), 'Create outreach returns records array', $p, $f, $e);
assert_eq(2, $res['body']['data']['created'] ?? 0, 'Create outreach count matches payload', $p, $f, $e);
$outreachId = $res['body']['data']['records'][0]['id'] ?? null;

$res = request('POST', "{$API_BASE}/api/v1/campaigns/{$promotionId}/outreach", [
    'records' => [
        ['message_content' => 'Missing client id'],
    ],
]);
assert_eq(422, $res['http_code'], 'Create outreach missing client_id returns 422', $p, $f, $e);

echo "\n--- 8. List Campaign Outreach ---\n";
$res = request('GET', "{$API_BASE}/api/v1/campaigns/{$promotionId}/outreach");
assert_eq(200, $res['http_code'], 'List outreach returns 200', $p, $f, $e);
assert_true(is_array($res['body']['data']['data'] ?? null), 'List outreach has data array', $p, $f, $e);

$res = request('GET', "{$API_BASE}/api/v1/campaigns/{$promotionId}/outreach?status=pending");
assert_eq(200, $res['http_code'], 'Filtered outreach returns 200', $p, $f, $e);
$pendingItems = $res['body']['data']['data'] ?? [];
foreach ($pendingItems as $item) {
    assert_eq('pending', $item['status'] ?? '', 'Filtered outreach status is pending', $p, $f, $e);
}

echo "\n--- 9. Get Pending Outreach ---\n";
$res = request('GET', "{$API_BASE}/api/v1/outreach/pending");
assert_eq(200, $res['http_code'], 'Pending outreach returns 200', $p, $f, $e);
assert_true(is_array($res['body']['data']['data'] ?? null), 'Pending outreach has data array', $p, $f, $e);

echo "\n--- 10. Update Outreach Status ---\n";
if ($outreachId) {
    $res = request('PATCH', "{$API_BASE}/api/v1/outreach/{$outreachId}", [
        'status' => 'sent',
        'sent_at' => '2026-03-06 08:30:00',
    ]);
    assert_eq(200, $res['http_code'], 'Update outreach status returns 200', $p, $f, $e);
}
$res = request('PATCH', "{$API_BASE}/api/v1/outreach/{$outreachId}", []);
assert_eq(422, $res['http_code'], 'Update outreach missing status returns 422', $p, $f, $e);

echo "\n--- 11. Record Outreach Response ---\n";
if ($outreachId) {
    $res = request('POST', "{$API_BASE}/api/v1/outreach/{$outreachId}/response", [
        'response_content' => 'Interested in learning more.',
        'outcome' => 'interested',
    ]);
    assert_eq(200, $res['http_code'], 'Record outreach response returns 200', $p, $f, $e);
}
$res = request('POST', "{$API_BASE}/api/v1/outreach/{$outreachId}/response", [
    'outcome' => 'interested',
]);
assert_eq(422, $res['http_code'], 'Record outreach response missing content returns 422', $p, $f, $e);

echo "\n--- 12. Create Campaign Feedback ---\n";
$feedbackPayload = [
    'outreach_id' => $outreachId,
    'client_id' => 'client-001',
    'feedback_type' => 'positive',
    'content' => 'Very interested in the offer',
    'sentiment' => 'positive',
    'tags' => ['interested', 'follow-up'],
];
$res = request('POST', "{$API_BASE}/api/v1/campaigns/{$promotionId}/feedback", $feedbackPayload);
assert_eq(200, $res['http_code'], 'Create feedback returns 200', $p, $f, $e);
$feedbackId = $res['body']['data']['id'] ?? null;
assert_true($feedbackId !== null, 'Created feedback has an id', $p, $f, $e);

$res = request('POST', "{$API_BASE}/api/v1/campaigns/{$promotionId}/feedback", [
    'feedback_type' => 'positive',
]);
assert_eq(422, $res['http_code'], 'Create feedback missing content returns 422', $p, $f, $e);

echo "\n--- 13. List Campaign Feedback ---\n";
$res = request('GET', "{$API_BASE}/api/v1/campaigns/{$promotionId}/feedback");
assert_eq(200, $res['http_code'], 'List feedback returns 200', $p, $f, $e);
assert_true(is_array($res['body']['data']['data'] ?? null), 'List feedback has data array', $p, $f, $e);

$res = request('GET', "{$API_BASE}/api/v1/campaigns/{$promotionId}/feedback?sentiment=positive");
assert_eq(200, $res['http_code'], 'Filtered feedback returns 200', $p, $f, $e);
$positiveFeedback = $res['body']['data']['data'] ?? [];
foreach ($positiveFeedback as $item) {
    assert_eq('positive', $item['sentiment'] ?? '', 'Filtered feedback sentiment is positive', $p, $f, $e);
}

echo "\n--- 14. Analyze Campaign Feedback ---\n";
$res = request('GET', "{$API_BASE}/api/v1/campaigns/{$promotionId}/feedback/analysis");
assert_eq(200, $res['http_code'], 'Analyze feedback returns 200', $p, $f, $e);
assert_true(isset($res['body']['data']['total_feedback']), 'Analysis includes total_feedback', $p, $f, $e);
assert_true(isset($res['body']['data']['sentiment_distribution']), 'Analysis includes sentiment_distribution', $p, $f, $e);
assert_true(isset($res['body']['data']['feedback_type_distribution']), 'Analysis includes feedback_type_distribution', $p, $f, $e);
assert_true(isset($res['body']['data']['common_tags']), 'Analysis includes common_tags', $p, $f, $e);

echo "\n--- 15. Campaign Stats ---\n";
$res = request('GET', "{$API_BASE}/api/v1/campaigns/{$promotionId}/stats");
assert_eq(200, $res['http_code'], 'Campaign stats returns 200', $p, $f, $e);
assert_true(isset($res['body']['data']['total_outreach']), 'Stats include total_outreach', $p, $f, $e);
assert_true(isset($res['body']['data']['response_rate']), 'Stats include response_rate', $p, $f, $e);
assert_true(isset($res['body']['data']['conversion_rate']), 'Stats include conversion_rate', $p, $f, $e);
assert_true(isset($res['body']['data']['sentiment_breakdown']), 'Stats include sentiment_breakdown', $p, $f, $e);
assert_true(isset($res['body']['data']['outcome_breakdown']), 'Stats include outcome_breakdown', $p, $f, $e);

echo "\n--- 16. Cleanup ---\n";
if ($promotionId) {
    request('DELETE', "{$API_BASE}/api/v1/promotions/{$promotionId}");
}

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
$dbName = getenv('DB_NAME') ?: 'topnotch';

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName}", $dbUser, $dbPass);
    $pdo->exec("DELETE FROM ai_message_templates WHERE name LIKE '%API Test Template%'");
    echo "  ✅ Campaign test data cleaned up\n";
} catch (\Exception $ex) {
    echo "  ⚠️  Could not clean up campaign test data: {$ex->getMessage()}\n";
}

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
