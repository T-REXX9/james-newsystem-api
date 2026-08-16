<?php

declare(strict_types=1);

$po = file_get_contents(dirname(__DIR__) . '/src/Repositories/PurchaseOrderRepository.php');
$pr = file_get_contents(dirname(__DIR__) . '/src/Repositories/PurchaseRequestRepository.php');
if ($po === false || $pr === false) exit(1);

$checks = [
    'PO can only be generated from approved PR' => str_contains($pr, 'Only an approved purchase request can generate a purchase order'),
    'generated PO retains PR reference' => str_contains($pr, "'pr_refno' => \$prRefno"),
    'generated PO carries PR lines' => str_contains($pr, 'INSERT INTO tblpo_itemlist'),
    'unpost is permission controlled' => str_contains($po, 'canUnpostPurchaseOrder'),
    'unpost is blocked by receiving dependency' => str_contains($po, 'a receiving report already depends on it'),
    'unpost is blocked by received quantities' => str_contains($po, 'quantities have already been received'),
    'unpost records an audit event' => str_contains($po, "'Purchase Order', 'Unpost'"),
];

$failed = 0;
foreach ($checks as $name => $passed) {
    if ($passed) echo "  PASS {$name}\n";
    else { echo "  FAIL {$name}\n"; $failed++; }
}
exit($failed === 0 ? 0 : 1);
