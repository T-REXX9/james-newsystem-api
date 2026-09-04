<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/src/Repositories/ReceivingStockRepository.php');
if ($source === false) {
    fwrite(STDERR, "FAIL unable to read ReceivingStockRepository\n");
    exit(1);
}
$controller = file_get_contents(dirname(__DIR__) . '/src/Controllers/ReceivingStockController.php');
if ($controller === false) {
    fwrite(STDERR, "FAIL unable to read ReceivingStockController\n");
    exit(1);
}

$checks = [
    'creation resolves the PO before saving' => 'resolvePurchaseOrder($mainId, $payload)',
    'PO eligibility policy is enforced' => 'assertEligiblePurchaseOrder($purchaseOrder)',
    'each line requires a PO item' => 'resolvePurchaseOrderItem($mainId, $payload)',
    'receiving item stores its PO line reference' => 'lpo_itemid',
    'finalization updates the PO received quantity' => 'lreceiving_qty = LEAST',
    'incomplete delivery finalization requires a reason' => 'PurchaseReceivingPolicy::assertIncompleteDeliveryReason($incompleteDeliveryReason, $hasRemainingPoQty)',
    'incomplete delivery reason is retained on the receiving report' => "'Incomplete delivery reason: ' . \$incompleteDeliveryReason",
    'incomplete delivery can complete the PO without inflating received quantity' => 'if (!$hasRemainingPoQty || $closeRemainingPoQty)',
    'partial delivery never closes remaining PO quantity from the client flag' => 'PurchaseReceivingPolicy::shouldCloseRemainingPoQty($incompleteDeliveryReason)',
    'incomplete delivery reason is saved without requiring PO close' => 'if ($incompleteDeliveryReason !== \'\')',
    'incomplete delivery reason uses a single bound parameter' => 'appendReferenceNote($pdo, \'tblpurchase_order\'',
    'eligible PO list requires a PR link' => "COALESCE(po.lpr_refno, '') <> ''",
    'eligible PO list ignores deleted PO headers' => 'COALESCE(po.ldeleted, 0) = 0',
    'receiving PO resolution ignores deleted PO headers' => 'AND COALESCE(ldeleted, 0) = 0',
    'receiving PO item resolution ignores deleted PO headers' => 'AND COALESCE(po.ldeleted, 0) = 0',
    'eligible PO list only includes remaining quantities' => 'COALESCE(poi.lqty, 0) > COALESCE(poi.lreceiving_qty, 0)',
    'eligible PO ordering is valid with ONLY_FULL_GROUP_BY' => 'ORDER BY MAX(po.lid) DESC',
    'RR unpost names active return-to-supplier dependency' => 'formatReturnToSupplierDependencies($returnDependencies)',
    'item edits require an unposted receiving report' => 'Posted receiving reports must be unposted before items can be edited',
    'item edits refresh the reorder report cache' => str_contains($source, 'function updateReceivingStockItem')
        && substr_count($source, '$this->clearReorderReportCache();') >= 4,
    'RR unpost ignores canceled or deleted return-to-supplier records' => 'NOT IN ("cancelled", "canceled", "deleted")',
    'RR list allows all months and years' => str_contains($source, '?int $month = null')
        && str_contains($source, '?int $year = null')
        && str_contains($controller, 'strtolower($monthParam) === \'all\'')
        && str_contains($controller, 'strtolower($yearParam) === \'all\''),
];

$failed = 0;
foreach ($checks as $name => $needle) {
    $passed = is_bool($needle) ? $needle : str_contains($source, $needle);
    if (!$passed) {
        echo "  FAIL {$name}\n";
        $failed++;
    } else {
        echo "  PASS {$name}\n";
    }
}

exit($failed === 0 ? 0 : 1);
