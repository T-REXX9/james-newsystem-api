<?php

declare(strict_types=1);

/**
 * Special Price API integration test script.
 *
 * Run:
 *   SPECIAL_PRICE_TEST_EMAIL=... SPECIAL_PRICE_TEST_PASSWORD=... php api/tests/SpecialPriceApiTest.php
 * or
 *   SPECIAL_PRICE_TEST_TOKEN=... php api/tests/SpecialPriceApiTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://localhost:3305', '/');
$passed = 0;
$failed = 0;
$errors = [];

function request(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== null && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

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
        'raw' => $responseBody,
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
    assert_true($expected === $actual, "{$message} (expected " . json_encode($expected) . ', got ' . json_encode($actual) . ')', $passed, $failed, $errors);
}

function decode_token_claims(string $token): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return [];
    }

    $payload = strtr($parts[0], '-_', '+/');
    $padding = 4 - (strlen($payload) % 4);
    if ($padding < 4) {
        $payload .= str_repeat('=', $padding);
    }

    $decoded = json_decode((string) base64_decode($payload), true);
    return is_array($decoded) ? $decoded : [];
}

function fetch_auth_context(string $apiBase): array
{
    $token = trim((string) getenv('SPECIAL_PRICE_TEST_TOKEN'));
    if ($token !== '') {
        $claims = decode_token_claims($token);
        return [
            'token' => $token,
            'main_userid' => (int) ($claims['main_userid'] ?? 0),
        ];
    }

    $email = trim((string) getenv('SPECIAL_PRICE_TEST_EMAIL'));
    $password = (string) getenv('SPECIAL_PRICE_TEST_PASSWORD');
    if ($email === '' || $password === '') {
        fwrite(STDERR, "Set SPECIAL_PRICE_TEST_TOKEN or SPECIAL_PRICE_TEST_EMAIL/SPECIAL_PRICE_TEST_PASSWORD before running this script.\n");
        exit(1);
    }

    $login = request('POST', "{$apiBase}/api/v1/auth/login", [
        'email' => $email,
        'password' => $password,
    ]);

    if (($login['http_code'] ?? 0) !== 200) {
        fwrite(STDERR, "Unable to authenticate for Special Price API tests.\n");
        exit(1);
    }

    return [
        'token' => (string) ($login['body']['data']['token'] ?? ''),
        'main_userid' => (int) ($login['body']['data']['main_userid'] ?? 0),
    ];
}

function must_find_product(PDO $pdo, int $mainId): array
{
    $stmt = $pdo->prepare(
        'SELECT lsession, lid, COALESCE(litemcode, "") AS litemcode, COALESCE(lpartno, "") AS lpartno, COALESCE(ldescription, "") AS ldescription
         FROM tblinventory_item
         WHERE lmain_id = :main_id AND COALESCE(lnot_inventory, 0) = 0
         ORDER BY lid ASC
         LIMIT 1'
    );
    $stmt->execute(['main_id' => $mainId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('No product found for Special Price tests');
    }
    return $row;
}

function ensure_customer(PDO $pdo, int $mainId): array
{
    $stmt = $pdo->prepare(
        'SELECT lsessionid, COALESCE(lcompany, "") AS lcompany, COALESCE(lpatient_code, "") AS lpatient_code
         FROM tblpatient
         WHERE lmain_id = :main_id
         ORDER BY lid ASC
         LIMIT 1'
    );
    $stmt->execute(['main_id' => $mainId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        return $row;
    }

    $seed = (string) time();
    $session = 'sp-customer-' . $seed;
    $patientCode = 'SPC-' . $seed;
    $stmt = $pdo->prepare(
        'INSERT INTO tblpatient (lsessionid, lmain_id, lcompany, lpatient_code)
         VALUES (:session, :main_id, :company, :patient_code)'
    );
    $stmt->execute([
        'session' => $session,
        'main_id' => $mainId,
        'company' => 'Special Price Test Customer',
        'patient_code' => $patientCode,
    ]);

    return [
        'lsessionid' => $session,
        'lcompany' => 'Special Price Test Customer',
        'lpatient_code' => $patientCode,
    ];
}

function ensure_category(PDO $pdo, int $mainId): array
{
    $stmt = $pdo->prepare(
        'SELECT lid, COALESCE(lname, "") AS lname
         FROM tblcategory
         WHERE lmain_id = :main_id
         ORDER BY lid ASC
         LIMIT 1'
    );
    $stmt->execute(['main_id' => $mainId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        return $row;
    }

    $seed = (string) time();
    $stmt = $pdo->prepare(
        'INSERT INTO tblcategory (lmain_id, lname) VALUES (:main_id, :name)'
    );
    $stmt->execute([
        'main_id' => $mainId,
        'name' => 'Special Price Test Category ' . $seed,
    ]);
    $id = (string) $pdo->lastInsertId();

    return ['lid' => $id, 'lname' => 'Special Price Test Category ' . $seed];
}

function must_find_area(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT psgcCode, COALESCE(provDesc, "") AS provDesc FROM refprovince ORDER BY provDesc ASC LIMIT 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('No area found for Special Price tests');
    }
    return $row;
}

echo "==========================================================\n";
echo " Special Price API Integration Tests\n";
echo " Base URL: {$API_BASE}\n";
echo "==========================================================\n\n";

$health = request('GET', "{$API_BASE}/api/v1/health");
assert_eq(200, $health['http_code'], 'Health endpoint returns 200', $passed, $failed, $errors);
if (($health['http_code'] ?? 0) !== 200) {
    exit(1);
}

$auth = fetch_auth_context($API_BASE);
$token = $auth['token'] ?? '';
$mainId = (int) ($auth['main_userid'] ?? 0);
assert_true($token !== '', 'Obtained auth token for test suite', $passed, $failed, $errors);
assert_true($mainId > 0, 'Resolved tenant main_userid from token', $passed, $failed, $errors);

$db = new Database(app_config());
$pdo = $db->pdo();
$product = must_find_product($pdo, $mainId);
$customer = ensure_customer($pdo, $mainId);
$category = ensure_category($pdo, $mainId);
$area = must_find_area($pdo);

$unauthenticated = request('GET', "{$API_BASE}/api/v1/special-prices");
assert_eq(401, $unauthenticated['http_code'], 'Special Price list rejects unauthenticated requests', $passed, $failed, $errors);

$list = request('GET', "{$API_BASE}/api/v1/special-prices?page=1&per_page=5", null, $token);
assert_eq(200, $list['http_code'], 'Special Price list accepts bearer-authenticated requests', $passed, $failed, $errors);
assert_true(isset($list['body']['data']['meta']), 'Special Price list includes pagination metadata', $passed, $failed, $errors);

$createdRefno = null;
$cleanupRefnos = [];

try {
    echo "\n--- CRUD + Detail ---\n";
    $create = request('POST', "{$API_BASE}/api/v1/special-prices", [
        'item_session' => (string) $product['lsession'],
        'type' => 'Fix Amount',
        'amount' => 123.45,
        'main_id' => 999999,
    ], $token);
    assert_eq(200, $create['http_code'], 'Create special price returns 200 and ignores spoofed main_id', $passed, $failed, $errors);
    $createdRefno = (string) ($create['body']['data']['refno'] ?? '');
    $cleanupRefnos[] = $createdRefno;
    assert_true($createdRefno !== '', 'Created special price has a refno', $passed, $failed, $errors);

    $detail = request('GET', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno), null, $token);
    assert_eq(200, $detail['http_code'], 'Detail endpoint returns 200 for created record', $passed, $failed, $errors);
    assert_eq((string) $product['lsession'], (string) ($detail['body']['data']['item_session'] ?? ''), 'Detail payload matches created product session', $passed, $failed, $errors);

    $update = request('PATCH', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno), [
        'type' => 'Percentage',
        'amount' => 5,
        'main_id' => 999999,
    ], $token);
    assert_eq(200, $update['http_code'], 'Update special price returns 200', $passed, $failed, $errors);
    assert_eq('Percentage', (string) ($update['body']['data']['type'] ?? ''), 'Update changes type', $passed, $failed, $errors);

    echo "\n--- Association Validation ---\n";
    $missingCustomer = request('POST', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno) . '/customers', [
        'patient_refno' => 'missing-customer',
    ], $token);
    assert_eq(422, $missingCustomer['http_code'], 'Adding a missing customer fails validation', $passed, $failed, $errors);

    $missingArea = request('POST', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno) . '/areas', [
        'area_code' => 'missing-area',
    ], $token);
    assert_eq(422, $missingArea['http_code'], 'Adding a missing area fails validation', $passed, $failed, $errors);

    $missingCategory = request('POST', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno) . '/categories', [
        'category_id' => 'missing-category',
    ], $token);
    assert_eq(422, $missingCategory['http_code'], 'Adding a missing category fails validation', $passed, $failed, $errors);

    echo "\n--- Associations ---\n";
    $customerAdd = request('POST', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno) . '/customers', [
        'patient_refno' => (string) $customer['lsessionid'],
    ], $token);
    assert_eq(200, $customerAdd['http_code'], 'Add customer returns 200', $passed, $failed, $errors);

    $areaAdd = request('POST', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno) . '/areas', [
        'area_code' => (string) $area['psgcCode'],
    ], $token);
    assert_eq(200, $areaAdd['http_code'], 'Add area returns 200', $passed, $failed, $errors);

    $categoryAdd = request('POST', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno) . '/categories', [
        'category_id' => (string) $category['lid'],
    ], $token);
    assert_eq(200, $categoryAdd['http_code'], 'Add category returns 200', $passed, $failed, $errors);

    echo "\n--- Delete Predicate Regression ---\n";
    $conflictInsert = $pdo->prepare(
        'INSERT INTO tblspecial_price (
            lrefno, litem_id, litem_refno, litem_code, lpart_no, ldesc, ltype, lamount,
            lpatient_refno, larea_code, lcategory, lfilterby
         ) VALUES (
            :refno, :item_id, :item_refno, :item_code, :part_no, :description, :type, :amount,
            :patient_refno, :area_code, :category_id, :filterby
         )'
    );
    $baseInsert = [
        'refno' => $createdRefno,
        'item_id' => (string) $product['lid'],
        'item_refno' => (string) $product['lsession'],
        'item_code' => (string) $product['litemcode'],
        'part_no' => (string) $product['lpartno'],
        'description' => (string) $product['ldescription'],
        'type' => 'Percentage',
        'amount' => 5,
    ];
    $conflictInsert->execute($baseInsert + [
        'patient_refno' => (string) $customer['lsessionid'],
        'area_code' => '',
        'category_id' => '',
        'filterby' => 'Area',
    ]);
    $conflictInsert->execute($baseInsert + [
        'patient_refno' => '',
        'area_code' => (string) $area['psgcCode'],
        'category_id' => '',
        'filterby' => 'Category',
    ]);
    $conflictInsert->execute($baseInsert + [
        'patient_refno' => '',
        'area_code' => '',
        'category_id' => (string) $category['lid'],
        'filterby' => 'Customer',
    ]);

    $customerRemove = request('DELETE', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno) . '/customers/' . rawurlencode((string) $customer['lsessionid']), null, $token);
    assert_eq(200, $customerRemove['http_code'], 'Remove customer returns 200', $passed, $failed, $errors);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tblspecial_price WHERE lrefno = :refno AND lpatient_refno = :patient_refno AND lfilterby = :filterby');
    $countStmt->execute(['refno' => $createdRefno, 'patient_refno' => (string) $customer['lsessionid'], 'filterby' => 'Area']);
    assert_eq(1, (int) $countStmt->fetchColumn(), 'Customer delete preserves rows with same reference under other filters', $passed, $failed, $errors);

    $areaRemove = request('DELETE', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno) . '/areas/' . rawurlencode((string) $area['psgcCode']), null, $token);
    assert_eq(200, $areaRemove['http_code'], 'Remove area returns 200', $passed, $failed, $errors);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tblspecial_price WHERE lrefno = :refno AND larea_code = :area_code AND lfilterby = :filterby');
    $countStmt->execute(['refno' => $createdRefno, 'area_code' => (string) $area['psgcCode'], 'filterby' => 'Category']);
    assert_eq(1, (int) $countStmt->fetchColumn(), 'Area delete preserves rows with same reference under other filters', $passed, $failed, $errors);

    $categoryRemove = request('DELETE', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno) . '/categories/' . rawurlencode((string) $category['lid']), null, $token);
    assert_eq(200, $categoryRemove['http_code'], 'Remove category returns 200', $passed, $failed, $errors);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tblspecial_price WHERE lrefno = :refno AND lcategory = :category_id AND lfilterby = :filterby');
    $countStmt->execute(['refno' => $createdRefno, 'category_id' => (string) $category['lid'], 'filterby' => 'Customer']);
    assert_eq(1, (int) $countStmt->fetchColumn(), 'Category delete preserves rows with same reference under other filters', $passed, $failed, $errors);

    $missingCustomerDelete = request('DELETE', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno) . '/customers/' . rawurlencode((string) $customer['lsessionid']), null, $token);
    assert_eq(404, $missingCustomerDelete['http_code'], 'Removing an already-removed customer returns 404', $passed, $failed, $errors);

    $missingAreaDelete = request('DELETE', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno) . '/areas/' . rawurlencode((string) $area['psgcCode']), null, $token);
    assert_eq(404, $missingAreaDelete['http_code'], 'Removing an already-removed area returns 404', $passed, $failed, $errors);

    $missingCategoryDelete = request('DELETE', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno) . '/categories/' . rawurlencode((string) $category['lid']), null, $token);
    assert_eq(404, $missingCategoryDelete['http_code'], 'Removing an already-removed category returns 404', $passed, $failed, $errors);

    echo "\n--- Delete ---\n";
    $delete = request('DELETE', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno), null, $token);
    assert_eq(200, $delete['http_code'], 'Delete special price returns 200', $passed, $failed, $errors);

    $missingDetail = request('GET', "{$API_BASE}/api/v1/special-prices/" . rawurlencode($createdRefno), null, $token);
    assert_eq(404, $missingDetail['http_code'], 'Deleted special price no longer loads', $passed, $failed, $errors);
} finally {
    foreach ($cleanupRefnos as $refno) {
        if ($refno === '') {
            continue;
        }

        $stmt = $pdo->prepare('DELETE FROM tblspecial_price WHERE lrefno = :refno');
        $stmt->execute([
            'refno' => $refno,
        ]);
    }
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
