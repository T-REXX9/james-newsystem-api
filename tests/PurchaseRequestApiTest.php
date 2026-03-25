<?php

declare(strict_types=1);

/**
 * Purchase Request API regression test.
 *
 * Run:
 *   API_BASE_URL=http://127.0.0.1:8081 php api/tests/PurchaseRequestApiTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$MAIN_ID = 1;
$USER_ID = 1;

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
        CURLOPT_TIMEOUT => 30,
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

function assert_eq(mixed $expected, mixed $actual, string $message, int &$passed, int &$failed, array &$errors): void
{
    assert_true(
        $expected === $actual,
        $message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual),
        $passed,
        $failed,
        $errors
    );
}

function must_find_product(PDO $pdo, int $mainId): array
{
    $stmt = $pdo->prepare(
        'SELECT lsession, COALESCE(litemcode, "") AS litemcode, COALESCE(lpartno, "") AS lpartno, COALESCE(ldescription, "") AS ldescription
         FROM tblinventory_item
         WHERE lmain_id = :main_id AND COALESCE(lnot_inventory, 0) = 0
         ORDER BY lid ASC
         LIMIT 1'
    );
    $stmt->execute(['main_id' => $mainId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('No product found for Purchase Request API tests');
    }
    return $row;
}

function cleanup_pr(PDO $pdo, string $prRefno): void
{
    if ($prRefno === '') {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM tblpr_item WHERE lrefno = :refno');
    $stmt->execute(['refno' => $prRefno]);
    $stmt = $pdo->prepare('DELETE FROM tblpr_list WHERE lrefno = :refno');
    $stmt->execute(['refno' => $prRefno]);
}

function cleanup_po(PDO $pdo, string $poRefno): void
{
    if ($poRefno === '') {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM tblpo_itemlist WHERE lrefno = :refno');
    $stmt->execute(['refno' => $poRefno]);
    $stmt = $pdo->prepare('DELETE FROM tblpo_list WHERE lrefno = :refno');
    $stmt->execute(['refno' => $poRefno]);
}

echo "==========================================================\n";
echo " Purchase Request API Test\n";
echo " Base URL: {$API_BASE}\n";
echo "==========================================================\n\n";

$health = request('GET', "{$API_BASE}/api/v1/health");
assert_eq(200, $health['http_code'], 'Health endpoint returns 200', $passed, $failed, $errors);
assert_eq(true, $health['body']['ok'] ?? false, 'Health response ok=true', $passed, $failed, $errors);
if (($health['http_code'] ?? 0) !== 200) {
    exit(1);
}

$db = new Database(app_config());
$pdo = $db->pdo();
$product = must_find_product($pdo, $MAIN_ID);

$nextMissingMain = request('GET', "{$API_BASE}/api/v1/purchase-requests/next-number");
assert_eq(422, $nextMissingMain['http_code'], 'Next number rejects missing main_id', $passed, $failed, $errors);

$invalidMonth = request('GET', "{$API_BASE}/api/v1/purchase-requests?main_id={$MAIN_ID}&month=13");
assert_eq(422, $invalidMonth['http_code'], 'List rejects invalid month', $passed, $failed, $errors);

$next = request('GET', "{$API_BASE}/api/v1/purchase-requests/next-number?main_id={$MAIN_ID}");
assert_eq(200, $next['http_code'], 'Next number returns 200', $passed, $failed, $errors);
assert_true(((string) ($next['body']['data']['pr_number'] ?? '')) !== '', 'Next number returns a pr_number', $passed, $failed, $errors);

$seed = (string) time();
$crudPrRefno = '';
$convertPrRefno = '';
$convertedPoRefno = '';

try {
    echo "\n--- CRUD flow ---\n";

    $create = request('POST', "{$API_BASE}/api/v1/purchase-requests", [
        'main_id' => $MAIN_ID,
        'user_id' => $USER_ID,
        'pr_number' => "PR-TEST-{$seed}",
        'request_date' => date('Y-m-d'),
        'notes' => 'API purchase request test',
        'reference_no' => "REF-{$seed}",
        'items' => [
            [
                'item_id' => (string) $product['lsession'],
                'item_code' => (string) $product['litemcode'],
                'part_number' => (string) $product['lpartno'],
                'description' => (string) $product['ldescription'],
                'quantity' => 2,
                'unit_cost' => 25.5,
                'eta_date' => '2026-04-05',
            ],
        ],
    ]);
    assert_eq(200, $create['http_code'], 'Create purchase request returns 200', $passed, $failed, $errors);
    $crudPrRefno = (string) ($create['body']['data']['request']['refno'] ?? '');
    assert_true($crudPrRefno !== '', 'Create response includes PR refno', $passed, $failed, $errors);
    assert_eq(1, count($create['body']['data']['items'] ?? []), 'Create response includes one item', $passed, $failed, $errors);

    $show = request('GET', "{$API_BASE}/api/v1/purchase-requests/{$crudPrRefno}?main_id={$MAIN_ID}");
    assert_eq(200, $show['http_code'], 'Show purchase request returns 200', $passed, $failed, $errors);
    assert_eq($crudPrRefno, (string) ($show['body']['data']['request']['refno'] ?? ''), 'Show returns the created purchase request', $passed, $failed, $errors);

    $patch = request('PATCH', "{$API_BASE}/api/v1/purchase-requests/{$crudPrRefno}", [
        'main_id' => $MAIN_ID,
        'notes' => 'Updated purchase request note',
        'reference_no' => "REF-UPD-{$seed}",
        'status' => 'Approved',
    ]);
    assert_eq(200, $patch['http_code'], 'Update purchase request returns 200', $passed, $failed, $errors);
    assert_true(
        str_contains((string) ($patch['body']['data']['request']['notes'] ?? ''), 'REF-UPD-'),
        'Update keeps reference embedded in notes',
        $passed,
        $failed,
        $errors
    );

    $addItem = request('POST', "{$API_BASE}/api/v1/purchase-requests/{$crudPrRefno}/items", [
        'main_id' => $MAIN_ID,
        'user_id' => $USER_ID,
        'item_id' => (string) $product['lsession'],
        'item_code' => (string) $product['litemcode'],
        'part_number' => (string) $product['lpartno'],
        'description' => (string) $product['ldescription'],
        'quantity' => 1,
        'unit_cost' => 30,
        'eta_date' => '2026-04-10',
    ]);
    assert_eq(200, $addItem['http_code'], 'Add purchase request item returns 200', $passed, $failed, $errors);
    $addedItemId = (int) ($addItem['body']['data']['id'] ?? 0);
    assert_true($addedItemId > 0, 'Added item returns a numeric item id', $passed, $failed, $errors);

    $updateItem = request('PATCH', "{$API_BASE}/api/v1/purchase-request-items/{$addedItemId}", [
        'main_id' => $MAIN_ID,
        'quantity' => 4,
        'unit_cost' => 31.75,
        'eta_date' => '2026-04-12',
    ]);
    assert_eq(200, $updateItem['http_code'], 'Update purchase request item returns 200', $passed, $failed, $errors);
    assert_eq('4.00', (string) ($updateItem['body']['data']['quantity'] ?? ''), 'Updated item quantity is persisted', $passed, $failed, $errors);
    assert_eq('2026-04-12', (string) ($updateItem['body']['data']['eta_date'] ?? ''), 'Updated item eta_date is persisted', $passed, $failed, $errors);

    $deleteItem = request('DELETE', "{$API_BASE}/api/v1/purchase-request-items/{$addedItemId}?main_id={$MAIN_ID}");
    assert_eq(200, $deleteItem['http_code'], 'Delete purchase request item returns 200', $passed, $failed, $errors);

    $deleteHeader = request('DELETE', "{$API_BASE}/api/v1/purchase-requests/{$crudPrRefno}?main_id={$MAIN_ID}");
    assert_eq(200, $deleteHeader['http_code'], 'Delete purchase request header returns 200', $passed, $failed, $errors);

    $showDeleted = request('GET', "{$API_BASE}/api/v1/purchase-requests/{$crudPrRefno}?main_id={$MAIN_ID}");
    assert_eq(404, $showDeleted['http_code'], 'Deleted purchase request is no longer available', $passed, $failed, $errors);

    $crudPrRefno = '';

    echo "\n--- Conversion flow ---\n";

    $convertCreate = request('POST', "{$API_BASE}/api/v1/purchase-requests", [
        'main_id' => $MAIN_ID,
        'user_id' => $USER_ID,
        'pr_number' => "PR-CONVERT-{$seed}",
        'request_date' => date('Y-m-d'),
        'notes' => 'Conversion test',
        'items' => [
            [
                'item_id' => (string) $product['lsession'],
                'item_code' => (string) $product['litemcode'],
                'part_number' => (string) $product['lpartno'],
                'description' => (string) $product['ldescription'],
                'quantity' => 1,
                'unit_cost' => 40,
                'eta_date' => '2026-04-20',
            ],
        ],
    ]);
    assert_eq(200, $convertCreate['http_code'], 'Create conversion purchase request returns 200', $passed, $failed, $errors);
    $convertPrRefno = (string) ($convertCreate['body']['data']['request']['refno'] ?? '');
    assert_true($convertPrRefno !== '', 'Conversion PR refno created', $passed, $failed, $errors);

    $convert = request('POST', "{$API_BASE}/api/v1/purchase-requests/{$convertPrRefno}/actions/convert-po", [
        'main_id' => $MAIN_ID,
        'user_id' => $USER_ID,
    ]);
    assert_eq(200, $convert['http_code'], 'Convert to PO returns 200', $passed, $failed, $errors);
    $convertedPoRefno = (string) ($convert['body']['data']['conversion']['po_refno'] ?? '');
    assert_true($convertedPoRefno !== '', 'Convert returns a PO refno', $passed, $failed, $errors);
    assert_eq(1, (int) ($convert['body']['data']['conversion']['converted_count'] ?? 0), 'Convert reports one converted item', $passed, $failed, $errors);

    $convertAgain = request('POST', "{$API_BASE}/api/v1/purchase-requests/{$convertPrRefno}/actions/convert-po", [
        'main_id' => $MAIN_ID,
        'user_id' => $USER_ID,
    ]);
    assert_eq(422, $convertAgain['http_code'], 'Second convert rejects when no convertible items remain', $passed, $failed, $errors);
} finally {
    cleanup_pr($pdo, $crudPrRefno);
    cleanup_pr($pdo, $convertPrRefno);
    cleanup_po($pdo, $convertedPoRefno);
}

echo "\n==========================================================\n";
echo " Passed: {$passed}\n";
echo " Failed: {$failed}\n";
echo "==========================================================\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    exit(1);
}
