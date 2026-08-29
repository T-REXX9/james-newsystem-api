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

function fetch_all_reorder_products(string $apiBase): array
{
    $page = 1;
    $rows = [];
    do {
        $url = $apiBase . '/api/v1/products?main_id=1&status=active&reorder_only=1&page=' . $page . '&per_page=500';
        $response = reorder_get($url);
        if ($response['code'] !== 200) {
            throw new RuntimeException("Product API returned HTTP {$response['code']} for reorder-only search");
        }
        $data = $response['body']['data'] ?? [];
        $rows = array_merge($rows, is_array($data['items'] ?? null) ? $data['items'] : []);
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $totalPages = max(1, (int) ($meta['total_pages'] ?? 1));
        $page++;
    } while ($page <= $totalPages);

    return $rows;
}

echo "Reorder Report deduplication regression test\n";
$health = reorder_get($apiBase . '/api/v1/health');
reorder_assert($health['code'] === 200, 'API health check', $passed, $failed);
if ($health['code'] !== 200) exit(1);

foreach (['total'] as $warehouse) {
    $result = fetch_all_reorder_rows($apiBase, $warehouse);
    $rows = $result['rows'];
    $sessions = array_map(static fn (array $row): string => trim((string) ($row['product_session'] ?? '')), $rows);
    $nonEmptySessions = array_values(array_filter($sessions, static fn (string $value): bool => $value !== ''));
    $uniqueSessions = array_values(array_unique($nonEmptySessions));

    reorder_assert(count($rows) === $result['reported_total'], strtoupper($warehouse) . ' returns every reported row across pages', $passed, $failed);
    reorder_assert(count($sessions) === count($nonEmptySessions), strtoupper($warehouse) . ' rows all have a canonical product session', $passed, $failed);
    reorder_assert(count($sessions) === count($uniqueSessions), strtoupper($warehouse) . ' contains exactly one row per product', $passed, $failed);

    $negativeAvailableStockRows = array_filter(
        $rows,
        static fn (array $row): bool => (float) ($row['available_stock'] ?? 0) < 0
    );
    reorder_assert(
        $negativeAvailableStockRows === [],
        strtoupper($warehouse) . ' clamps negative available stock to zero',
        $passed,
        $failed
    );

    $availabilityMismatchRows = array_filter(
        $rows,
        static fn (array $row): bool =>
            (float) ($row['available_stock'] ?? 0) !== max(0.0, (float) ($row['current_stock'] ?? 0))
            || (float) ($row['reserved_stock'] ?? 0) !== 0.0
    );
    reorder_assert(
        $availabilityMismatchRows === [],
        strtoupper($warehouse) . ' uses only nonnegative ledger stock for availability',
        $passed,
        $failed
    );

    $atOrAboveReorderRows = array_filter(
        $rows,
        static fn (array $row): bool =>
            (float) ($row['available_stock'] ?? 0) >= (float) ($row['reorder_qty'] ?? 0)
    );
    reorder_assert(
        $atOrAboveReorderRows === [],
        strtoupper($warehouse) . ' only includes stock strictly below reorder quantity',
        $passed,
        $failed
    );

    $completedRows = array_filter(
        $rows,
        static fn (array $row): bool => strtolower(trim((string) ($row['overall_status'] ?? ''))) === 'completed'
    );
    reorder_assert($completedRows === [], strtoupper($warehouse) . ' excludes completed cycles from the active report', $passed, $failed);

    $stagesMatchActiveDocuments = true;
    foreach ($rows as $row) {
        foreach ([
            ['documents' => 'pr_documents', 'refno' => 'pr_refno', 'number' => 'pr_no', 'status' => 'pr_status'],
            ['documents' => 'po_documents', 'refno' => 'po_refno', 'number' => 'po_no', 'status' => 'po_status'],
            ['documents' => 'rr_documents', 'refno' => 'rr_refno', 'number' => 'rr_no', 'status' => 'rr_status'],
        ] as $stage) {
            $documents = is_array($row[$stage['documents']] ?? null) ? $row[$stage['documents']] : [];
            if ($documents !== []) continue;
            if (
                trim((string) ($row[$stage['refno']] ?? '')) !== ''
                || trim((string) ($row[$stage['number']] ?? '')) !== ''
                || trim((string) ($row[$stage['status']] ?? '')) !== ''
            ) {
                $stagesMatchActiveDocuments = false;
                break 2;
            }
        }
    }
    reorder_assert($stagesMatchActiveDocuments, strtoupper($warehouse) . ' never exposes historical documents as active stages', $passed, $failed);

    $needsPrRowsAreClean = true;
    foreach ($rows as $row) {
        if (strtolower(trim((string) ($row['overall_status'] ?? ''))) !== 'needs pr') continue;
        if (
            (is_array($row['pr_documents'] ?? null) && $row['pr_documents'] !== [])
            || (is_array($row['po_documents'] ?? null) && $row['po_documents'] !== [])
            || (is_array($row['rr_documents'] ?? null) && $row['rr_documents'] !== [])
        ) {
            $needsPrRowsAreClean = false;
            break;
        }
    }
    reorder_assert($needsPrRowsAreClean, strtoupper($warehouse) . ' Needs PR rows start with clean PR, PO, and RR stages', $passed, $failed);
}

$reorderProducts = fetch_all_reorder_products($apiBase);
reorder_assert(
    $reorderProducts !== [],
    'purchase-request product search returns eligible low-stock products',
    $passed,
    $failed
);
$invalidReorderProducts = array_filter(
    $reorderProducts,
    static fn (array $row): bool =>
        (float) ($row['reorder_quantity'] ?? 0) <= 0
        || max(0.0, (float) ($row['total_stock'] ?? 0)) >= (float) ($row['reorder_quantity'] ?? 0)
);
reorder_assert(
    $invalidReorderProducts === [],
    'purchase-request product search returns only active stock below a positive reorder quantity',
    $passed,
    $failed
);

$legacyWarehouse = reorder_get($apiBase . '/api/v1/reorder-report?main_id=1&warehouse_type=wh1&page=1&per_page=1');
reorder_assert(
    $legacyWarehouse['code'] === 422,
    'warehouse-split reorder requests are rejected in centralized inventory mode',
    $passed,
    $failed
);

echo "\nPassed: {$passed}; Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
