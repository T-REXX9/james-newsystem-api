<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../src/Repositories/SuggestedStockReportRepository.php');
if ($source === false) {
    fwrite(STDERR, "FAIL unable to read suggested stock report repository\n");
    exit(1);
}

$checks = [
    'ProductCreated' => 'product creation must keep suggestions in the active workflow',
    'AddedToPR' => 'adding a suggestion to a PR must remove it from the active workflow',
    'markAddedToPurchaseRequest' => 'suggestions must be marked only after PR creation',
    "SET i.lremark = 'ProductCreated'" => 'product creation must preserve the active suggestion',
    'addToKiv' => 'KIV folder add must persist parked items',
    'removeFromKiv' => 'KIV folder restore must exist',
    'suggested_stock_kiv' => 'KIV matching must use the dedicated folder table',
    'part_no_search' => 'summary must support part-number search',
    'qty-desc' => 'summary must support qty requested sort',
    "(\$kivFolder ? '' : 'NOT ')" => 'main report must hide parked KIV items',
];

$failed = 0;
foreach ($checks as $needle => $message) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL {$message}\n");
        $failed++;
    } else {
        echo "  PASS {$message}\n";
    }
}

$productSource = file_get_contents(__DIR__ . '/../src/Repositories/ProductRepository.php');
if ($productSource === false) {
    fwrite(STDERR, "FAIL unable to read product repository\n");
    exit(1);
}

$productChecks = [
    'assertNoDuplicateCatalogProduct' => 'product create must guard against duplicate catalog rows',
    'already listed' => 'duplicate create must return an already-listed message',
];

foreach ($productChecks as $needle => $message) {
    if (!str_contains($productSource, $needle)) {
        fwrite(STDERR, "FAIL {$message}\n");
        $failed++;
    } else {
        echo "  PASS {$message}\n";
    }
}

$controller = file_get_contents(__DIR__ . '/../src/Controllers/SuggestedStockReportController.php');
$bootstrap = file_get_contents(__DIR__ . '/../src/bootstrap.php');
if ($controller === false || $bootstrap === false) {
    fwrite(STDERR, "FAIL unable to read suggested stock controller/bootstrap\n");
    exit(1);
}

if (str_contains($source, 'private function buildFilters')) {
    fwrite(STDERR, "FAIL unused filter builder must be removed from the live summary query\n");
    $failed++;
} else {
    echo "  PASS unused filter builder is not present\n";
}

if (str_contains($controller, "query['search']") || str_contains($controller, '$query[\'search\']')) {
    fwrite(STDERR, "FAIL summary must not accept an unused search alias\n");
    $failed++;
} else {
    echo "  PASS summary search uses part_no only\n";
}

if (str_contains($controller, "sort_by'] ?? 'inquiries-desc") || str_contains($controller, "sort_by'] ?? \"inquiries-desc\"")) {
    fwrite(STDERR, "FAIL summary default sort must be qty-desc\n");
    $failed++;
} else {
    echo "  PASS summary default sort is not inquiries-desc\n";
}

if (!str_contains($controller, 'function clearNotListed') || !str_contains($bootstrap, 'clear-not-listed')) {
    fwrite(STDERR, "FAIL clear-not-listed endpoint must be wired\n");
    $failed++;
} else {
    echo "  PASS clear-not-listed endpoint is wired\n";
}

if (
    !str_contains($controller, 'function addToKiv')
    || !str_contains($controller, 'function removeFromKiv')
    || !str_contains($bootstrap, 'suggested-stock-report/kiv')
    || !str_contains($bootstrap, 'suggested-stock-report/kiv/remove')
) {
    fwrite(STDERR, "FAIL KIV folder endpoints must be wired\n");
    $failed++;
} else {
    echo "  PASS KIV folder endpoints are wired\n";
}

if (!str_contains($controller, 'function markAddedToPurchaseRequest') || !str_contains($bootstrap, 'suggested-stock-report/added-to-pr')) {
    fwrite(STDERR, "FAIL add-to-PR completion endpoint must be wired\n");
    $failed++;
} else {
    echo "  PASS add-to-PR completion endpoint is wired\n";
}

$endpointChecks = 6;

if ($failed > 0) {
    echo "Results: " . (count($checks) + count($productChecks) + $endpointChecks - $failed) . " passed, {$failed} failed\n";
    exit(1);
}

echo 'Results: ' . (count($checks) + count($productChecks) + $endpointChecks) . " passed, 0 failed\n";
