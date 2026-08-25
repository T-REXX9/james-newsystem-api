<?php

declare(strict_types=1);

$report = file_get_contents(__DIR__ . '/../src/Repositories/ReorderReportRepository.php');
$guard = file_get_contents(__DIR__ . '/../src/Repositories/PurchaseWorkflowGuard.php');
$receiving = file_get_contents(__DIR__ . '/../src/Repositories/ReceivingStockRepository.php');

if (!is_string($report) || !is_string($guard) || !is_string($receiving)) {
    fwrite(STDERR, "FAIL unable to read purchasing workflow sources\n");
    exit(1);
}

$assertions = [
    'report calculates committed sales stock' => str_contains($report, 'AS reserved_qty'),
    'report includes the reorder threshold itself' => str_contains($report, "<= ' . \$targetExpr"),
    'pending PR lines remain open until linked to a PO' => str_contains($report, "TRIM(COALESCE(active_pr_item.lpo_refno, '')) = ''"),
    'only the unreceived PO balance remains on order' => str_contains($report, 'COALESCE(active_po_item.lqty, 0) > COALESCE(active_po_item.lreceiving_qty, 0)'),
    'accepted receiving quantities require a finalized RR status' => str_contains($report, "IN ('posted', 'received', 'delivered', 'completed')"),
    'report exposes the full purchasing-control quantities' => str_contains($report, "'suggested_reorder_qty'")
        && str_contains($report, "'open_pr_qty'")
        && str_contains($report, "'open_po_qty'")
        && str_contains($report, "'remaining_qty'"),
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
