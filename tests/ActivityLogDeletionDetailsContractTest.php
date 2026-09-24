<?php

declare(strict_types=1);

$repository = (string) file_get_contents(__DIR__ . '/../src/Repositories/ActivityLogRepository.php');

$checks = [
    'loads deletion details on demand instead of slowing the Activity Logs list' => str_contains($repository, 'public function deletionDetail') && !str_contains($repository, 'LEFT JOIN call_report_deletion_audits deletion_audit'),
    'returns original deleted-message payload' => str_contains($repository, 'deletion_original_payload'),
    'returns deletion actor and reason' => str_contains($repository, 'deletion_actor_name') && str_contains($repository, 'deletion_reason'),
    'resolves the customer name from the audited customer ID' => str_contains($repository, 'AS deletion_customer_name') && str_contains($repository, 'BINARY customer.lsessionid = BINARY deletion_audit.contact_id'),
    'uses distinct search placeholders for the Activity Logs query' => str_contains($repository, ':search_page') && str_contains($repository, ':search_refno'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " {$label}\n";
    if (!$passed) $failed[] = $label;
}

if ($failed !== []) {
    fwrite(STDERR, "Activity Log deletion-details contract failed.\n");
    exit(1);
}
