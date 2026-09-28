<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\CustomerDatabaseRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$customers = new CustomerDatabaseRepository($db);
$mainId = 1;
$agentId = 68;
$today = date('Y-m-d');
$stamp = date('YmdHis') . '-' . random_int(1000, 9999);
$sessionIds = ["ASSIGN-DIRECT-{$stamp}", "ASSIGN-BULK-{$stamp}", "ASSIGN-TEAM-{$stamp}", "ASSIGN-CREATE-{$stamp}", "ASSIGN-CREATE-NONE-{$stamp}"];

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: {$label}");
    }
    echo "PASS: {$label}\n";
};

$assignmentDate = static function (string $sessionId) use ($pdo, $mainId): ?string {
    $statement = $pdo->prepare(
        'SELECT ldate_assigned FROM tblpatient WHERE lmain_id = :main_id AND lsessionid = :session_id LIMIT 1'
    );
    $statement->execute(['main_id' => $mainId, 'session_id' => $sessionId]);
    $value = $statement->fetchColumn();
    return $value === false || $value === null ? null : substr((string) $value, 0, 10);
};

try {
    foreach ($sessionIds as $index => $sessionId) {
        // The two ASSIGN-CREATE sessions are created explicitly below to exercise
        // the create path with and without an agent already assigned.
        if ($sessionId === $sessionIds[3] || $sessionId === $sessionIds[4]) {
            continue;
        }
        $customers->createCustomer($mainId, 1, [
            'session_id' => $sessionId,
            'company' => "Agent assignment date test {$stamp}-{$index}",
            'phone' => '09171234567',
            'mobile' => '09171234567',
            'status' => 3,
            'profile_type' => 'Prospect',
            'debt_type' => 'Good',
            'refer_by' => 'QBP',
        ]);
    }

    $customers->updateCustomer($mainId, $sessionIds[0], [
        'sales_person_id' => (string) $agentId,
        'user_id' => 1,
    ]);
    $assert($assignmentDate($sessionIds[0]) === $today, 'direct agent assignment stores today as assignment date');

    $pdo->prepare('UPDATE tblpatient SET ldate_assigned = NULL WHERE lmain_id = :main_id AND lsessionid = :session_id')
        ->execute(['main_id' => $mainId, 'session_id' => $sessionIds[0]]);
    $customers->updateCustomer($mainId, $sessionIds[0], [
        'sales_person_id' => (string) $agentId,
        'user_id' => 1,
    ]);
    $assert($assignmentDate($sessionIds[0]) === $today, 're-saving an assigned agent restores a missing assignment date');

    $customers->updateCustomer($mainId, $sessionIds[0], [
        'sales_person_id' => '',
        'user_id' => 1,
    ]);
    $assert($assignmentDate($sessionIds[0]) === null, 'clearing an agent clears the assignment date');

    $customers->bulkUpdateCustomers($mainId, [$sessionIds[1]], ['sales_person_id' => (string) $agentId]);
    $assert($assignmentDate($sessionIds[1]) === $today, 'bulk agent assignment stores today as assignment date');

    $customers->bulkUpdateCustomers($mainId, [$sessionIds[1]], ['sales_person_id' => '']);
    $assert($assignmentDate($sessionIds[1]) === null, 'bulk clearing an agent clears the assignment date');

    $customers->bulkUpdateCustomers($mainId, [$sessionIds[2]], ['sales_team_id' => 3]);
    $assert($assignmentDate($sessionIds[2]) === null, 'team-only assignment does not create an agent assignment date');

    // Create path: a prospect created with an agent already assigned (e.g. an
    // unverified prospect auto-assigned to its staff's agent) must record the
    // assignment date at insert time, not leave it blank.
    $customers->createCustomer($mainId, 1, [
        'session_id' => $sessionIds[3],
        'company' => "Agent assignment date create test {$stamp}",
        'phone' => '09171234567',
        'mobile' => '09171234567',
        'status' => 3,
        'profile_type' => 'Prospect',
        'debt_type' => 'Good',
        'refer_by' => 'QBP',
        'sales_person_id' => (string) $agentId,
    ]);
    $assert($assignmentDate($sessionIds[3]) === $today, 'creating a prospect with an agent assigned stores today as assignment date');

    // Create path without an agent: the assignment date stays NULL.
    $customers->createCustomer($mainId, 1, [
        'session_id' => $sessionIds[4],
        'company' => "Agent assignment date create none test {$stamp}",
        'phone' => '09171234567',
        'mobile' => '09171234567',
        'status' => 3,
        'profile_type' => 'Prospect',
        'debt_type' => 'Good',
        'refer_by' => 'QBP',
    ]);
    $assert($assignmentDate($sessionIds[4]) === null, 'creating a prospect with no agent leaves the assignment date empty');
} finally {
    foreach ($sessionIds as $sessionId) {
        $pdo->prepare('DELETE FROM tblcontact_person WHERE lrefno = :session_id')->execute(['session_id' => $sessionId]);
        $pdo->prepare('DELETE FROM tblpatient_terms WHERE lpatient = :session_id')->execute(['session_id' => $sessionId]);
        $pdo->prepare('DELETE FROM tblpatient WHERE lmain_id = :main_id AND lsessionid = :session_id')
            ->execute(['main_id' => $mainId, 'session_id' => $sessionId]);
    }
}

echo "All customer agent assignment date checks passed.\n";
