<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * Product Database item-code prefix and running-number API integration test.
 *
 * Uses a real test database and API. Run with the API server available:
 *   API_BASE_URL=http://127.0.0.1:8081 php api/tests/ProductCodeGenerationApiTest.php
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$ROOT = dirname(__DIR__);
$vars = [];
foreach (file($ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $vars[trim($key)] = trim($value);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $vars['DB_HOST'] ?? '127.0.0.1', $vars['DB_PORT'] ?? '3306', $vars['DB_NAME'] ?? 'topnotch_migrate'),
    $vars['DB_USER'] ?? 'root',
    $vars['DB_PASS'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$account = $pdo->prepare('SELECT ltype FROM tblaccount WHERE lid = :id AND COALESCE(lstatus, 0) = 1 LIMIT 1');
$account->execute(['id' => (int) (getenv('PRODUCT_TEST_USER_ID') ?: 1)]);
$accountRow = $account->fetch(PDO::FETCH_ASSOC);
if (!$accountRow) {
    fwrite(STDERR, "PRODUCT_TEST_USER_ID must identify an active API user.\n");
    exit(2);
}
$sessionVersion = 0;
try {
    $version = $pdo->prepare('SELECT lsession_version FROM tblaccount WHERE lid = :id LIMIT 1');
    $version->execute(['id' => (int) (getenv('PRODUCT_TEST_USER_ID') ?: 1)]);
    $sessionVersion = (int) ($version->fetchColumn() ?: 0);
} catch (PDOException) {
    // Older schemas do not track session versions; AuthRepository supports those too.
}
$apiToken = (new App\Security\TokenService((string) ($vars['AUTH_SECRET'] ?? $vars['APP_KEY'] ?? 'change-me-in-env')))->issue([
    'sub' => (int) (getenv('PRODUCT_TEST_USER_ID') ?: 1),
    'main_userid' => (int) (getenv('PRODUCT_TEST_MAIN_ID') ?: 1),
    'user_type' => (string) ($accountRow['ltype'] ?? '1'),
    'session_version' => $sessionVersion,
]);
$GLOBALS['pc_api_token'] = $apiToken;

$mainId = (int) (getenv('PRODUCT_TEST_MAIN_ID') ?: 1);
$userId = (int) (getenv('PRODUCT_TEST_USER_ID') ?: 1);
$prefix = 'UT-QK-' . bin2hex(random_bytes(4)) . '-';
$newPrefix = 'UT-NEW-' . bin2hex(random_bytes(4)) . '-';
$partNos = [$prefix . 'PN-1', $prefix . 'PN-2'];
$sessions = [];
$passed = 0;
$failed = 0;
$errors = [];

function pc_request(string $method, string $url, ?array $body = null): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $GLOBALS['pc_api_token']],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($handle);
    $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    return ['http_code' => $code, 'body' => json_decode((string) $raw, true), 'raw' => (string) $raw, 'error' => $error];
}

function pc_parallel_posts(string $url, array $bodies): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($bodies as $index => $body) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $GLOBALS['pc_api_token']],
            CURLOPT_TIMEOUT => 20,
        ]);
        $handles[$index] = $handle;
        curl_multi_add_handle($multi, $handle);
    }
    do {
        $status = curl_multi_exec($multi, $active);
        if ($active) curl_multi_select($multi, 1.0);
    } while ($active && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $index => $handle) {
        $raw = curl_multi_getcontent($handle);
        $results[$index] = ['http_code' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => json_decode((string) $raw, true)];
        curl_multi_remove_handle($multi, $handle);
    }
    curl_multi_close($multi);
    return $results;
}

function pc_assert(bool $condition, string $message, int &$passed, int &$failed, array &$errors): void
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

try {
    $pdo->prepare('INSERT INTO tblproduct_code (lname) VALUES (:name)')->execute(['name' => $prefix]);
    $pdo->prepare('INSERT INTO tblproduct_code_generator (lcode, lctr) VALUES (:code, 122)')->execute(['code' => $prefix]);

    $options = pc_request('GET', "{$API_BASE}/api/v1/products/create-options?main_id={$mainId}");
    pc_assert($options['http_code'] === 200, 'create-options returns HTTP 200', $passed, $failed, $errors);
    $prefixRows = $options['body']['data']['code_prefixes'] ?? [];
    pc_assert(
        in_array($prefix, array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $prefixRows), true),
        'dropdown options include the existing legacy prefix', $passed, $failed, $errors
    );

    foreach ($partNos as $index => $partNo) {
        $created = pc_request('POST', "{$API_BASE}/api/v1/products", [
            'main_id' => $mainId,
            'user_id' => $userId,
            'product_code_prefix' => $prefix,
            'create_product_code_prefix' => false,
            'part_no' => $partNo,
            'description' => 'Product code generation integration test',
            'replenish_quantity' => 7,
            'record_image' => 'data:image/jpeg;base64,aW1hZ2U=',
            'record_image_position' => '20,70',
            'status' => 'Active',
        ]);
        pc_assert($created['http_code'] === 200, 'product create request succeeds (' . ($index + 1) . ')', $passed, $failed, $errors);
        $product = $created['body']['data'] ?? [];
        $session = (string) ($product['id'] ?? '');
        if ($session !== '') $sessions[] = $session;
        pc_assert(($product['item_code'] ?? null) === $prefix . (123 + $index), 'generated item code advances to ' . $prefix . (123 + $index), $passed, $failed, $errors);
        pc_assert(($product['part_no'] ?? null) === $partNo, 'created item retains its submitted part number', $passed, $failed, $errors);

        if ($session !== '') {
            $saved = $pdo->prepare('SELECT litemcode, lproduct_code, lreplenish, lrecord_image, lrecord_image_position FROM tblinventory_item WHERE lsession = :session AND lmain_id = :main_id');
            $saved->execute(['session' => $session, 'main_id' => $mainId]);
            $row = $saved->fetch(PDO::FETCH_ASSOC) ?: [];
            pc_assert(($row['litemcode'] ?? null) === $prefix . (123 + $index), 'exact legacy litemcode column stores the generated code', $passed, $failed, $errors);
            pc_assert(($row['lproduct_code'] ?? null) === $prefix, 'exact legacy lproduct_code column stores the selected prefix', $passed, $failed, $errors);
            pc_assert((int) ($row['lreplenish'] ?? -1) === 7, 'legacy replenish quantity column stores the submitted value', $passed, $failed, $errors);
            pc_assert(($row['lrecord_image'] ?? null) === 'data:image/jpeg;base64,aW1hZ2U=', 'legacy product image column stores the uploaded image data', $passed, $failed, $errors);
            pc_assert(($row['lrecord_image_position'] ?? null) === '20,70', 'legacy product image position column stores its crop position', $passed, $failed, $errors);
            $reloaded = pc_request('GET', "{$API_BASE}/api/v1/products/" . rawurlencode($session) . "?main_id={$mainId}");
            $reloadedProduct = $reloaded['body']['data'] ?? [];
            pc_assert($reloaded['http_code'] === 200, 'created product reload succeeds', $passed, $failed, $errors);
            pc_assert(($reloadedProduct['record_image'] ?? null) === 'data:image/jpeg;base64,aW1hZ2U=', 'reloaded product returns the saved legacy image', $passed, $failed, $errors);
            pc_assert(($reloadedProduct['record_image_position'] ?? null) === '20,70', 'reloaded product returns its saved crop position', $passed, $failed, $errors);
            if ($index === 0) {
                $updatedImage = 'data:image/jpeg;base64,bmV3LWltYWdl';
                $updated = pc_request('PATCH', "{$API_BASE}/api/v1/products/" . rawurlencode($session), [
                    'main_id' => $mainId,
                    'user_id' => $userId,
                    'record_image' => $updatedImage,
                    'record_image_position' => '80,30',
                ]);
                pc_assert($updated['http_code'] === 200, 'product image update request succeeds', $passed, $failed, $errors);
                $verifyUpdate = $pdo->prepare('SELECT lrecord_image, lrecord_image_position FROM tblinventory_item WHERE lsession = :session AND lmain_id = :main_id');
                $verifyUpdate->execute(['session' => $session, 'main_id' => $mainId]);
                $updatedRow = $verifyUpdate->fetch(PDO::FETCH_ASSOC) ?: [];
                pc_assert(($updatedRow['lrecord_image'] ?? null) === $updatedImage, 'image update writes the exact legacy image column', $passed, $failed, $errors);
                pc_assert(($updatedRow['lrecord_image_position'] ?? null) === '80,30', 'image update writes the exact legacy crop position column', $passed, $failed, $errors);
            }
            $priceCount = $pdo->prepare('SELECT COUNT(*) FROM tblinventory_price WHERE linv_refno = :session');
            $priceCount->execute(['session' => $session]);
            $configuredCount = $pdo->prepare('SELECT COUNT(*) FROM tblinv_pricegroup WHERE lmainid = :main_id');
            $configuredCount->execute(['main_id' => $mainId]);
            pc_assert((int) $priceCount->fetchColumn() === (int) $configuredCount->fetchColumn(), 'one legacy price row is created for every configured group', $passed, $failed, $errors);
            $firstPrice = $pdo->prepare('SELECT lrefno, lprice_amt_old FROM tblinventory_price WHERE linv_refno = :session ORDER BY lid ASC LIMIT 1');
            $firstPrice->execute(['session' => $session]);
            $priceRow = $firstPrice->fetch(PDO::FETCH_ASSOC) ?: [];
            pc_assert(($priceRow['lrefno'] ?? null) === null && ($priceRow['lprice_amt_old'] ?? null) === null, 'new price rows only populate columns used by the legacy insert', $passed, $failed, $errors);
        }
    }

    $counter = $pdo->prepare('SELECT MAX(lctr) FROM tblproduct_code_generator WHERE lcode = :code');
    $counter->execute(['code' => $prefix]);
    pc_assert((int) $counter->fetchColumn() === 124, 'legacy generator table stores the latest running number', $passed, $failed, $errors);

    $parallel = pc_parallel_posts("{$API_BASE}/api/v1/products", array_map(static fn(string $partNo): array => [
        'main_id' => $mainId,
        'user_id' => $userId,
        'product_code_prefix' => $prefix,
        'create_product_code_prefix' => false,
        'part_no' => $partNo,
        'description' => 'Concurrent product code integration test',
        'status' => 'Active',
    ], [$prefix . 'PN-3', $prefix . 'PN-4']));
    $parallelCodes = [];
    foreach ($parallel as $index => $result) {
        pc_assert($result['http_code'] === 200, 'concurrent create request succeeds (' . ($index + 1) . ')', $passed, $failed, $errors);
        $product = $result['body']['data'] ?? [];
        if (!empty($product['id'])) $sessions[] = (string) $product['id'];
        $parallelCodes[] = (string) ($product['item_code'] ?? '');
    }
    sort($parallelCodes);
    pc_assert($parallelCodes === [$prefix . '125', $prefix . '126'], 'concurrent requests receive distinct consecutive numbers (actual ' . json_encode($parallelCodes) . ')', $passed, $failed, $errors);
    $counter->execute(['code' => $prefix]);
    $concurrentCounter = (int) $counter->fetchColumn();
    pc_assert($concurrentCounter === 126, 'generator table preserves the concurrent latest number (actual ' . $concurrentCounter . ')', $passed, $failed, $errors);

    $newPartNo = $newPrefix . 'PN-1';
    $newCreated = pc_request('POST', "{$API_BASE}/api/v1/products", [
        'main_id' => $mainId,
        'user_id' => $userId,
        'product_code_prefix' => $newPrefix,
        'create_product_code_prefix' => true,
        'part_no' => $newPartNo,
        'description' => 'New product prefix integration test',
        'status' => 'Active',
    ]);
    pc_assert($newCreated['http_code'] === 200, 'new product prefix can be created and used', $passed, $failed, $errors);
    $newProduct = $newCreated['body']['data'] ?? [];
    $newSession = (string) ($newProduct['id'] ?? '');
    if ($newSession !== '') $sessions[] = $newSession;
    pc_assert(($newProduct['item_code'] ?? null) === $newPrefix . '1', 'new prefix starts its own running number at 1', $passed, $failed, $errors);
    $newSaved = $pdo->prepare('SELECT litemcode, lproduct_code FROM tblinventory_item WHERE lsession = :session AND lmain_id = :main_id');
    $newSaved->execute(['session' => $newSession, 'main_id' => $mainId]);
    $newRow = $newSaved->fetch(PDO::FETCH_ASSOC) ?: [];
    pc_assert(($newRow['litemcode'] ?? null) === $newPrefix . '1', 'new code is persisted in the legacy item-code column', $passed, $failed, $errors);
    pc_assert(($newRow['lproduct_code'] ?? null) === $newPrefix, 'new prefix is persisted in the legacy product-code column', $passed, $failed, $errors);
} finally {
    foreach ($sessions as $session) {
        $pdo->prepare('DELETE FROM tblinventory_price WHERE linv_refno = ?')->execute([$session]);
        $pdo->prepare('DELETE FROM tblsupplier_cost WHERE litemsession = ?')->execute([$session]);
        $pdo->prepare('DELETE FROM tblinventory_logs WHERE linvent_id = ?')->execute([$session]);
        $pdo->prepare('DELETE FROM tblinventory_item WHERE lsession = ?')->execute([$session]);
    }
    $pdo->prepare('DELETE FROM tblproduct_code_generator WHERE lcode = ?')->execute([$prefix]);
    $pdo->prepare('DELETE FROM tblproduct_code WHERE lname = ?')->execute([$prefix]);
    $pdo->prepare('DELETE FROM tblproduct_code_generator WHERE lcode = ?')->execute([$newPrefix]);
    $pdo->prepare('DELETE FROM tblproduct_code WHERE lname = ?')->execute([$newPrefix]);
}

echo "\nProduct code API integration results: {$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    foreach ($errors as $error) echo " - {$error}\n";
    exit(1);
}
exit(0);
