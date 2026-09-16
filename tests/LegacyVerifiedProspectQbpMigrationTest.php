<?php

declare(strict_types=1);

/**
 * Contract test for the QBP legacy-prospect source migration.
 *
 * Run: php tests/LegacyVerifiedProspectQbpMigrationTest.php
 */

$migration = file_get_contents(__DIR__ . '/../migrations/052_mark_legacy_verified_no_sale_prospects_qbp.sql');
if ($migration === false) {
    fwrite(STDERR, "FAIL: migration file could not be read\n");
    exit(1);
}

$assertions = [
    'sets the source to QBP' => str_contains($migration, "SET p.lrefer_by = 'QBP'"),
    'limits the change to prospective records' => str_contains($migration, 'COALESCE(p.lstatus, 1) = 3'),
    'requires the imported Verified flag' => str_contains($migration, "LOWER(TRIM(COALESCE(p.lverification, ''))) = 'verified'"),
    'excludes new-system verification audits' => str_contains($migration, "audit.lpage = 'Daily Call Monitoring Dashboard'")
        && str_contains($migration, "audit.laction = 'Verify Prospect'"),
    'excludes ledger sales' => str_contains($migration, 'FROM tblledger ledger'),
    'excludes posted standalone transaction sales' => str_contains($migration, 'FROM tbltransaction transaction_row')
        && str_contains($migration, "IN ('Approved', 'Posted', 'Submitted')"),
    'does not overwrite QBP records on rerun' => str_contains($migration, "COALESCE(p.lrefer_by, '') <> 'QBP'"),
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
