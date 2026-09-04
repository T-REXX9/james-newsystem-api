<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/PurchaseReceivingPolicy.php';

use App\Support\PurchaseReceivingPolicy;

$passed = 0;
$failed = 0;

$expectPass = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try { $test(); echo "  PASS {$name}\n"; $passed++; }
    catch (Throwable $e) { echo "  FAIL {$name}: {$e->getMessage()}\n"; $failed++; }
};
$expectFail = static function (string $name, string $message, callable $test) use (&$passed, &$failed): void {
    try { $test(); echo "  FAIL {$name}: no exception\n"; $failed++; }
    catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), $message)) { echo "  PASS {$name}\n"; $passed++; }
        else { echo "  FAIL {$name}: {$e->getMessage()}\n"; $failed++; }
    }
};

$expectPass('posted PO linked to PR is eligible', fn () => PurchaseReceivingPolicy::assertEligiblePurchaseOrder([
    'status' => 'Posted', 'pr_refno' => 'pr-session',
]));
$expectFail('unposted PO is rejected', 'posted purchase order', fn () => PurchaseReceivingPolicy::assertEligiblePurchaseOrder([
    'status' => 'Pending', 'pr_refno' => 'pr-session',
]));
$expectFail('PO without PR is rejected', 'purchase request', fn () => PurchaseReceivingPolicy::assertEligiblePurchaseOrder([
    'status' => 'Posted', 'pr_refno' => '', 'pr_number' => '',
]));
$expectPass('partial remaining quantity is accepted', fn () => PurchaseReceivingPolicy::assertReceivableLine([
    'qty' => 10, 'receiving_qty' => 4,
], 6));
$expectFail('over-receiving is rejected', 'remaining purchase order quantity (6)', fn () => PurchaseReceivingPolicy::assertReceivableLine([
    'qty' => 10, 'receiving_qty' => 4,
], 7));
$expectFail('fully received line is rejected', 'already fully received', fn () => PurchaseReceivingPolicy::assertReceivableLine([
    'qty' => 10, 'receiving_qty' => 10,
], 1));
$expectPass('complete delivery does not require an incomplete-delivery reason', fn () => PurchaseReceivingPolicy::assertIncompleteDeliveryReason('', false));
$expectFail('incomplete delivery requires a reason', 'Select a reason for the incomplete delivery', fn () => PurchaseReceivingPolicy::assertIncompleteDeliveryReason('', true));
$expectFail('incomplete delivery rejects unknown reasons', 'Select a valid reason', fn () => PurchaseReceivingPolicy::assertIncompleteDeliveryReason('short receipt', true));
$expectPass('partial delivery is an allowed incomplete-delivery reason', fn () => PurchaseReceivingPolicy::assertIncompleteDeliveryReason(
    PurchaseReceivingPolicy::PARTIAL_DELIVERY_REASON,
    true
));
$expectPass('partial delivery keeps remaining PO quantity open', function (): void {
    if (PurchaseReceivingPolicy::shouldCloseRemainingPoQty(PurchaseReceivingPolicy::PARTIAL_DELIVERY_REASON)) {
        throw new RuntimeException('partial delivery closed remaining quantity');
    }
});
$expectPass('factory out of stock closes remaining PO quantity', function (): void {
    if (!PurchaseReceivingPolicy::shouldCloseRemainingPoQty('Factory out of stock — unable to complete the full delivery')) {
        throw new RuntimeException('factory out of stock left remaining quantity open');
    }
});

echo "\nPassed: {$passed}; Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
