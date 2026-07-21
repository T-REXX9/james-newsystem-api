<?php

declare(strict_types=1);

/**
 * Reorder Report canonical-row regression test.
 *
 * Run:
 *   API_BASE_URL=http://127.0.0.1:8081 php tests/ReorderReportDeduplicationApiTest.php
 */

$apiBase = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$passed = 0;
$failed = 0;

function reorder_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    return [
        'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body' => json_decode((string) $raw, true),
        'error' => curl_error($ch),
    ];
}

function reorder_assert(bool $condition, string $message, int &$passed, int &$failed): void
{
    if ($condition) {
        $passed++;
        echo "  PASS {$message}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$message}\n";
}

function fetch_all_reorder_rows(string $apiBase, string $warehouse): array
{
    $page = 1;
    $rows = [];
    $reportedTotal = 0;
    do {
        $url = $apiBase . '/api/v1/reorder-report?main_id=1&warehouse_type=' . rawurlencode($warehouse)
            . '&page=' . $page . '&per_page=500';
        $response = reorder_get($url);
        if ($response['code'] !== 200) {
            throw new RuntimeException("Reorder API returned HTTP {$response['code']} for {$warehouse}");
        }
        $data = $response['body']['data'] ?? [];
        $rows = array_merge($rows, is_array($data['items'] ?? null) ? $data['items'] : []);
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $reportedTotal = (int) ($meta['total'] ?? 0);
        $totalPages = max(1, (int) ($meta['total_pages'] ?? 1));
        $page++;
    } while ($page <= $totalPages);

    return ['rows' => $rows, 'reported_total' => $reportedTotal];
}

echo "Reorder Report deduplication regression test\n";
$health = reorder_get($apiBase . '/api/v1/health');
reorder_assert($health['code'] === 200, 'API health check', $passed, $failed);
if ($health['code'] !== 200) exit(1);

foreach (['total', 'wh1'] as $warehouse) {
    $result = fetch_all_reorder_rows($apiBase, $warehouse);
    $rows = $result['rows'];
    $sessions = array_map(static fn (array $row): string => trim((string) ($row['product_session'] ?? '')), $rows);
    $nonEmptySessions = array_values(array_filter($sessions, static fn (string $value): bool => $value !== ''));
    $uniqueSessions = array_values(array_unique($nonEmptySessions));

    reorder_assert(count($rows) === $result['reported_total'], strtoupper($warehouse) . ' returns every reported row across pages', $passed, $failed);
    reorder_assert(count($sessions) === count($nonEmptySessions), strtoupper($warehouse) . ' rows all have a canonical product session', $passed, $failed);
    reorder_assert(count($sessions) === count($uniqueSessions), strtoupper($warehouse) . ' contains exactly one row per product', $passed, $failed);
}

echo "\nPassed: {$passed}; Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
