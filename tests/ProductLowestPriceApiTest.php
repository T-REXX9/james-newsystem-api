<?php

declare(strict_types=1);

/**
 * Product pricing regression test.
 *
 * Run:
 *   API_BASE_URL=http://127.0.0.1:8081 php api/tests/ProductLowestPriceApiTest.php
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$MAIN_ID = 1;
$ROOT = dirname(__DIR__);

$vars = [];
foreach (file($ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $vars[trim($key)] = trim($value);
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $vars['DB_HOST'] ?? 'localhost',
        $vars['DB_PORT'] ?? '3306',
        $vars['DB_NAME'] ?? 'topnotch_migrate'
    ),
    $vars['DB_USER'] ?? 'root',
    $vars['DB_PASS'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

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

function assert_eq(mixed $expected, mixed $actual, string $message, int &$passed, int &$failed, array &$errors): void
{
    if ($expected === $actual) {
        $passed++;
        echo "  PASS {$message}\n";
        return;
    }

    $failed++;
    $errors[] = "{$message} expected=" . json_encode($expected) . ' actual=' . json_encode($actual);
    echo "  FAIL " . end($errors) . "\n";
}

$session = 'codex-lowest-price-' . time();

try {
    $pdo->prepare(
        "INSERT INTO tblinventory_item (
            litemcode, ldescription, ldateadded, laddedby, lstatus, lsession,
            lmain_id, linv_stat, ltrackable, lpartno, lnot_inventory
        ) VALUES (
            :item_code, 'Codex lowest price test product', CURDATE(), 1, 1, :session,
            :main_id, '', 'Yes', :part_no, 0
        )"
    )->execute([
        'item_code' => 'CODEX-LOWEST-PRICE',
        'session' => $session,
        'main_id' => $MAIN_ID,
        'part_no' => 'CODEX-LOWEST-PRICE',
    ]);

    $insertPrice = $pdo->prepare(
        'INSERT INTO tblinventory_price (lrefno, linv_refno, lprice_name, lprice_amt, lprice_amt_old)
         VALUES (:refno, :session, :name, :amount, 0)'
    );
    foreach ([
        ['AAA', '500.00'],
        ['VIP 1', '450.00'],
        ['VIP2', '475.00'],
        ['ADD', '300.00'],
        ['ZERO', '0.00'],
    ] as [$name, $amount]) {
        $insertPrice->execute([
            'refno' => $session . '-' . str_replace(' ', '-', $name),
            'session' => $session,
            'name' => $name,
            'amount' => $amount,
        ]);
    }

    echo "==========================================================\n";
    echo " Product Lowest Price API Test\n";
    echo " Base URL: {$API_BASE}\n";
    echo "==========================================================\n\n";

    $res = request("{$API_BASE}/api/v1/products/{$session}?main_id={$MAIN_ID}");
    assert_eq(200, $res['http_code'], 'Product endpoint returns 200', $passed, $failed, $errors);

    $product = is_array($res['body']['data'] ?? null) ? $res['body']['data'] : [];
    assert_eq(300.0, (float) ($product['price_aa'] ?? -1), 'Regular uses lowest positive product price', $passed, $failed, $errors);
    assert_eq(300.0, (float) ($product['price_vip1'] ?? -1), 'Silver uses lowest positive product price', $passed, $failed, $errors);
    assert_eq(300.0, (float) ($product['price_vip2'] ?? -1), 'Gold uses lowest positive product price', $passed, $failed, $errors);

    $list = request("{$API_BASE}/api/v1/products?main_id={$MAIN_ID}&status=all&search=CODEX-LOWEST-PRICE&page=1&per_page=5");
    assert_eq(200, $list['http_code'], 'Product list endpoint returns 200', $passed, $failed, $errors);
    $listProduct = $list['body']['data']['items'][0] ?? [];
    assert_eq($session, (string) ($listProduct['id'] ?? ''), 'Product list finds seeded product', $passed, $failed, $errors);
    assert_eq(300.0, (float) ($listProduct['price_aa'] ?? -1), 'List Regular uses lowest positive product price', $passed, $failed, $errors);
    assert_eq(300.0, (float) ($listProduct['price_vip1'] ?? -1), 'List Silver uses lowest positive product price', $passed, $failed, $errors);
    assert_eq(300.0, (float) ($listProduct['price_vip2'] ?? -1), 'List Gold uses lowest positive product price', $passed, $failed, $errors);
} finally {
    $pdo->prepare('DELETE FROM tblinventory_price WHERE linv_refno = :session')->execute(['session' => $session]);
    $pdo->prepare('DELETE FROM tblinventory_item WHERE lsession = :session')->execute(['session' => $session]);
}

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
