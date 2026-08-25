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
