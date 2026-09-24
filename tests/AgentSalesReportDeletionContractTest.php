<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string) file_get_contents($root . '/migrations/055_add_agent_sales_report_deletion_audit.sql');
$repository = (string) file_get_contents($root . '/src/Repositories/CallReportRepository.php');
$controller = (string) file_get_contents($root . '/src/Controllers/DailyCallMonitoringController.php');
$bootstrap = (string) file_get_contents($root . '/src/bootstrap.php');

$checks = [
    'migration soft-deletes original reports' => str_contains($migration, 'report_deleted_at') && str_contains($migration, 'report_deleted_by'),
    'migration soft-deletes replies' => str_contains($migration, 'deleted_at') && str_contains($migration, 'deleted_by'),
    'migration preserves immutable audit payload' => str_contains($migration, 'call_report_deletion_audits') && str_contains($migration, 'original_payload JSON NOT NULL'),
    'repository requires a Master User' => str_contains($repository, 'Only the Master User can delete Agent Sales Report messages.'),
    'repository requires a deletion reason' => str_contains($repository, 'A deletion reason of up to 2,000 characters is required.'),
    'repository records original message payload' => str_contains($repository, "'original' => \$record") && str_contains($repository, 'deleted_by_role'),
    'repository excludes deleted replies from the chat' => str_contains($repository, 'call_report_messages WHERE thread_id = :thread_id AND deleted_at IS NULL'),
    'repository excludes deleted reports from the chat' => str_contains($repository, "empty(\$thread['report_deleted_at'])"),
    'controller enforces claimed Master role' => str_contains($controller, "(string) (\$claims['user_type'] ?? '') !== '1'") && str_contains($controller, 'function deleteSalesReportMessage'),
    'router exposes the authenticated deletion endpoint' => str_contains($bootstrap, 'sales-report-messages/{messageId}') && str_contains($bootstrap, "'deleteSalesReportMessage'"),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " {$label}\n";
    if (!$passed) $failed[] = $label;
}

if ($failed !== []) exit(1);
