<?php

declare(strict_types=1);

$po = file_get_contents(dirname(__DIR__) . '/src/Repositories/PurchaseOrderRepository.php');
$pr = file_get_contents(dirname(__DIR__) . '/src/Repositories/PurchaseRequestRepository.php');
$poController = file_get_contents(dirname(__DIR__) . '/src/Controllers/PurchaseOrderController.php');
if ($po === false || $pr === false || $poController === false) exit(1);

$checks = [
    'PO can only be generated from approved PR' => str_contains($pr, 'Only an approved purchase request can generate a purchase order'),
    'generated PO retains PR reference' => str_contains($pr, "'pr_refno' => \$prRefno"),
    'generated PO carries PR lines' => str_contains($pr, 'INSERT INTO tblpo_itemlist'),
    'PR recovery names active PO dependencies' => str_contains($pr, 'activePurchaseOrderDependencies')
        && str_contains($pr, 'formatPurchaseOrderDependencies')
        && str_contains($pr, 'Purchase request cannot be unposted because'),
    'unpost is permission controlled' => str_contains($po, 'canUnpostPurchaseOrder'),
    'unpost is blocked by receiving dependency' => str_contains($po, 'activeReceivingDependencies')
        && str_contains($po, 'formatReceivingDependencies')
        && str_contains($po, 'already depends on it'),
    'receiving dependency ignores deleted RR records' => str_contains($po, 'ACTIVE_RECEIVING_DEPENDENCY_SQL')
        && str_contains($po, 'COALESCE(ldeleted, 0) = 0')
        && str_contains($po, 'NOT IN ("cancelled", "canceled", "deleted")'),
    'unpost only blocks received quantities from active RR records' => str_contains($po, 'SUM(poi.lreceiving_qty)')
        && str_contains($po, 'rr.lrefno = poi.lreceiving_refno')
        && str_contains($po, 'COALESCE(rr.ldeleted, 0) = 0')
        && str_contains($po, 'quantities have already been received'),
    'PO list allows all months and years' => str_contains($po, '?int $month = null')
        && str_contains($po, '?int $year = null')
        && str_contains($poController, 'strtolower($monthParam) === \'all\'')
        && str_contains($poController, 'strtolower($yearParam) === \'all\''),
    'unpost records an audit event' => str_contains($po, "'Purchase Order', 'Unpost'"),
];

$failed = 0;
foreach ($checks as $name => $passed) {
    if ($passed) echo "  PASS {$name}\n";
    else { echo "  FAIL {$name}\n"; $failed++; }
}
exit($failed === 0 ? 0 : 1);
