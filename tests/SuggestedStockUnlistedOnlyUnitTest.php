<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../src/Repositories/SuggestedStockReportRepository.php');
if ($source === false) {
    fwrite(STDERR, "FAIL unable to read suggested stock report repository\n");
    exit(1);
}

$checks = [
    'unlistedInventoryMatchSql' => 'unlisted inventory exclusion helper must exist',
    'NOT EXISTS' => 'suggested stock queries must exclude inventory soft-matches',
    'clearNotListedRemarks' => 'clearing NotListed remarks after product create must exist',
    "SET i.lremark = 'Listed'" => 'NotListed remarks must be settled to Listed',
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

if (!str_contains($controller, 'function clearNotListed') || !str_contains($bootstrap, 'clear-not-listed')) {
    fwrite(STDERR, "FAIL clear-not-listed endpoint must be wired\n");
    $failed++;
} else {
    echo "  PASS clear-not-listed endpoint is wired\n";
}

if ($failed > 0) {
    echo "Results: " . (count($checks) + count($productChecks) + 1 - $failed) . " passed, {$failed} failed\n";
    exit(1);
}

echo 'Results: ' . (count($checks) + count($productChecks) + 1) . " passed, 0 failed\n";
