<?php

declare(strict_types=1);

/**
 * Historical sales-return source-document regression test.
 *
 * Run:
 *   API_BASE_URL=http://127.0.0.1:8081 php tests/SalesReturnSourceDocumentsApiTest.php
 */

$apiBase = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$passed = 0;
$failed = 0;

function source_document_get(string $url): array
{
    $startedAt = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    return [
        'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body' => json_decode((string) $raw, true),
        'error' => curl_error($ch),
        'duration' => microtime(true) - $startedAt,
    ];
}

function source_document_assert(bool $condition, string $message, int &$passed, int &$failed): void
{
    if ($condition) {
        $passed++;
        echo "  PASS {$message}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$message}\n";
}

echo "Sales Return historical source-document regression test\n";
$health = source_document_get($apiBase . '/api/v1/health');
source_document_assert($health['code'] === 200, 'API health check', $passed, $failed);
if ($health['code'] !== 200) {
    exit(1);
}

$response = source_document_get(
    $apiBase . '/api/v1/sales-returns/source-documents?main_id=1&search=D24116&limit=50'
);
$items = is_array($response['body']['data']['items'] ?? null)
    ? $response['body']['data']['items']
    : [];
$match = null;
foreach ($items as $item) {
    if (strcasecmp(trim((string) ($item['doc_no'] ?? '')), 'D24116') === 0) {
        $match = $item;
        break;
    }
}

source_document_assert($response['code'] === 200, 'source-document lookup returns HTTP 200', $passed, $failed);
source_document_assert($response['duration'] < 5, 'historical lookup completes in under five seconds', $passed, $failed);
source_document_assert(is_array($match), 'D24116 is returned by exact document-number search', $passed, $failed);
source_document_assert(($match['type'] ?? '') === 'OR', 'D24116 is identified as an order slip / OR', $passed, $failed);
source_document_assert(trim((string) ($match['id'] ?? '')) !== '', 'D24116 includes its internal source reference', $passed, $failed);
source_document_assert(trim((string) ($match['contact_id'] ?? '')) !== '', 'D24116 includes its customer reference', $passed, $failed);

echo "\nPassed: {$passed}; Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
