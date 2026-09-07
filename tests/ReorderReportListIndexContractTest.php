<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'migration' => $root . '/migrations/037_optimize_reorder_report_list_indexes.sql',
    'setup' => dirname($root) . '/james-newsystem/setup.sh',
    'report' => $root . '/src/Repositories/ReorderReportRepository.php',
];
foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL missing {$name}\n");
        exit(1);
    }
}

$source = static fn (string $name): string => (string) file_get_contents($files[$name]);
$migration = $source('migration');
$setup = $source('setup');
$report = $source('report');

$existingIndexNames = [
    'idx_reorder_inventory_main_session',
    'idx_reorder_logs_inventory_qty',
    'idx_reorder_transaction_ref_main',
    'idx_reorder_sales_ref_cancel_item',
    'idx_reorder_pr_item_session',
    'idx_reorder_pr_item_code',
    'idx_reorder_pr_list_ref',
    'idx_reorder_po_item_session',
    'idx_reorder_po_item_code',
    'idx_reorder_po_list_ref_main',
    'idx_reorder_rr_item_session',
    'idx_reorder_rr_item_ref',
    'idx_reorder_rr_po_ref',
    'idx_reorder_supplier_item',
    'idx_reorder_return_item_session',
    'idx_reorder_return_inventory_session',
];

$recreatedExisting = [];
foreach ($existingIndexNames as $indexName) {
    if (preg_match('/ADD INDEX\\s+' . preg_quote($indexName, '/') . '\\b/', $migration) === 1) {
        $recreatedExisting[] = $indexName;
    }
}

$checks = [
    'list query still filters deleted inventory items' => str_contains($report, 'COALESCE(itm.ldeleted, 0) = 0'),
    'list query still filters hidden inventory items unless include hidden' => str_contains($report, 'COALESCE(itm.lstatus, 0) = 1'),
    'migration adds inventory-item index for company, deleted, session, then visibility status' => str_contains(
        $migration,
        'ADD INDEX idx_reorder_inventory_main_deleted_status (lmain_id, ldeleted, lsession(64), lstatus, lid)'
    ),
    'migration is idempotent' => str_contains($migration, 'INDEX_NAME')
        && str_contains($migration, 'IF(@idx_exists = 0')
        && !str_contains($migration, 'DROP INDEX')
        && !str_contains($migration, 'DROP TABLE'),
    'migration does not recreate existing reorder indexes' => $recreatedExisting === [],
    'deployment setup applies the list-index migration' => str_contains($setup, '037_optimize_reorder_report_list_indexes.sql'),
];

$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? '  PASS ' : '  FAIL ') . $name . "\n";
    $failed += $passed ? 0 : 1;
}

if ($recreatedExisting !== []) {
    fwrite(STDERR, 'Recreated existing indexes: ' . implode(', ', $recreatedExisting) . "\n");
}

exit($failed === 0 ? 0 : 1);
