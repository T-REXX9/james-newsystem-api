<?php

declare(strict_types=1);

/**
 * Unposting a purchase request cascades through its purchase orders and their
 * receiving reports in one transaction, reports what it unposted, and rolls the
 * whole chain back when any link refuses.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\PurchaseRequestRepository;

$passed = 0;
$failed = 0;
$errors = [];

function pr_cascade_assert(bool $condition, string $message): void
{
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  PASS {$message}\n";
        return;
    }
    $failed++;
    $errors[] = $message;
    echo "  FAIL {$message}\n";
}

function pr_cascade_assert_eq(mixed $expected, mixed $actual, string $message): void
{
    pr_cascade_assert(
        $expected === $actual,
        $message . ($expected === $actual ? '' : ' expected=' . json_encode($expected) . ' got=' . json_encode($actual))
    );
}

function pr_cascade_create_temp_like(PDO $pdo, string $table): void
{
    $row = $pdo->query("SHOW CREATE TABLE {$table}")->fetch(PDO::FETCH_ASSOC);
    $createSql = (string) ($row['Create Table'] ?? '');
    if ($createSql === '') {
        throw new RuntimeException("Unable to clone schema for {$table}");
    }
    $quoted = preg_quote($table, '/');
    $createSql = preg_replace('/^CREATE TABLE `?' . $quoted . '`?/i', "CREATE TEMPORARY TABLE `{$table}`", $createSql, 1) ?: $createSql;
    $pdo->exec($createSql);
}

function pr_cascade_status(PDO $pdo, string $table, string $column, string $refno): string
{
    $stmt = $pdo->prepare("SELECT {$column} FROM {$table} WHERE lrefno = :refno");
    $stmt->execute(['refno' => $refno]);
    return (string) $stmt->fetchColumn();
}

$vars = file_exists(__DIR__ . '/../.env') ? (parse_ini_file(__DIR__ . '/../.env') ?: []) : [];
$config = new Config(
    'test',
    true,
    '*',
    'secret',
    3600,
    (string) ($vars['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1'),
    (int) ($vars['DB_PORT'] ?? getenv('DB_PORT') ?: 3306),
    (string) ($vars['DB_NAME'] ?? getenv('DB_NAME') ?: 'topnotch_migrate'),
    (string) ($vars['DB_USER'] ?? getenv('DB_USER') ?: 'root'),
    (string) ($vars['DB_PASS'] ?? getenv('DB_PASS') ?: '')
);
$db = new Database($config);
$pdo = $db->pdo();
$mainId = 998977;
$userId = 998978;
$prefix = 'UT-pr-unpost-';

$tempTables = [
    'tblusertype',
    'tblaccount',
    'tblsupplier',
    'tblpr_list',
    'tblpr_item',
    'tblpo_list',
    'tblpo_itemlist',
    'tblnumber_generator',
    'tblpurchase_order',
    'tblpurchase_item',
    'tblinventory_logs',
    'tblreturn_supplier',
    'tblaudit_trail',
];

/**
 * Builds a PR -> PO -> RR chain. $poStatus controls whether the PO is
 * cascadable ("Completed"/"Posted") or not ("Pending").
 */
function pr_cascade_seed(
    PDO $pdo,
    int $mainId,
    int $userId,
    string $key,
    int $idBase,
    string $poStatus,
    bool $withReceivingReport = true
): array {
    $prRefno = $key . '-pr';
    $poRefno = $key . '-po';
    $rrRefno = $key . '-rr';
    $prNo = 'PR-' . strtoupper(substr($key, -6));
    $poNo = 'PO-' . strtoupper(substr($key, -6));
    $rrNo = 'RR-' . strtoupper(substr($key, -6));

    $pdo->prepare(
        'INSERT INTO tblpr_list (lid, lrefno, lprno, ldatetime, luser, lstatus, lremark, lapproval, ldeleted)
         VALUES (:id, :refno, :pr_no, NOW(), :user_id, "Submitted", "Cascade PR", "Approved", 0)'
    )->execute(['id' => $idBase, 'refno' => $prRefno, 'pr_no' => $prNo, 'user_id' => $userId]);

    $pdo->prepare(
        'INSERT INTO tblpr_item (lid, lrefno, litem_refno, litem_code, lpart_no, ldesc, lqty, lcost, lsupp_id, lsupp_name, lsupp_code, lpo_refno, lpo_no)
         VALUES (:id, :refno, :session, "ITEM-C1", "PART-C1", "Cascade item", "5", "100", "7001", "Supplier One", "S1", :po_refno, :po_no)'
    )->execute([
        'id' => $idBase + 1,
        'refno' => $prRefno,
        'session' => $key . '-session',
        'po_refno' => $poRefno,
        'po_no' => $poNo,
    ]);

    $pdo->prepare(
        'INSERT INTO tblpo_list (lid, lpurchaseno, ldate, ltime, lmain_id, luser, lrefno, ltransaction_status, lsupplier, lsupplier_name, lsupplier_code, lpr_no, lpr_refno, ldeleted)
         VALUES (:id, :po_no, CURDATE(), CURTIME(), :main_id, :user_id, :refno, :status, "7001", "Supplier One", "S1", :pr_no, :pr_refno, 0)'
    )->execute([
        'id' => $idBase + 2,
        'po_no' => $poNo,
        'main_id' => $mainId,
        'user_id' => $userId,
        'refno' => $poRefno,
        'status' => $poStatus,
        'pr_no' => $prNo,
        'pr_refno' => $prRefno,
    ]);

    $poItemId = $idBase + 3;
    $pdo->prepare(
        'INSERT INTO tblpo_itemlist (lid, lrefno, litemid, ldesc, lqty, luser, lpartno, litem_code, litem_refno, lsup_price, lbrand, lsupp_id, lsupp_code, lsupp_name, lreceiving_qty, lreceiving_refno, lreceiving_no)
         VALUES (:id, :refno, 201, "Cascade item", 5, :user_id, "PART-C1", "ITEM-C1", :session, "100.00", "Brand", "7001", "S1", "Supplier One", :received, :rr_refno, :rr_no)'
    )->execute([
        'id' => $poItemId,
        'refno' => $poRefno,
        'user_id' => $userId,
        'session' => $key . '-session',
        'received' => $withReceivingReport ? 5 : 0,
        'rr_refno' => $withReceivingReport ? $rrRefno : '',
        'rr_no' => $withReceivingReport ? $rrNo : '',
    ]);

    if ($withReceivingReport) {
        $pdo->prepare(
            'INSERT INTO tblpurchase_order (lid, lpurchaseno, ldate, ltime, lmain_id, luser, lrefno, ltransaction_status, lsupplier, lsupplier_name, lsupplier_code, lpo_refno, lpo_number, ldate_recieved, ldeleted)
             VALUES (:id, :rr_no, CURDATE(), CURTIME(), :main_id, :user_id, :refno, "Delivered", "7001", "Supplier One", "S1", :po_refno, :po_no, CURDATE(), 0)'
        )->execute([
            'id' => $idBase + 4,
            'rr_no' => $rrNo,
            'main_id' => $mainId,
            'user_id' => $userId,
            'refno' => $rrRefno,
            'po_refno' => $poRefno,
            'po_no' => $poNo,
        ]);
        $rrItemId = $idBase + 5;
        $pdo->prepare(
            'INSERT INTO tblpurchase_item (lid, lrefno, luser, litemid, litem_refno, litem_code, lpartno, ldesc, lqty, lsup_price, lpo_itemid, llocation, lwarehouse)
             VALUES (:id, :refno, :user_id, 201, :session, "ITEM-C1", "PART-C1", "Cascade item", 5, "100.00", :po_item_id, "Main", "Main")'
        )->execute([
            'id' => $rrItemId,
            'refno' => $rrRefno,
            'user_id' => $userId,
            'session' => $key . '-session',
            'po_item_id' => $poItemId,
        ]);
        $pdo->prepare(
            'INSERT INTO tblinventory_logs (linvent_id, lin, lout, ltotal, ldateadded, lprocess_by, lnote, lsupplier_id, linventory_id, lstatus_logs, ltransaction_item_id, lpurchase_item_id, lrefno, llocation, lwarehouse, ltransaction_type, litemcode, lpartno)
             VALUES (:session, 5, 0, 5, NOW(), :rr_no, "Supplier One", "7001", "201", "+", "Purchase Order", :item_id, :refno, "Main", "Main", "Receiving", "ITEM-C1", "PART-C1")'
        )->execute([
            'session' => $key . '-session',
            'rr_no' => $rrNo,
            'item_id' => $rrItemId,
            'refno' => $rrRefno,
        ]);
    }

    return [
        'pr_refno' => $prRefno,
        'po_refno' => $poRefno,
        'rr_refno' => $rrRefno,
        'po_no' => $poNo,
        'rr_no' => $rrNo,
        'po_item_id' => $poItemId,
    ];
}

try {
    foreach ($tempTables as $table) {
        pr_cascade_create_temp_like($pdo, $table);
    }

    $pdo->prepare('INSERT INTO tblusertype (lid, ltype_name) VALUES (1, "Owner")')->execute();
    $pdo->prepare('INSERT INTO tblaccount (lid, lmother_id, ltype, lfname, llname) VALUES (:id, :main_id, "1", "Unit", "Owner")')
        ->execute(['id' => $userId, 'main_id' => $mainId]);
    $pdo->prepare('INSERT INTO tblsupplier (lid, lmain_id, lcode, lname, lstatus) VALUES (7001, :main_id, "S1", "Supplier One", 1)')
        ->execute(['main_id' => $mainId]);

    $repo = new PurchaseRequestRepository($db);

    // ---- 1. Happy path: PR unpost cascades to its PO and RR ----
    echo "\n--- cascade through PO and RR ---\n";
    $chain = pr_cascade_seed($pdo, $mainId, $userId, $prefix . 'happy', 890000, 'Completed');

    $result = $repo->unpostPurchaseRequest($mainId, $userId, $chain['pr_refno'], 'Wrong supplier on the whole chain');

    pr_cascade_assert_eq('Unposted', $result['request']['status'] ?? null, 'purchase request is unposted');
    pr_cascade_assert_eq('Unposted', pr_cascade_status($pdo, 'tblpo_list', 'ltransaction_status', $chain['po_refno']), 'dependent purchase order is unposted too');
    pr_cascade_assert_eq('Unposted', pr_cascade_status($pdo, 'tblpurchase_order', 'ltransaction_status', $chain['rr_refno']), 'receiving report under that PO is unposted too');

    pr_cascade_assert_eq([$chain['po_no']], $result['cascade']['purchase_orders'] ?? null, 'result reports which purchase orders were unposted');
    pr_cascade_assert_eq([$chain['rr_no']], $result['cascade']['receiving_reports'] ?? null, 'result reports which receiving reports were unposted');

    $poItem = $pdo->prepare('SELECT lreceiving_qty, lreceiving_refno FROM tblpo_itemlist WHERE lid = :id');
    $poItem->execute(['id' => $chain['po_item_id']]);
    $poItemRow = $poItem->fetch(PDO::FETCH_ASSOC) ?: [];
    pr_cascade_assert_eq('0', (string) ($poItemRow['lreceiving_qty'] ?? ''), 'received quantity is reversed on the PO line');
    pr_cascade_assert_eq('', (string) ($poItemRow['lreceiving_refno'] ?? ''), 'receiving reference is cleared on the PO line');

    $logCount = $pdo->prepare('SELECT COUNT(*) FROM tblinventory_logs WHERE lrefno = :refno AND ltransaction_type = "Receiving"');
    $logCount->execute(['refno' => $chain['rr_refno']]);
    pr_cascade_assert_eq(0, (int) $logCount->fetchColumn(), 'receiving inventory logs are removed');

    $auditCount = $pdo->prepare('SELECT COUNT(*) FROM tblaudit_trail WHERE laction = "Unpost" AND lrefno IN (:pr, :po, :rr)');
    $auditCount->execute(['pr' => $chain['pr_refno'], 'po' => $chain['po_refno'], 'rr' => $chain['rr_refno']]);
    pr_cascade_assert_eq(3, (int) $auditCount->fetchColumn(), 'each unposted document is written to the audit trail');

    // ---- 2. A PO that cannot be unposted on its own blocks with a clear message ----
    echo "\n--- PO that is still Pending blocks with a clear reason ---\n";
    $pending = pr_cascade_seed($pdo, $mainId, $userId, $prefix . 'pending', 891000, 'Pending', false);

    $blocked = false;
    $blockedMessage = '';
    try {
        $repo->unpostPurchaseRequest($mainId, $userId, $pending['pr_refno'], 'Try to unpost with a pending PO');
    } catch (RuntimeException $error) {
        $blocked = true;
        $blockedMessage = $error->getMessage();
    }
    pr_cascade_assert($blocked, 'purchase request with a Pending purchase order is refused');
    pr_cascade_assert(
        str_contains($blockedMessage, 'cannot be unposted because') && str_contains($blockedMessage, $pending['po_no']),
        'refusal names the blocking purchase order so the user can act on it'
    );
    pr_cascade_assert_eq('Approved', $repo->getPurchaseRequest($mainId, $pending['pr_refno'])['request']['status'] ?? null, 'refused purchase request keeps its status');

    // ---- 3. Whole chain rolls back when a receiving report refuses ----
    echo "\n--- rollback when a receiving report has a supplier return ---\n";
    $guarded = pr_cascade_seed($pdo, $mainId, $userId, $prefix . 'guarded', 892000, 'Completed');
    $pdo->prepare(
        'INSERT INTO tblreturn_supplier (lid, lcredit_no, lrefno, lmainid, ltransaction_refno, lstatus)
         VALUES (892900, "RTS-UT-02", :refno, :main_id, :rr_refno, "Posted")'
    )->execute(['refno' => $prefix . 'rts', 'main_id' => $mainId, 'rr_refno' => $guarded['rr_refno']]);

    $rolledBack = false;
    $rollbackMessage = '';
    try {
        $repo->unpostPurchaseRequest($mainId, $userId, $guarded['pr_refno'], 'Try to unpost a returned chain');
    } catch (RuntimeException $error) {
        $rolledBack = true;
        $rollbackMessage = $error->getMessage();
    }
    pr_cascade_assert($rolledBack, 'purchase request unpost fails when a receiving report has a supplier return');
    pr_cascade_assert(str_contains($rollbackMessage, 'return-to-supplier'), 'failure explains the supplier return that blocked it');
    pr_cascade_assert_eq('Approved', $repo->getPurchaseRequest($mainId, $guarded['pr_refno'])['request']['status'] ?? null, 'purchase request stays posted after rollback');
    pr_cascade_assert_eq('Completed', pr_cascade_status($pdo, 'tblpo_list', 'ltransaction_status', $guarded['po_refno']), 'purchase order stays posted after rollback');
    pr_cascade_assert_eq('Delivered', pr_cascade_status($pdo, 'tblpurchase_order', 'ltransaction_status', $guarded['rr_refno']), 'receiving report stays posted after rollback');

    $guardedLogs = $pdo->prepare('SELECT COUNT(*) FROM tblinventory_logs WHERE lrefno = :refno AND ltransaction_type = "Receiving"');
    $guardedLogs->execute(['refno' => $guarded['rr_refno']]);
    pr_cascade_assert_eq(1, (int) $guardedLogs->fetchColumn(), 'inventory logs survive the rollback');

    echo "\nMock PR/PO/RR data used temporary tables only; no persistent rows were inserted.\n";
} catch (Throwable $error) {
    $failed++;
    $errors[] = $error->getMessage();
    echo "  FAIL setup or unexpected error: {$error->getMessage()}\n";
}

echo "Results: {$passed} passed, {$failed} failed\n";
if ($errors !== []) {
    echo "Errors:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
}
exit($failed === 0 ? 0 : 1);
