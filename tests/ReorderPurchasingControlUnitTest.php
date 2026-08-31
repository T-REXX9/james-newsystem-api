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

$deleteReceivingMethod = '';
$deleteReceivingStart = strpos($receiving, 'public function deleteReceivingStock');
if ($deleteReceivingStart !== false) {
    $deleteReceivingEnd = strpos($receiving, 'public function unpostReceivingStock', $deleteReceivingStart);
    $deleteReceivingMethod = substr(
        $receiving,
        $deleteReceivingStart,
        $deleteReceivingEnd === false ? null : $deleteReceivingEnd - $deleteReceivingStart
    );
}

$assertions = [
    'report does not subtract sales reservations from stock' => !str_contains($report, 'reservationSubquery')
        && !str_contains($report, 'AS reserved_qty')
        && !str_contains($report, 'reserved_qty, 0'),
    'report uses nonnegative ledger stock as available stock' => str_contains(
        $report,
        "\$availableExpr = 'GREATEST(COALESCE(st.current_stock, 0), 0)'"
    ),
    'report includes stock below reorder quantity or with an incomplete PO balance' => str_contains($report, "\$availableExpr . ' < ' . \$reorderLevelExpr . ' OR ' . \$activeOutstandingPoExpr"),
    'product search supports the same reorder eligibility rule' => str_contains($products, 'if ($reorderOnly)')
        && str_contains($products, "\$reorderLevelExpr . ' > 0'")
        && str_contains($products, "\$availableStockExpr . ' < ' . \$reorderLevelExpr")
        && str_contains($productController, "\$query['reorder_only']")
        && str_contains($productController, '$reorderOnly'),
    'incomplete PO balances keep items visible above the stock threshold' => str_contains($report, '$activeOutstandingPoExpr')
        && str_contains($report, 'COALESCE(active_poi.lqty, 0) > COALESCE(active_poi.lreceiving_qty, 0)')
        && str_contains($report, 'COALESCE(active_pol.ldeleted, 0) = 0'),
    'deleted PR, PO, and RR headers do not appear in purchasing control' => str_contains($report, 'COALESCE(prl.ldeleted, 0) = 0')
        && str_contains($report, 'COALESCE(pol.ldeleted, 0) = 0')
        && str_contains($report, 'COALESCE(rr.ldeleted, 0) = 0')
        && str_contains($report, "LOWER(COALESCE(rr.ltransaction_status, 'pending')) <> 'deleted'"),
    'latest RR lookup ignores deleted receiving headers before choosing the latest row' => str_contains($report, 'po_latest.lrefno = pi_latest.lrefno')
        && str_contains($report, 'COALESCE(po_latest.ldeleted, 0) = 0')
        && str_contains($report, "LOWER(COALESCE(po_latest.ltransaction_status, 'pending')) <> 'deleted'"),
    'receiving delete clears reorder report cache immediately' => str_contains($deleteReceivingMethod, '$this->clearReorderReportCache();')
        && strpos($deleteReceivingMethod, 'UPDATE tblpurchase_order SET ldeleted = 1') < strpos($deleteReceivingMethod, '$this->clearReorderReportCache();'),
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
    'partial receipt status reports actual completion progress' => str_contains($report, "'Partially Received — ' . \$completionLabel . '% Complete'"),
    'overdue partial receipt retains its completion progress' => str_contains($report, "'Overdue — ' . \$completionLabel . '% Complete'"),
    'completion progress aggregates every line on the active PO' => str_contains($report, '$this->fetchPoProgressByRefno($mainId, array_keys($openPoRefnos))')
        && str_contains($report, '$poTotalOrderedQty +=')
        && str_contains($report, '$poTotalReceivedQty +=')
        && str_contains($report, '($poTotalReceivedQty / $poTotalOrderedQty) * 100'),
    'report quantity columns use whole PO and RR totals' => str_contains($report, "'po_ordered_qty' => \$poTotalOrderedQty")
        && str_contains($report, "'received_qty' => \$rrTotalReceivedQty")
        && str_contains($report, '$this->fetchRrProgressByRefno($mainId, array_keys($openRrRefnos))'),
    'pending RR status reports entered receiving progress' => str_contains($report, "'RR Pending — ' . \$pendingReceiptLabel . '% Received'"),
    'active PO balance blocks a duplicate PR regardless of status label' => str_contains($report, "'can_create_pr' => !(\$hasPendingPr || \$hasApprovedPr || \$hasPendingPo || \$openPoQty > 0)"),
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
