<?php

declare(strict_types=1);

/**
 * Customer database contacts regression test.
 *
 * Run:
 *   API_BASE_URL=http://127.0.0.1:8081 php api/tests/CustomerDatabaseContactsApiTest.php
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$MAIN_ID = 1;

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

function assert_true(bool $condition, string $message, array &$passed, array &$failed, array &$errors): void
{
    if ($condition) {
        $passed[0]++;
        echo "  PASS {$message}\n";
        return;
    }

    $failed[0]++;
    $errors[] = $message;
    echo "  FAIL {$message}\n";
}

function assert_eq(mixed $expected, mixed $actual, string $message, array &$passed, array &$failed, array &$errors): void
{
    assert_true($expected === $actual, $message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual), $passed, $failed, $errors);
}

$p = [&$passed];
$f = [&$failed];
$e = &$errors;

echo "==========================================================\n";
echo " Customer Database Contacts API Test\n";
echo " Base URL: {$API_BASE}\n";
echo "==========================================================\n\n";

$list = request('GET', "{$API_BASE}/api/v1/customer-database?main_id={$MAIN_ID}&status=all&page=1&per_page=25");
assert_eq(200, $list['http_code'], 'Customer database returns 200', $p, $f, $e);
assert_eq(true, $list['body']['ok'] ?? false, 'Customer database ok=true', $p, $f, $e);

$items = $list['body']['data']['items'] ?? [];
assert_true(is_array($items) && count($items) > 0, 'Customer database returns at least one customer', $p, $f, $e);

$firstCustomer = is_array($items[0] ?? null) ? $items[0] : null;
assert_true(is_array($firstCustomer), 'Customer database returned a first customer record', $p, $f, $e);

if (is_array($firstCustomer)) {
    $sessionId = (string) ($firstCustomer['session_id'] ?? '');
    assert_true($sessionId !== '', 'First customer includes a session_id', $p, $f, $e);

    if ($sessionId !== '') {
        $detail = request('GET', "{$API_BASE}/api/v1/customers/{$sessionId}");
        assert_eq(200, $detail['http_code'], 'Customer detail returns 200', $p, $f, $e);

        $detailData = $detail['body']['data'] ?? null;
        $listBalance = (float) ($firstCustomer['latest_balance'] ?? 0);
        $detailBalance = (float) ($detailData['latest_balance'] ?? 0);

        assert_eq(
            $detailBalance,
            $listBalance,
            'Customer database balance matches customer detail balance',
            $p,
            $f,
            $e
        );
    }
}

$customerWithContacts = null;
$namedContact = null;
foreach ($items as $item) {
    $contacts = $item['contacts'] ?? null;
    if (is_array($contacts) && count($contacts) > 0) {
        $customerWithContacts = $item;
        foreach ($contacts as $contact) {
            $hasName = trim((string) (($contact['lfname'] ?? '') ?: ($contact['llname'] ?? ''))) !== '';
            if ($hasName) {
                $namedContact = $contact;
                break 2;
            }
        }
    }
}

assert_true($customerWithContacts !== null, 'At least one listed customer includes contacts payload', $p, $f, $e);
assert_true(is_array($namedContact), 'At least one listed contact includes a legacy name field', $p, $f, $e);

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
