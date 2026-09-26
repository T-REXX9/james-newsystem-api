<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\CustomerDatabaseRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$customers = new CustomerDatabaseRepository($db);
$mainId = 1;
$stamp = date('YmdHis') . '-' . random_int(1000, 9999);
$sessionId = "ASSIGN-HISTORY-{$stamp}";

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: {$label}");
    }
    echo "PASS: {$label}\n";
};

// Pick two distinct valid agents for this main_id, plus fall back gracefully.
$agentRows = $pdo->prepare(
    "SELECT lid FROM tblaccount
     WHERE (lid = :owner OR lmother_id = :staff) AND COALESCE(lstatus, 0) = 1
     ORDER BY lid ASC LIMIT 3"
);
$agentRows->execute(['owner' => $mainId, 'staff' => $mainId]);
$agentIds = array_map('strval', array_column($agentRows->fetchAll(PDO::FETCH_ASSOC), 'lid'));
if (count($agentIds) < 3) {
    // Common test agents used elsewhere in the suite.
    $agentIds = array_values(array_unique(array_merge($agentIds, ['64', '68', '1'])));
}
[$agentA, $agentB, $agentC] = [$agentIds[0], $agentIds[1], $agentIds[2]];

try {
    // 1) Create with an agent -> one history row.
    $customers->createCustomer($mainId, 1, [
        'session_id' => $sessionId,
        'company' => "Assignment history test {$stamp}",
        'phone' => '09171234567',
        'mobile' => '09171234567',
        'status' => 3,
        'profile_type' => 'Prospect',
        'debt_type' => 'Good',
        'refer_by' => 'QBP',
        'sales_person_id' => $agentA,
    ]);
    $history = $customers->getAssignmentHistory($mainId, $sessionId);
    $assert(count($history) === 1, 'creating with an agent records one history row');
    $assert($history[0]['agent_id'] === (string) $agentA, 'first history row is the creation agent');

    // 2) Reassign to a different agent -> two rows, newest first.
    $customers->updateCustomer($mainId, $sessionId, [
        'sales_person_id' => $agentB,
        'user_id' => 1,
    ]);
    $history = $customers->getAssignmentHistory($mainId, $sessionId);
    $assert(count($history) === 2, 'reassigning to a new agent records a second history row');
    $assert($history[0]['agent_id'] === (string) $agentB, 'newest history row is the latest agent');

    // 3) Re-saving the SAME agent must NOT add a row.
    $customers->updateCustomer($mainId, $sessionId, [
        'sales_person_id' => $agentB,
        'user_id' => 1,
    ]);
    $assert(count($customers->getAssignmentHistory($mainId, $sessionId)) === 2, 're-saving the same agent adds no history row');

    // 4) Bulk-assign to a third agent -> three rows.
    $customers->bulkUpdateCustomers($mainId, [$sessionId], ['sales_person_id' => $agentC, 'user_id' => 1]);
    $history = $customers->getAssignmentHistory($mainId, $sessionId);
    $assert(count($history) === 3, 'bulk assignment records a history row');
    $assert($history[0]['agent_id'] === (string) $agentC, 'newest row after bulk is the bulk agent');

    // 5) Unassigning is NOT a history event.
    $customers->updateCustomer($mainId, $sessionId, [
        'sales_person_id' => '',
        'user_id' => 1,
    ]);
    $assert(count($customers->getAssignmentHistory($mainId, $sessionId)) === 3, 'clearing the agent adds no history row');

    // 6) Agent name is snapshotted (non-empty when the account resolves).
    $assert(is_string($history[0]['agent_name']), 'history rows carry an agent name field');
} finally {
    $pdo->prepare('DELETE FROM tblpatient_assignment_history WHERE lmain_id = :main_id AND lsessionid = :session_id')
        ->execute(['main_id' => $mainId, 'session_id' => $sessionId]);
    $pdo->prepare('DELETE FROM tblcontact_person WHERE lrefno = :session_id')->execute(['session_id' => $sessionId]);
    $pdo->prepare('DELETE FROM tblpatient_terms WHERE lpatient = :session_id')->execute(['session_id' => $sessionId]);
    $pdo->prepare('DELETE FROM tblpatient WHERE lmain_id = :main_id AND lsessionid = :session_id')
        ->execute(['main_id' => $mainId, 'session_id' => $sessionId]);
}

echo "All assignment history checks passed.\n";
