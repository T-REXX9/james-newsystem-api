<?php

declare(strict_types=1);

/**
 * Feedback loop for: a staff account with Page Action Permission can_unpost
 * (and without Maintenance Approver listing) must be allowed to unpost.
 *
 * Covers every unpost-capable page: dedicated requireActionAuth routes must
 * win over Approver-gated dynamic {action} routes, and unpost must not appear
 * in the Approver action list.
 */

$source = file_get_contents(__DIR__ . '/../src/bootstrap.php');
if ($source === false) {
    throw new RuntimeException('FAIL: unable to read src/bootstrap.php');
}

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: {$label}");
    }
    echo "PASS: {$label}\n";
};

if (!preg_match(
    '/\$approvalActions\s*=\s*\[(.*?)\];/s',
    $source,
    $matches
)) {
    throw new RuntimeException('FAIL: could not find $approvalActions in requireApproverAction');
}

$listed = [];
if (preg_match_all("/'([^']+)'/", $matches[1], $actionMatches)) {
    $listed = $actionMatches[1];
}

$assert(
    in_array('approve', $listed, true),
    'approve remains an Approver-gated action'
);

$assert(
    in_array('post', $listed, true) || in_array('finalize', $listed, true),
    'post/finalize remain Approver-gated where the old system required Approver'
);

$assert(
    !in_array('unpost', $listed, true),
    'unpost must not require Maintenance Approver listing (Posting permission is enough)'
);

$unpostRoutes = [
    '/api/v1/adjustment-entries/{refno}/actions/unpost' => 'Adjustment Entry',
    '/api/v1/freight-charges/{refno}/actions/unpost' => 'Freight Charges',
    '/api/v1/purchase-requests/{prRefno}/actions/unpost' => 'Purchase Request',
    '/api/v1/purchase-orders/{purchaseRefno}/actions/unpost' => 'Purchase Order',
    '/api/v1/receiving-stocks/{receivingRefno}/actions/unpost' => 'Receiving Stock',
    '/api/v1/return-to-suppliers/{returnRefno}/actions/unpost' => 'Return to Supplier',
    '/api/v1/order-slips/{orderSlipRefno}/actions/unpost' => 'Order Slip',
    '/api/v1/invoices/{invoiceRefno}/actions/unpost' => 'Invoice',
    '/api/v1/sales-returns/{refno}/actions/unpost' => 'Sales Return',
    '/api/v1/sales-orders/{salesRefno}/actions/unpost' => 'Sales Order',
];

foreach ($unpostRoutes as $route => $page) {
    $line = null;
    foreach (preg_split('/\R/', $source) ?: [] as $candidate) {
        if (preg_match('/\$router->post\(/', $candidate) && str_contains($candidate, "'{$route}'")) {
            $line = $candidate;
            break;
        }
    }
    $assert(
        $line !== null && str_contains($line, '$requireActionAuth') && str_contains($line, "'{$page}'") && str_contains($line, "'unpost'"),
        "{$route} uses requireActionAuth for {$page} can_unpost (not Approver listing)"
    );
}

// Dedicated literal unpost routes must be registered before dynamic {action}
// routes so the router matches can_unpost auth first.
$dynamicUnpostParents = [
    '/api/v1/adjustment-entries/{refno}/actions/',
    '/api/v1/freight-charges/{refno}/actions/',
    '/api/v1/purchase-requests/{prRefno}/actions/',
    '/api/v1/return-to-suppliers/{returnRefno}/actions/',
    '/api/v1/order-slips/{orderSlipRefno}/actions/',
    '/api/v1/invoices/{invoiceRefno}/actions/',
    '/api/v1/sales-orders/{salesRefno}/actions/',
];

$lines = preg_split('/\R/', $source) ?: [];
foreach ($dynamicUnpostParents as $prefix) {
    $unpostLine = null;
    $dynamicLine = null;
    foreach ($lines as $index => $candidate) {
        if (!preg_match('/\$router->post\(/', $candidate)) {
            continue;
        }
        if (str_contains($candidate, "'{$prefix}unpost'")) {
            $unpostLine = $index;
        }
        if (str_contains($candidate, "'{$prefix}{action}'")) {
            $dynamicLine = $index;
        }
    }
    $assert(
        $unpostLine !== null && $dynamicLine !== null && $unpostLine < $dynamicLine,
        "{$prefix}unpost is registered before {$prefix}{action}"
    );
}

echo "PASS: ApproverActionUnpostGateTest\n";
