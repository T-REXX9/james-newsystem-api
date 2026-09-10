<?php

declare(strict_types=1);

/**
 * Contract test for the one-time unnamed customer cleanup migration.
 *
 * Run: php tests/UnnamedCustomerCleanupMigrationTest.php
 */

$migration = file_get_contents(__DIR__ . '/../migrations/046_remove_two_unnamed_customer_records.sql');
if ($migration === false) {
    fwrite(STDERR, "FAIL: migration file could not be read\n");
    exit(1);
}

$assertions = [
    'targets the first known unnamed customer by stable session ID' => str_contains($migration, "'152021011303113643901'") ,
    'targets the second known unnamed customer by stable session ID' => str_contains($migration, "'12202602100417528111'") ,
    'requires both target rows before changing data' => str_contains($migration, '@unnamed_customer_target_count = 2') ,
    'only targets blank company names' => str_contains($migration, "TRIM(COALESCE(lcompany, '')) = ''") ,
    'does not target customer-code-only rows' => str_contains($migration, "TRIM(COALESCE(lpatient_code, '')) = ''") ,
    'uses the established customer soft-delete fields' => str_contains($migration, 'ldeleted = 1') && str_contains($migration, 'ldeleted_at = NOW()') ,
    'does not delete customer ledger rows' => !str_contains(strtolower($migration), 'delete from tblledger') ,
];

$failed = [];
foreach ($assertions as $label => $passed) {
    if ($passed) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failed[] = $label;
    }
}

exit($failed === [] ? 0 : 1);
