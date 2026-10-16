<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string) file_get_contents($root . '/migrations/055_add_agent_sales_report_deletion_audit.sql');
$editHistoryMigration = (string) file_get_contents($root . '/migrations/070_add_agent_sales_report_edit_history.sql');
$repository = (string) file_get_contents($root . '/src/Repositories/CallReportRepository.php');
$controller = (string) file_get_contents($root . '/src/Controllers/DailyCallMonitoringController.php');
$bootstrap = (string) file_get_contents($root . '/src/bootstrap.php');

$checks = [
    'migration soft-deletes original reports' => str_contains($migration, 'report_deleted_at') && str_contains($migration, 'report_deleted_by'),
    'migration soft-deletes replies' => str_contains($migration, 'deleted_at') && str_contains($migration, 'deleted_by'),
    'migration preserves immutable audit payload' => str_contains($migration, 'call_report_deletion_audits') && str_contains($migration, 'original_payload JSON NOT NULL'),
    'repository requires a Master User' => str_contains($repository, 'Only the Master User can delete Agent Sales Report messages.'),
    'repository requires a deletion reason' => str_contains($repository, 'A deletion reason of up to 2,000 characters is required.'),
    'repository rejects non-canonical message identifiers' => str_contains($repository, "preg_match('/^[1-9][0-9]*$/D', \$rawRecordId) !== 1"),
    'repository records original message payload and customer name' => str_contains($repository, "'original' => \$record") && str_contains($repository, "'customer_name' => \$customerName") && str_contains($repository, 'deleted_by_role'),
    'repository seals deleted report threads against new replies' => str_contains($repository, 'A deleted Agent Sales Report cannot receive new replies.'),
    'repository writes a reviewable central Activity Log audit in the deletion transaction' => str_contains($repository, 'INSERT INTO tblaudit_trail') && str_contains($repository, 'call-report-deletion-audit:'),
    'repository notifies the affected sales agent in the deletion transaction' => str_contains($repository, 'call-report-deletion-notification:') && str_contains($repository, "'agent_sales_report_deletion'") && str_contains($repository, 'Agent Sales Report Deleted'),
    'repository excludes deleted replies from the chat' => str_contains($repository, 'call_report_messages WHERE thread_id = :thread_id AND deleted_at IS NULL'),
    'repository excludes deleted reports from the chat' => str_contains($repository, "empty(\$thread['report_deleted_at'])"),
    'thread mapping retains the report deletion state across reloads' => str_contains($repository, "'report_deleted_at' => \$row['report_deleted_at'] ?? null"),
    'controller enforces claimed Master role' => str_contains($controller, "(string) (\$claims['user_type'] ?? '') !== '1'") && str_contains($controller, 'function deleteSalesReportMessage'),
    'router exposes the authenticated deletion endpoint' => str_contains($bootstrap, 'sales-report-messages/{messageId}') && str_contains($bootstrap, "'deleteSalesReportMessage'"),
    'edit endpoint requires authenticated non-master agent' => str_contains($controller, 'function updateSalesReportMessage') && str_contains($controller, 'Only sales agents can edit their own Agent Sales Report messages.'),
    'edit endpoint is registered as an authenticated patch' => str_contains($bootstrap, "'updateSalesReportMessage'"),
    'repository edits report bodies only for their owning agent' => str_contains($repository, 'SET report_body = :body') && str_contains($repository, '(int) ($record[\'agent_user_id\'] ?? 0) !== $userId'),
    'repository edits replies only for their agent author' => str_contains($repository, 'SET body = :body, edited_at = NOW() WHERE id = :id') && str_contains($repository, '(string) ($record[\'sender_role\'] ?? \'\') !== \'agent\''),
    'repository enforces a 24-hour edit window' => str_contains($repository, 'TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_seconds') && str_contains($repository, '(int) $record[\'age_seconds\'] > 86400'),
    'repository rejects legacy and unknown message identifiers' => str_contains($repository, 'Only persisted agent messages can be edited.'),
    'edit history migration stores previous bodies and agent attribution' => str_contains($editHistoryMigration, 'previous_body TEXT NOT NULL') && str_contains($editHistoryMigration, 'actor_user_id INT NOT NULL') && str_contains($editHistoryMigration, 'edited_at DATETIME NOT NULL'),
    'edits record history and update the message in one transaction' => str_contains($repository, 'recordConversationMessageEdit($pdo') && str_contains($repository, 'SET report_body = :body, report_edited_at = NOW()') && str_contains($repository, 'SET body = :body, edited_at = NOW()'),
    'history lookup is Master-only and contact scoped' => str_contains($repository, 'Only the Master User can view Agent Sales Report edit history.') && str_contains($repository, 'WHERE main_id = :main_id AND contact_id = :contact_id AND record_type = :record_type'),
    'history endpoint is authenticated and registered' => str_contains($controller, 'function salesReportMessageEditHistory') && str_contains($bootstrap, "'salesReportMessageEditHistory'"),
    'conversation exposes only the edited marker to Master Users' => str_contains($repository, "'is_edited' => \$canViewEditHistory && !empty(\$thread['report_edited_at'])") && str_contains($repository, "'is_edited' => \$canViewEditHistory && !empty(\$message['edited_at'])"),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " {$label}\n";
    if (!$passed) $failed[] = $label;
}

if ($failed !== []) exit(1);
