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
use App\Support\Exceptions\HttpException;

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

    // A foreign customer's blocked identity is shared with every agent so
    // they can avoid contacting them, while remaining absent from contacts.
    $foreignBlockedCompany = 'REGRESS_BLOCKED_' . bin2hex(random_bytes(4));
    $foreignBlockedSession = 'RGB' . substr((string) time(), -6) . random_int(100, 999);
    $pdo->prepare(
        "INSERT INTO tblpatient (lmain_id, lsessionid, lcompany, lsales_person, lsales_team, ldeleted, lstatus, ldebt_type)
         VALUES (:main, :sess, :company, :owner, 0, 0, 4, 'Good')"
    )->execute([
        'main' => $mainId,
        'sess' => $foreignBlockedSession,
        'company' => $foreignBlockedCompany,
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
    $seenForeignBlocked = false;
    $seenOwn = false;
    foreach ($contacts as $contact) {
        $name = (string) ($contact['shop_name'] ?? '');
        if ($name === $foreignCompany) {
            $seenForeign = true;
        }
        if ($name === $foreignBlockedCompany) {
            $seenForeignBlocked = true;
        }
        if ($name === $ownCompany) {
            $seenOwn = true;
        }
    }

    if ($seenForeign) {
        $fail("FAIL: no-team view-all agent still sees another agent's no-team customer (leak not fixed)");
    }
    if ($seenForeignBlocked) {
        $fail('FAIL: foreign blacklisted customer leaked into the assignment-scoped contacts');
    }
    if (!$seenOwn) {
        $fail('FAIL: viewer no longer sees their own assigned customer (over-tightened)');
    }

    $blockedRows = $snapshot['do_not_contact_customers'] ?? [];
    $blockedRow = null;
    foreach ($blockedRows as $row) {
        if (($row['shop_name'] ?? '') === $foreignBlockedCompany) {
            $blockedRow = $row;
            break;
        }
    }
    if ($blockedRow === null) {
        $fail('FAIL: company-wide Do Not Contact list omitted another agent\'s blacklisted customer');
    }
    if (array_keys($blockedRow) !== ['id', 'shop_name', 'assigned_to', 'assigned_team']) {
        $fail('FAIL: Do Not Contact identity row contains fields outside the approved identity/owner allowlist');
    }
    foreach (['phone', 'mobile', 'contact_number', 'total_sales', 'totalSales'] as $sensitiveField) {
        if (array_key_exists($sensitiveField, $blockedRow)) {
            $fail("FAIL: Do Not Contact identity row unexpectedly includes {$sensitiveField}");
        }
    }

    $nonSalesTypeId = $pdo->query(
        "SELECT lid FROM tblusertype
         WHERE LOWER(TRIM(REGEXP_REPLACE(COALESCE(ltype_name, ''), '[[:space:]]+', ' '))) NOT IN ('sales agent', 'sales person', 'salesperson')
         LIMIT 1"
    )->fetchColumn();
    if ($nonSalesTypeId === false) {
        $fail('FAIL: no non-sales account type is available for the authorization regression');
    }
    $pdo->prepare(
        "INSERT INTO tblaccount (lmother_id, lfname, llname, ltype, lteam, lstatus)
         VALUES (:main, 'REGRESS', 'NONSALES', :type, 0, 1)"
    )->execute(['main' => $mainId, 'type' => $nonSalesTypeId]);
    $nonSalesViewerId = (int) $pdo->lastInsertId();
    try {
        $controller->agentSnapshot([], [], ['__auth_claims' => ['sub' => $nonSalesViewerId, 'main_userid' => $mainId]]);
        $fail('FAIL: non-sales tenant account could retrieve the Daily Call agent snapshot');
    } catch (HttpException $error) {
        if ($error->statusCode() !== 403) {
            throw $error;
        }
    }

    echo "PASS: assigned scope, company-wide Do Not Contact identities, DTO allowlist, and sales-agent authorization are enforced\n";
} finally {
    $pdo->rollBack();
}
