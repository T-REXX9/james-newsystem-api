<?php

declare(strict_types=1);

// Contract coverage for every business write route. This intentionally checks
// the router registration itself so a future endpoint cannot silently bypass
// the per-account action policy while the UI still looks permission-aware.
$source = file_get_contents(__DIR__ . '/../src/bootstrap.php');
if ($source === false) {
    throw new RuntimeException('FAIL: unable to read src/bootstrap.php');
}

$expected = [
    '/api/v1/adjustment-entries' => '$requireActionAuth',
    '/api/v1/adjustment-entries/{refno}' => '$requireActionAuth',
    '/api/v1/adjustment-entries/{refno}/actions/{action}' => '$requireApproverAction',
    '/api/v1/collections' => '$requireActionAuth',
    '/api/v1/collections/{collectionRefno}' => '$requireActionAuth',
    '/api/v1/collections/{collectionRefno}/items/post' => '$requireActionAuth',
    '/api/v1/collections/{collectionRefno}/payments' => '$requireActionAuth',
    '/api/v1/collections/{collectionRefno}/actions/{action}' => '$requireApproverAction',
    '/api/v1/collection-items/{itemId}' => '$requireActionAuth',
    '/api/v1/freight-charges' => '$requireActionAuth',
    '/api/v1/freight-charges/{refno}' => '$requireActionAuth',
    '/api/v1/freight-charges/{refno}/actions/{action}' => '$requireApproverAction',
    '/api/v1/recycle-bin/{type}/{itemId}/restore' => '$requireActionAuth',
    '/api/v1/customer-workflows/{contactId}/requests' => '$requireActionAuth',
    '/api/v1/daily-call-monitoring/customer-logs' => '$requireActionAuth',
    '/api/v1/daily-call-monitoring/incident-reports' => '$requireActionAuth',
    '/api/v1/call-system/auto-reply-settings' => '$requireMasterUser',
    '/api/v1/incident-report-items' => '$requireActionAuth',
    '/api/v1/suggested-stock-report/remark' => '$requireActionAuth',
    '/api/v1/reorder-report/hide-items' => '$requireActionAuth',
    '/api/v1/reorder-report/restore-items' => '$requireActionAuth',
    '/api/v1/teams' => '$requireActionAuth',
    '/api/v1/couriers' => '$requireActionAuth',
    '/api/v1/categories' => '$requireActionAuth',
    '/api/v1/remark-templates' => '$requireActionAuth',
    '/api/v1/special-prices' => '$requireActionAuth',
    '/api/v1/campaigns/{campaignId}/outreach' => '$requireActionAuth',
    '/api/v1/outreach/{id}' => '$requireActionAuth',
    '/api/v1/outreach/{id}/response' => '$requireActionAuth',
    '/api/v1/campaigns/{campaignId}/feedback' => '$requireActionAuth',
    '/api/v1/message-templates' => '$requireActionAuth',
    '/api/v1/outreach/queue/process' => '$requireActionAuth',
    '/api/v1/loyalty-discounts' => '$requireActionAuth',
    '/api/v1/profit-protection/threshold' => '$requireActionAuth',
    '/api/v1/profit-protection/overrides' => '$requireActionAuth',
    '/api/v1/profit-protection/admin-overrides' => '$requireMasterUser',
    '/api/v1/vip-tier-settings' => '$requireMasterUser',
    '/api/v1/server-maintenance/automatic-backup' => '$requireMasterUser',
];

foreach ($expected as $route => $wrapper) {
    $line = null;
    foreach (preg_split('/\R/', $source) ?: [] as $candidate) {
        if (preg_match('/\$router->(?:post|patch|put|delete)\(/', $candidate) && str_contains($candidate, "'{$route}'")) {
            $line = $candidate;
            if (str_contains($candidate, $wrapper)) break;
        }
    }

    if ($line === null || !str_contains($line, $wrapper)) {
        throw new RuntimeException("FAIL: {$route} is not protected by {$wrapper}");
    }
}

$expectedViewRoutes = [
    '/api/v1/customer-database' => 'Customer Database',
    '/api/v1/products' => 'Product Database',
    '/api/v1/purchase-requests' => 'Purchase Request',
    '/api/v1/purchase-orders' => 'Purchase Order',
    '/api/v1/receiving-stocks' => 'Receiving Stock',
    '/api/v1/return-to-suppliers' => 'Return to Supplier',
    '/api/v1/order-slips' => 'Order Slip',
    '/api/v1/invoices' => 'Invoice',
    '/api/v1/sales-returns' => 'Sales Return',
    '/api/v1/sales-inquiries' => 'Sales Inquiry',
];

foreach ($expectedViewRoutes as $route => $page) {
    $line = null;
    foreach (preg_split('/\R/', $source) ?: [] as $candidate) {
        if (preg_match('/\$router->get\(/', $candidate) && str_contains($candidate, "'{$route}'")) {
            $line = $candidate;
            if (str_contains($candidate, '$requireViewAuth') && str_contains($candidate, "'{$page}'")) break;
        }
    }

    if ($line === null || !str_contains($line, '$requireViewAuth') || !str_contains($line, "'{$page}'")) {
        throw new RuntimeException("FAIL: {$route} is not protected by View for {$page}");
    }
}

foreach (['submitrecord', 'postrecord', 'posttoledger', 'cancelrecord', 'convert-to-order'] as $actionAlias) {
    if (!str_contains($source, "'{$actionAlias}'")) {
        throw new RuntimeException("FAIL: dynamic action alias {$actionAlias} is not mapped to an action permission");
    }
}

echo 'PASS: business write routes are action-protected' . PHP_EOL;
