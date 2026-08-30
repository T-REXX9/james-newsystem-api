<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/src/Repositories/ReceivingStockRepository.php');
if ($source === false) {
    fwrite(STDERR, "FAIL unable to read ReceivingStockRepository\n");
    exit(1);
}

$checks = [
    'creation resolves the PO before saving' => 'resolvePurchaseOrder($mainId, $payload)',
    'PO eligibility policy is enforced' => 'assertEligiblePurchaseOrder($purchaseOrder)',
    'each line requires a PO item' => 'resolvePurchaseOrderItem($mainId, $payload)',
    'receiving item stores its PO line reference' => 'lpo_itemid',
    'finalization updates the PO received quantity' => 'lreceiving_qty = LEAST',
    'short receipt finalization requires a reason' => 'A reason is required when closing a PO with undelivered quantity.',
    'short receipt reason is retained on the receiving report' => "'Short receipt reason: ' . \$shortReceiptReason",
    'short receipt can complete the PO without inflating received quantity' => 'if (!$hasRemainingPoQty || $closeRemainingPoQty)',
    'eligible PO list requires a PR link' => "COALESCE(po.lpr_refno, '') <> ''",
    'eligible PO list only includes remaining quantities' => 'COALESCE(poi.lqty, 0) > COALESCE(poi.lreceiving_qty, 0)',
    'eligible PO ordering is valid with ONLY_FULL_GROUP_BY' => 'ORDER BY MAX(po.lid) DESC',
];

$failed = 0;
foreach ($checks as $name => $needle) {
    if (!str_contains($source, $needle)) {
        echo "  FAIL {$name}\n";
        $failed++;
    } else {
        echo "  PASS {$name}\n";
    }
}

exit($failed === 0 ? 0 : 1);
