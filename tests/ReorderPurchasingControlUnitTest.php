<?php

declare(strict_types=1);

$report = file_get_contents(__DIR__ . '/../src/Repositories/ReorderReportRepository.php');
$products = file_get_contents(__DIR__ . '/../src/Repositories/ProductRepository.php');
$productController = file_get_contents(__DIR__ . '/../src/Controllers/ProductController.php');
$guard = file_get_contents(__DIR__ . '/../src/Repositories/PurchaseWorkflowGuard.php');
$receiving = file_get_contents(__DIR__ . '/../src/Repositories/ReceivingStockRepository.php');

if (!is_string($report) || !is_string($products) || !is_string($productController) || !is_string($guard) || !is_string($receiving)) {
    fwrite(STDERR, "FAIL unable to read purchasing workflow sources\n");
    exit(1);
}

$assertions = [
    'report does not subtract sales reservations from stock' => !str_contains($report, 'reservationSubquery')
        && !str_contains($report, 'AS reserved_qty')
        && !str_contains($report, 'reserved_qty, 0'),
    'report uses nonnegative ledger stock as available stock' => str_contains(
        $report,
        "\$availableExpr = 'GREATEST(COALESCE(st.current_stock, 0), 0)'"
    ),
    'report only includes stock strictly below the reorder quantity' => str_contains($report, "\$availableExpr . ' < ' . \$reorderLevelExpr"),
    'product search supports the same reorder eligibility rule' => str_contains($products, 'if ($reorderOnly)')
        && str_contains($products, "\$reorderLevelExpr . ' > 0'")
        && str_contains($products, "\$availableStockExpr . ' < ' . \$reorderLevelExpr")
        && str_contains($productController, "\$query['reorder_only']")
        && str_contains($productController, '$reorderOnly'),
    'active purchasing documents cannot bypass the stock threshold' => !str_contains($report, '$activeWorkflowExpr'),
    'pending PR lines remain open until linked to a PO' => str_contains($report, "TRIM(COALESCE(pri.lpo_refno, '')) = ''"),
    'only the unreceived PO balance remains on order' => str_contains($report, 'COALESCE(poi.lqty, 0) > COALESCE(poi.lreceiving_qty, 0)'),
    'accepted receiving quantities require a finalized RR status' => str_contains($report, "IN ('posted', 'received', 'delivered', 'completed')"),
    'report exposes the full purchasing-control quantities' => str_contains($report, "'suggested_reorder_qty'")
        && str_contains($report, "'pr_requested_qty'")
        && str_contains($report, "'open_pr_qty'")
        && str_contains($report, "'open_po_qty'")
        && str_contains($report, "'remaining_qty'"),
    'displayed PR quantity includes lines already linked to a PO' => str_contains($report, '$requestedPrQty +=')
        && str_contains($report, "'pr_requested_qty' => \$requestedPrQty"),
    'pending PO quantity remains visible without counting as on order' => strpos($report, '$orderedQty +=') < strpos($report, 'if (!$isOnOrder)'),
    'PO record outstanding quantity remains visible separately from on order' => str_contains($report, '$recordedOutstandingQty +=')
        && str_contains($report, "'remaining_qty' => \$recordedOutstandingQty"),
    'report obtains pagination totals in the same database pass' => str_contains($report, 'COUNT(*) OVER() AS report_total')
        && !str_contains($report, 'SELECT COUNT(*) AS total'),
    'partial receiving still blocks a duplicate PR' => str_contains($guard, 'COALESCE(poi.lqty, 0) > COALESCE(poi.lreceiving_qty, 0)')
        && !str_contains($guard, 'completed_rr'),
    'fully received PO is marked completed' => str_contains($receiving, 'SET ltransaction_status = "Completed"'),
];

$failed = 0;
foreach ($assertions as $message => $condition) {
    if ($condition) {
        echo "  PASS {$message}\n";
        continue;
    }
    $failed++;
    echo "  FAIL {$message}\n";
}

echo 'Results: ' . (count($assertions) - $failed) . ' passed, ' . $failed . " failed\n";
exit($failed === 0 ? 0 : 1);
