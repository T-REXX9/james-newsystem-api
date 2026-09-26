<?php

declare(strict_types=1);

/**
 * Regression: a Daily Call agent with the "See all records" permission but
 * NO team must still be scoped to their own assigned customers. Before the
 * fix, resolveViewerAssignmentId() returned null (unfiltered) for such an
 * agent as long as they had at least one assigned customer, leaking the whole
 * company book -- including customers assigned to another agent and on no team.
 *
 * Self-contained: seeds temporary fixtures inside a transaction and rolls back,
 * so it never pollutes the database.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Controllers\DailyCallMonitoringController;
use App\Database;
use App\Repositories\CallReportRepository;
use App\Repositories\CustomerDatabaseRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\DailyCallMonitoringRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$mainId = 1;

$fail = static function (string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

$pdo->beginTransaction();
try {
    // The "See all records" permission for the Daily Call page, stored the way
    // getActionPermissionsForAccount reads it (page-scoped JSON on the account).
    $viewAllPerms = json_encode([
        'global' => ['can_view_all_records' => true],
        'pages' => [
            'Daily Call Monitoring Dashboard' => ['can_view_all_records' => true],
        ],
    ], JSON_THROW_ON_ERROR);

    // Viewer: a plain sales agent (type 2), NO team, WITH the view-all permission.
    $pdo->prepare(
        "INSERT INTO tblaccount (lmother_id, lfname, llname, ltype, lteam, lstatus, laction_permissions)
         VALUES (:main, 'REGRESS', 'VIEWER', 2, 0, 1, :perms)"
    )->execute(['main' => $mainId, 'perms' => $viewAllPerms]);
    $viewerId = (int) $pdo->lastInsertId();

    // Another agent (type 2), also no team, who OWNS the customer under test.
    $pdo->prepare(
        "INSERT INTO tblaccount (lmother_id, lfname, llname, ltype, lteam, lstatus)
         VALUES (:main, 'REGRESS', 'OTHERAGENT', 2, 0, 1)"
    )->execute(['main' => $mainId]);
    $otherAgentId = (int) $pdo->lastInsertId();

    // A customer assigned to the OTHER agent, on NO team. The viewer must NOT
    // see this row.
    $foreignCompany = 'REGRESS_FOREIGN_' . bin2hex(random_bytes(4));
    $foreignSession = 'RGX' . substr((string) time(), -6) . random_int(100, 999);
    $pdo->prepare(
        "INSERT INTO tblpatient (lmain_id, lsessionid, lcompany, lsales_person, lsales_team, ldeleted, lstatus)
         VALUES (:main, :sess, :company, :owner, 0, 0, 1)"
    )->execute([
        'main' => $mainId,
        'sess' => $foreignSession,
        'company' => $foreignCompany,
        'owner' => $otherAgentId,
    ]);

    // A customer assigned to the VIEWER, so the viewer has an individual
    // assignment (the exact condition that used to trigger the leak).
    $ownCompany = 'REGRESS_OWN_' . bin2hex(random_bytes(4));
    $ownSession = 'RGO' . substr((string) time(), -6) . random_int(100, 999);
    $pdo->prepare(
        "INSERT INTO tblpatient (lmain_id, lsessionid, lcompany, lsales_person, lsales_team, ldeleted, lstatus)
         VALUES (:main, :sess, :company, :owner, 0, 0, 1)"
    )->execute([
        'main' => $mainId,
        'sess' => $ownSession,
        'company' => $ownCompany,
        'owner' => $viewerId,
    ]);

    $controller = new DailyCallMonitoringController(
        new DailyCallMonitoringRepository($db),
        new CallReportRepository($db),
        new CustomerDatabaseRepository($db),
        new CustomerRepository($db)
    );
    $claims = ['__auth_claims' => ['sub' => $viewerId, 'main_userid' => $mainId]];
    $snapshot = $controller->agentSnapshot([], [], $claims);
    $contacts = $snapshot['contacts'] ?? [];

    $seenForeign = false;
    $seenOwn = false;
    foreach ($contacts as $contact) {
        $name = (string) ($contact['shop_name'] ?? '');
        if ($name === $foreignCompany) {
            $seenForeign = true;
        }
        if ($name === $ownCompany) {
            $seenOwn = true;
        }
    }

    if ($seenForeign) {
        $fail("FAIL: no-team view-all agent still sees another agent's no-team customer (leak not fixed)");
    }
    if (!$seenOwn) {
        $fail('FAIL: viewer no longer sees their own assigned customer (over-tightened)');
    }

    echo "PASS: a no-team agent with view-all permission is scoped to their own assignments\n";
} finally {
    $pdo->rollBack();
}
