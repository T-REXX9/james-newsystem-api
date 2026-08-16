<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../src/Repositories/CustomerDatabaseRepository.php');

$checks = [
    'verification compares previous state' => str_contains($source, '$oldVerification'),
    'verified prospect action is named consistently' => str_contains($source, "'Verify Prospect'"),
    'rejected prospect action is named consistently' => str_contains($source, "'Reject Prospect - Blacklisted'"),
    'dashboard page is captured' => str_contains($source, "'Daily Call Monitoring Dashboard'"),
    'acting user is written to audit trail' => str_contains($source, '$auditUserId'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Customer verification audit contract: ' . count($checks) . "/" . count($checks) . " passed\n";
