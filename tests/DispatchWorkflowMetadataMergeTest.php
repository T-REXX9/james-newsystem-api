<?php

declare(strict_types=1);

$repo = file_get_contents(__DIR__ . '/../src/Repositories/NotificationsRepository.php');
$callRepo = file_get_contents(__DIR__ . '/../src/Repositories/CallReportRepository.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

// The bug: dispatchWorkflow used PHP's `+` union on metadata, which keeps the
// LEFT side values. So caller's `entity_type` was being clobbered by the empty
// default from the dispatcher. The fix is to use array_merge so the caller's
// metadata wins for keys they explicitly set.
$assert(
    !str_contains($repo, "'] + (is_array(\$payload['metadata']"),
    'dispatchWorkflow must not use `+` to merge caller metadata (was clobbering entity_type)'
);
$assert(
    str_contains($repo, 'array_merge(') && str_contains($repo, '$callerMetadata'),
    'dispatchWorkflow must merge caller metadata via array_merge so caller values win'
);

// Call paths still pass the right keys; verify the helper is referenced.
$assert(
    str_contains($callRepo, "'entity_type' => 'call_report_reply'"),
    'notifyMasterOnAgentMessage still stamps entity_type = call_report_reply'
);
$assert(
    str_contains($callRepo, "'entity_type' => 'call_report'"),
    'notifyMasterOnReport still stamps entity_type = call_report'
);

echo "DispatchWorkflow metadata merge contract passed.\n";