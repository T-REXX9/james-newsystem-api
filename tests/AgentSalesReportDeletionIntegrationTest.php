<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\CallReportRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$repo = new CallReportRepository($db);
$mainId = 1;
$masterId = 1;
$contactId = 'UT-sales-report-delete-' . bin2hex(random_bytes(6));
$threadId = 0;
$auditRefno = null;
$notificationRefno = null;

try {
    $insert = $pdo->prepare(
        'INSERT INTO call_report_threads
         (main_id, contact_id, is_direct, call_log_entry_id, call_log_refno, agent_user_id, agent_name, outcome, report_body)
         VALUES (:main_id, :contact_id, 1, NULL, :refno, :agent_id, :agent_name, "note", :report_body)'
    );
    $insert->execute([
        'main_id' => $mainId,
        'contact_id' => $contactId,
        'refno' => 'UT-delete-reload',
        'agent_id' => 11,
        'agent_name' => 'Sales Agent Fixture',
        'report_body' => 'Incorrect customer report',
    ]);
    $threadId = (int) $pdo->lastInsertId();

    $before = $repo->getUnifiedConversation($mainId, $contactId, $masterId);
    if (!in_array('report:' . $threadId, array_column($before['messages'], 'id'), true)) {
        throw new RuntimeException('FAIL: report is missing before deletion');
    }

    $repo->deleteConversationMessage($mainId, $contactId, 'report:' . $threadId, $masterId, 'Sent for the wrong customer');

    $reloaded = $repo->getUnifiedConversation($mainId, $contactId, $masterId);
    if (in_array('report:' . $threadId, array_column($reloaded['messages'], 'id'), true)) {
        throw new RuntimeException('FAIL: deleted report reappears after conversation reload');
    }

    $audit = $pdo->prepare('SELECT id, original_payload, delete_reason FROM call_report_deletion_audits WHERE main_id = :main_id AND thread_id = :thread_id AND record_type = "agent_report" LIMIT 1');
    $audit->execute(['main_id' => $mainId, 'thread_id' => $threadId]);
    $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
    if (!is_array($auditRow) || !str_contains((string) $auditRow['original_payload'], 'Incorrect customer report')) {
        throw new RuntimeException('FAIL: immutable original report audit is missing');
    }

    $auditRefno = 'call-report-deletion-audit:' . (int) $auditRow['id'];
    $trail = $pdo->prepare('SELECT luser_id, lreason FROM tblaudit_trail WHERE lrefno = :refno LIMIT 1');
    $trail->execute(['refno' => $auditRefno]);
    $trailRow = $trail->fetch(PDO::FETCH_ASSOC);
    if (!is_array($trailRow) || (int) $trailRow['luser_id'] !== $masterId || !str_contains((string) $trailRow['lreason'], 'sales_agent_id=11')) {
        throw new RuntimeException('FAIL: reviewable central deletion audit is missing traceability');
    }

    $notificationRefno = 'call-report-deletion-notification:' . (int) $auditRow['id'];
    $notification = $pdo->prepare('SELECT luserid, lmessage FROM tblnotifications WHERE lrefno = :refno LIMIT 1');
    $notification->execute(['refno' => $notificationRefno]);
    $notificationRow = $notification->fetch(PDO::FETCH_ASSOC);
    if (!is_array($notificationRow) || (int) $notificationRow['luserid'] !== 11 || !str_contains((string) $notificationRow['lmessage'], 'Sent for the wrong customer')) {
        throw new RuntimeException('FAIL: affected sales agent deletion notification is missing');
    }

    echo "PASS: master deletion remains hidden after reload and writes both audit records plus an agent notification\n";
} finally {
    if ($notificationRefno !== null) {
        $pdo->prepare('DELETE FROM tblnotifications WHERE lrefno = :refno')->execute(['refno' => $notificationRefno]);
    }
    if ($auditRefno !== null) {
        $pdo->prepare('DELETE FROM tblaudit_trail WHERE lrefno = :refno')->execute(['refno' => $auditRefno]);
    }
    if ($threadId > 0) {
        $pdo->prepare('DELETE FROM call_report_deletion_audits WHERE thread_id = :thread_id')->execute(['thread_id' => $threadId]);
        $pdo->prepare('DELETE FROM call_report_threads WHERE id = :id')->execute(['id' => $threadId]);
    }
}
