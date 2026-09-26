<?php

declare(strict_types=1);

/**
 * Regression: the Verify button on the Daily Call unverified list flickered a
 * prospect out then back because verified_in_system stayed 0.
 *
 * The Daily Call master list reads verified_in_system from the presence of a
 * 'Verify Prospect' audit row. The update path used to write that audit ONLY on
 * a text transition (old != 'verified' && new == 'verified'), so a legacy row
 * already labelled 'Verified' in tblpatient but WITHOUT an audit row could never
 * gain one — it stayed Unverified forever.
 *
 * Guard the fixed logic: the audit write must also fire when the record ends up
 * Verified and no 'Verify Prospect' audit exists yet.
 */

$source = file_get_contents(__DIR__ . '/../src/Repositories/CustomerDatabaseRepository.php');

$checks = [
    'has hasVerifyProspectAudit helper' => str_contains($source, 'function hasVerifyProspectAudit'),
    'helper queries the Verify Prospect audit' => str_contains($source, "laction = 'Verify Prospect'"),
    'audit write no longer keys on transition alone' => str_contains(
        $source,
        "\$oldVerification !== 'verified' || !\$this->hasVerifyProspectAudit(\$mainId, \$sessionId)"
    ),
    'still fires only when the record ends up verified' => str_contains(
        $source,
        "if (\$newVerification === 'verified'"
    ),
];

$failed = false;
foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

echo 'Verify Prospect audit-on-missing-audit: ' . count($checks) . '/' . count($checks) . " passed\n";
