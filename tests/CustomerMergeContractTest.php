<?php

declare(strict_types=1);

/**
 * Run: php tests/CustomerMergeContractTest.php
 *
 * This is intentionally dependency-light: it protects the merge contract and
 * redirect behavior without touching a tenant database.
 */

require __DIR__ . '/../src/Support/CustomerMergeRedirectResolver.php';

use App\Support\CustomerMergeRedirectResolver;

$migration = file_get_contents(__DIR__ . '/../migrations/062_customer_merge_workflow.sql');
$service = file_get_contents(__DIR__ . '/../src/Services/CustomerMergeService.php');
$controller = file_get_contents(__DIR__ . '/../src/Controllers/CustomerDuplicateRequestController.php');
$workflowController = file_get_contents(__DIR__ . '/../src/Controllers/CustomerWorkflowController.php');
$dailyController = file_get_contents(__DIR__ . '/../src/Controllers/DailyCallMonitoringController.php');
$reportController = file_get_contents(__DIR__ . '/../src/Controllers/SalesReportController.php');
$frontend = file_get_contents(__DIR__ . '/../../james-newsystem/components/Maintenance/Customer/DuplicateCustomersView.tsx');
$frontendEntry = file_get_contents(__DIR__ . '/../../james-newsystem/App.tsx');

foreach (['customer_merge_requests', 'customer_merge_transfer_log', 'customer_merge_redirects', 'field_decisions', 'preview_checksum'] as $needle) {
    if (!is_string($migration) || !str_contains($migration, $needle)) {
        throw new RuntimeException("Migration is missing {$needle}.");
    }
}
foreach (['beginTransaction', 'FOR UPDATE', 'rollBack', 'customer_merge_redirects', 'MERGE CUSTOMER RECORDS', 'INFORMATION_SCHEMA.COLUMNS'] as $needle) {
    if (!is_string($service) || !str_contains($service, $needle)) {
        throw new RuntimeException("Merge service is missing {$needle}.");
    }
}
foreach (['NON_REFERENCE_COLUMNS', 'KNOWN_WORKFLOW_TABLES', 'tblcollection_item', 'tblcredit_memo', 'tblcredit_return_item', 'tblcall_dial_requests', 'tblnotifications', 'json_contact', 'writeRequiredMergeAudit', 'syncPendingDuplicateRequests', 'lockAndValidateDuplicateRequest'] as $needle) {
    if (!is_string($service) || !str_contains($service, $needle)) {
        throw new RuntimeException("Merge service is missing required Phase 2 coverage {$needle}.");
    }
}
$updateSurvivor = is_string($service) ? substr($service, strpos($service, 'private function updateSurvivor'), strpos($service, 'private function retireDuplicate') - strpos($service, 'private function updateSurvivor')) : '';
if (str_contains($updateSurvivor, 'rowCount() !== 1')) {
    throw new RuntimeException('Unchanged survivor names must not fail on a zero-row UPDATE count.');
}
if (!is_string($controller) || !str_contains($controller, 'private readonly CustomerMergeService $mergeService')) {
    throw new RuntimeException('Legacy duplicate approval does not delegate to CustomerMergeService.');
}
foreach ([$workflowController, $dailyController, $reportController] as $redirectAwareController) {
    if (!is_string($redirectAwareController) || !str_contains($redirectAwareController, 'CustomerMergeRedirectResolver')) {
        throw new RuntimeException('A customer-dependent API controller is missing merge redirect handling.');
    }
}
foreach (['explicitly', 'Final company name', 'MERGE CUSTOMER RECORDS', 'blocking_warnings', 'setError'] as $needle) {
    if (!is_string($frontend) || !str_contains($frontend, $needle)) {
        throw new RuntimeException("Duplicate customer UI is missing {$needle}.");
    }
}
if (!is_string($frontendEntry) || !str_contains($frontendEntry, 'DuplicateCustomersView')) {
    throw new RuntimeException('App.tsx does not reference DuplicateCustomersView.');
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE customer_merge_redirects (
        main_id INTEGER NOT NULL,
        old_customer_session_id TEXT NOT NULL,
        surviving_customer_session_id TEXT NOT NULL,
        merge_id INTEGER NOT NULL,
        merged_at TEXT NOT NULL,
        merged_by INTEGER NOT NULL
    )'
);
$pdo->exec("INSERT INTO customer_merge_redirects VALUES (1, 'old-a', 'old-b', 10, '2026-09-30 10:00:00', 7)");
$pdo->exec("INSERT INTO customer_merge_redirects VALUES (1, 'old-b', 'survivor', 11, '2026-09-30 10:01:00', 7)");

$resolved = (new CustomerMergeRedirectResolver($pdo))->resolve(1, 'old-a');
if ($resolved['session_id'] !== 'survivor' || $resolved['redirected'] !== true) {
    throw new RuntimeException('Redirect resolver did not follow the complete merge chain.');
}

$otherTenant = (new CustomerMergeRedirectResolver($pdo))->resolve(2, 'old-a');
if ($otherTenant['session_id'] !== 'old-a' || $otherTenant['redirected'] !== false) {
    throw new RuntimeException('Redirect resolver crossed tenant scope.');
}

echo "PASS: customer merge migration, service contract, legacy adapter, and redirects\n";
