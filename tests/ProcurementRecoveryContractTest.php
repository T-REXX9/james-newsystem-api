<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'migration' => $root . '/migrations/019_add_procurement_recovery_columns.sql',
    'po' => $root . '/src/Repositories/PurchaseOrderRepository.php',
    'pr' => $root . '/src/Repositories/PurchaseRequestRepository.php',
    'rr' => $root . '/src/Repositories/ReceivingStockRepository.php',
    'audit' => $root . '/src/Support/AuditTrailWriter.php',
    'setup' => dirname($root) . '/james-newsystem/setup.sh',
];
foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL missing {$name}\n");
        exit(1);
    }
}

$source = fn(string $name): string => (string) file_get_contents($files[$name]);
$checks = [
    'migration is additive only' => str_contains($source('migration'), 'add_procurement_recovery_column') && !str_contains($source('migration'), 'DROP TABLE'),
    'migration stores audit reason and status transition' => str_contains($source('migration'), 'lreason') && str_contains($source('migration'), 'lold_status') && str_contains($source('migration'), 'lnew_status'),
    'PR has unpost action' => str_contains($source('pr'), 'unpostPurchaseRequest'),
    'PR delete is soft delete' => str_contains($source('pr'), 'ldeleted = 1'),
    'PO delete is soft delete' => str_contains($source('po'), 'ldeleted = 1'),
    'RR has unpost action' => str_contains($source('rr'), 'unpostReceivingStock'),
    'RR delete is soft delete' => str_contains($source('rr'), 'ldeleted = 1'),
    'audit accepts reason and statuses' => str_contains($source('audit'), 'reason =') && str_contains($source('audit'), 'oldStatus'),
    'deployment setup applies procurement recovery migration' => str_contains($source('setup'), '019_add_procurement_recovery_columns.sql'),
];
$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? '  PASS ' : '  FAIL ') . $name . "\n";
    $failed += $passed ? 0 : 1;
}
exit($failed === 0 ? 0 : 1);
