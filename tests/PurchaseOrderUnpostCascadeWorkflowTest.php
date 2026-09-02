<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\PurchaseRequestRepository;
use App\Repositories\ReceivingStockRepository;

$passed = 0;
$failed = 0;
$errors = [];

function po_cascade_assert(bool $condition, string $message): void
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

function po_cascade_assert_eq(mixed $expected, mixed $actual, string $message): void
{
    po_cascade_assert(
        $expected === $actual,
        $message . ($expected === $actual ? '' : ' expected=' . json_encode($expected) . ' got=' . json_encode($actual))
    );
}

function po_cascade_table_count(PDO $pdo, string $table, string $where, array $params): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function po_cascade_create_temp_like(PDO $pdo, string $table): void
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

$vars = file_exists(__DIR__ . '/../.env') ? (parse_ini_file(__DIR__ . '/../.env') ?: []) : [];
$dbHost = (string) ($vars['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1');
$dbPort = (int) ($vars['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
$dbName = (string) ($vars['DB_NAME'] ?? getenv('DB_NAME') ?: 'topnotch_migrate');
$dbUser = (string) ($vars['DB_USER'] ?? getenv('DB_USER') ?: 'root');
$dbPass = (string) ($vars['DB_PASS'] ?? getenv('DB_PASS') ?: '');

$config = new Config('test', true, '*', 'secret', 3600, $dbHost, $dbPort, $dbName, $dbUser, $dbPass);
$db = new Database($config);
$pdo = $db->pdo();
$mainId = 998878;
$userId = 998879;
$prefix = 'UT-pr-po-rr-';

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

try {
    foreach ($tempTables as $table) {
        po_cascade_create_temp_like($pdo, $table);
    }

    $pdo->prepare('INSERT INTO tblusertype (lid, ltype_name) VALUES (:id, "Owner")')
        ->execute(['id' => 1]);
    $pdo->prepare('INSERT INTO tblaccount (lid, lmother_id, ltype, lfname, llname) VALUES (:id, :main_id, "1", "Unit", "Owner")')
        ->execute(['id' => $userId, 'main_id' => $mainId]);
    $pdo->prepare('INSERT INTO tblsupplier (lid, lmain_id, lcode, lname, lstatus) VALUES (7001, :main_id, "S1", "Supplier One", 1)')
        ->execute(['main_id' => $mainId]);
    $pdo->prepare('INSERT INTO tblsupplier (lid, lmain_id, lcode, lname, lstatus) VALUES (7002, :main_id, "S2", "Supplier Two", 1)')
        ->execute(['main_id' => $mainId]);

    $prRefno = $prefix . 'pr';
    $poRefno = $prefix . 'po';
    $rrRefno = $prefix . 'rr';
    $poItemId = 880001;
    $prItemId = 880101;
    $rrItemId = 880201;

    $pdo->prepare(
        'INSERT INTO tblpr_list (lid, lrefno, lprno, ldatetime, luser, lstatus, lremark, lapproval, ldeleted)
         VALUES (870001, :refno, "PR-UT-01", NOW(), :user_id, "Submitted", "Initial PR", "Approved", 0)'
    )->execute(['refno' => $prRefno, 'user_id' => $userId]);
    $pdo->prepare(
        'INSERT INTO tblpr_item (lid, lrefno, litem_refno, litem_code, lpart_no, ldesc, lqty, lcost, lsupp_id, lsupp_name, lsupp_code, lpo_refno, lpo_no)
         VALUES (:id, :refno, "ITEM-SESSION-1", "ITEM-1", "PART-1", "Correctable item", "5", "100", "7001", "Supplier One", "S1", :po_refno, "PO-UT-01")'
    )->execute(['id' => $prItemId, 'refno' => $prRefno, 'po_refno' => $poRefno]);
    $pdo->prepare(
        'INSERT INTO tblpo_list (lid, lpurchaseno, ldate, ltime, lmain_id, luser, lrefno, ltransaction_status, lsupplier, lsupplier_name, lsupplier_code, lreference, lterms, laddress, lpr_no, lpr_refno, ldeleted)
         VALUES (870101, "PO-UT-01", CURDATE(), CURTIME(), :main_id, :user_id, :refno, "Completed", "7001", "Supplier One", "S1", "", "", "", "PR-UT-01", :pr_refno, 0)'
    )->execute(['main_id' => $mainId, 'user_id' => $userId, 'refno' => $poRefno, 'pr_refno' => $prRefno]);
    $pdo->prepare(
        'INSERT INTO tblpo_itemlist (lid, lrefno, litemid, ldesc, lqty, luser, lpartno, litem_code, litem_refno, lopn_number, lsup_price, lbrand, lsupp_id, lsupp_code, lsupp_name, leta_date, lreceiving_qty, lreceiving_refno, lreceiving_no)
         VALUES (:id, :refno, 101, "Correctable item", 5, :user_id, "PART-1", "ITEM-1", "ITEM-SESSION-1", "OPN-1", "100.00", "Brand", "7001", "S1", "Supplier One", CURDATE(), 5, :rr_refno, "RR-UT-01")'
    )->execute(['id' => $poItemId, 'refno' => $poRefno, 'user_id' => $userId, 'rr_refno' => $rrRefno]);
    $pdo->prepare(
        'INSERT INTO tblpurchase_order (lid, lpurchaseno, ldate, ltime, lmain_id, luser, lrefno, ltransaction_status, lsupplier, lsupplier_name, lsupplier_code, lpo_refno, lpo_number, lreference, lterms, laddress, ldate_recieved, ldeleted)
         VALUES (870201, "RR-UT-01", CURDATE(), CURTIME(), :main_id, :user_id, :refno, "Delivered", "7001", "Supplier One", "S1", :po_refno, "PO-UT-01", "", "", "", CURDATE(), 0)'
    )->execute(['main_id' => $mainId, 'user_id' => $userId, 'refno' => $rrRefno, 'po_refno' => $poRefno]);
    $pdo->prepare(
        'INSERT INTO tblpurchase_item (lid, lrefno, luser, litemid, litem_refno, litem_code, lpartno, ldesc, lqty, lsup_price, lpo_itemid, llocation, lwarehouse)
         VALUES (:id, :refno, :user_id, 101, "ITEM-SESSION-1", "ITEM-1", "PART-1", "Correctable item", 5, "100.00", :po_item_id, "Main", "Main")'
    )->execute(['id' => $rrItemId, 'refno' => $rrRefno, 'user_id' => $userId, 'po_item_id' => $poItemId]);
    $pdo->prepare(
        'INSERT INTO tblinventory_logs (linvent_id, lin, lout, ltotal, ldateadded, lprocess_by, lnote, lsupplier_id, linventory_id, lstatus_logs, ltransaction_item_id, lpurchase_item_id, lrefno, llocation, lwarehouse, ltransaction_type, litemcode, lpartno)
         VALUES ("ITEM-SESSION-1", 5, 0, 5, NOW(), "RR-UT-01", "Supplier One", "7001", "101", "+", "Purchase Order", :item_id, :refno, "Main", "Main", "Receiving", "ITEM-1", "PART-1")'
    )->execute(['item_id' => $rrItemId, 'refno' => $rrRefno]);

    $repo = new PurchaseOrderRepository($db);
    $updated = $repo->unpostPurchaseOrder($mainId, $userId, $poRefno, 'Correct quantity after receiving');

    po_cascade_assert_eq('Unposted', $updated['order']['status'] ?? null, 'completed PO can be unposted');
    po_cascade_assert_eq('Unposted', $updated['receiving_reports'][0]['status'] ?? null, 'linked RR is unposted with the PO');

    $poItem = $pdo->prepare('SELECT lreceiving_qty, lreceiving_refno, lreceiving_no FROM tblpo_itemlist WHERE lid = :id');
    $poItem->execute(['id' => $poItemId]);
    $poItemRow = $poItem->fetch(PDO::FETCH_ASSOC) ?: [];
    po_cascade_assert_eq('0', (string) ($poItemRow['lreceiving_qty'] ?? ''), 'PO received quantity is reversed');
    po_cascade_assert_eq('', (string) ($poItemRow['lreceiving_refno'] ?? ''), 'PO receiving reference is cleared');
    po_cascade_assert_eq('', (string) ($poItemRow['lreceiving_no'] ?? ''), 'PO receiving number is cleared');
    po_cascade_assert_eq(0, po_cascade_table_count($pdo, 'tblinventory_logs', 'lrefno = :refno AND ltransaction_type = "Receiving"', ['refno' => $rrRefno]), 'receiving inventory logs are removed');

    $prRepo = new PurchaseRequestRepository($db);
    $editedPrItem = $prRepo->updatePurchaseRequestItem($mainId, $prItemId, ['quantity' => 6]);
    po_cascade_assert_eq(6.0, (float) ($editedPrItem['quantity'] ?? 0), 'PR item is editable once related PO is unposted');

    $repostedPr = $prRepo->updatePurchaseRequest($mainId, $prRefno, ['status' => 'Approved']);
    po_cascade_assert_eq('Approved', $repostedPr['request']['status'] ?? null, 'unposted PR can be posted again');

    try {
        $prRepo->applyAction($mainId, $userId, $prRefno, 'convert-po', []);
        po_cascade_assert(false, 'PR with no remaining unconverted lines cannot generate another PO');
    } catch (RuntimeException $error) {
        po_cascade_assert(str_contains($error->getMessage(), 'No remaining purchase request items'), 'PR with no remaining unconverted lines cannot generate another PO');
    }

    $mixedSupplierPrRefno = $prefix . 'pr-mixed-supplier';
    $pdo->prepare(
        'INSERT INTO tblpr_list (lid, lrefno, lprno, ldatetime, luser, lstatus, lremark, lapproval, ldeleted)
         VALUES (870002, :refno, "PR-UT-MIXED", NOW(), :user_id, "Submitted", "Mixed supplier PR", "Approved", 0)'
    )->execute(['refno' => $mixedSupplierPrRefno, 'user_id' => $userId]);
    $pdo->prepare(
        'INSERT INTO tblpr_item (lid, lrefno, litem_refno, litem_code, lpart_no, ldesc, lqty, lcost, lsupp_id, lsupp_name, lsupp_code, lpo_refno, lpo_no)
         VALUES
         (880102, :refno_one, "ITEM-MIX-1", "ITEM-MIX-1", "PART-MIX-1", "Supplier one item", "1", "10", "7001", "Supplier One", "S1", "", ""),
         (880103, :refno_two, "ITEM-MIX-2", "ITEM-MIX-2", "PART-MIX-2", "Supplier two item", "1", "10", "7002", "Supplier Two", "S2", "", "")'
    )->execute(['refno_one' => $mixedSupplierPrRefno, 'refno_two' => $mixedSupplierPrRefno]);
    $splitConversion = $prRepo->applyAction($mainId, $userId, $mixedSupplierPrRefno, 'convert-po', ['item_ids' => [880102, 880103]]);
    po_cascade_assert_eq(2, (int) ($splitConversion['conversion']['po_count'] ?? 0), 'PR conversion creates one PO per selected supplier');
    po_cascade_assert_eq(2, (int) ($splitConversion['conversion']['converted_count'] ?? 0), 'split PR conversion reports all selected converted items');
    po_cascade_assert_eq(2, count($splitConversion['conversion']['purchase_orders'] ?? []), 'split PR conversion returns both created PO records');
    po_cascade_assert_eq(2, po_cascade_table_count($pdo, 'tblpo_list', 'lpr_refno = :refno', ['refno' => $mixedSupplierPrRefno]), 'split PR conversion inserts two PO headers');
    po_cascade_assert_eq(2, po_cascade_table_count($pdo, 'tblpr_item', 'lrefno = :refno AND TRIM(COALESCE(lpo_refno, "")) <> ""', ['refno' => $mixedSupplierPrRefno]), 'split PR conversion links each selected PR item to a PO');

    $deletedPoPrRefno = $prefix . 'pr-delete-po';
    $deletedPoRefno = $prefix . 'po-delete';
    $deletedPoItemId = 880301;
    $deletedPoPrItemId = 880401;
    $pdo->prepare(
        'INSERT INTO tblpr_list (lid, lrefno, lprno, ldatetime, luser, lstatus, lremark, lapproval, ldeleted)
         VALUES (870203, :refno, "PR-UT-DELETE-PO", NOW(), :user_id, "Submitted", "Delete PO cleanup PR", "Approved", 0)'
    )->execute(['refno' => $deletedPoPrRefno, 'user_id' => $userId]);
    $pdo->prepare(
        'INSERT INTO tblpr_item (lid, lrefno, litem_refno, litem_code, lpart_no, ldesc, lqty, lcost, lsupp_id, lsupp_name, lsupp_code, lpo_refno, lpo_no)
         VALUES (:id, :refno, "ITEM-DELETE-PO", "ITEM-DELETE-PO", "PART-DELETE-PO", "Delete PO item", "2", "25", "7001", "Supplier One", "S1", :po_refno, "PO-UT-DELETE")'
    )->execute(['id' => $deletedPoPrItemId, 'refno' => $deletedPoPrRefno, 'po_refno' => $deletedPoRefno]);
    $pdo->prepare(
        'INSERT INTO tblpo_list (lid, lpurchaseno, ldate, ltime, lmain_id, luser, lrefno, ltransaction_status, lsupplier, lsupplier_name, lsupplier_code, lpr_no, lpr_refno, ldeleted)
         VALUES (870303, "PO-UT-DELETE", CURDATE(), CURTIME(), :main_id, :user_id, :refno, "Pending", "7001", "Supplier One", "S1", "PR-UT-DELETE-PO", :pr_refno, 0)'
    )->execute(['main_id' => $mainId, 'user_id' => $userId, 'refno' => $deletedPoRefno, 'pr_refno' => $deletedPoPrRefno]);
    $pdo->prepare(
        'INSERT INTO tblpo_itemlist (lid, lrefno, litemid, ldesc, lqty, luser, lpartno, litem_code, litem_refno, lsup_price, lsupp_id, lsupp_code, lsupp_name)
         VALUES (:id, :refno, 103, "Delete PO item", 2, :user_id, "PART-DELETE-PO", "ITEM-DELETE-PO", "ITEM-DELETE-PO", "25.00", "7001", "S1", "Supplier One")'
    )->execute(['id' => $deletedPoItemId, 'refno' => $deletedPoRefno, 'user_id' => $userId]);

    $repo->deletePurchaseOrderItem($mainId, $deletedPoItemId);
    $deletedPoItemLink = $pdo->prepare('SELECT lpo_refno, lpo_no FROM tblpr_item WHERE lid = :id');
    $deletedPoItemLink->execute(['id' => $deletedPoPrItemId]);
    $deletedPoItemLinkRow = $deletedPoItemLink->fetch(PDO::FETCH_ASSOC) ?: [];
    po_cascade_assert_eq('', (string) ($deletedPoItemLinkRow['lpo_refno'] ?? ''), 'deleting a PO item clears the matching PR item PO reference');
    po_cascade_assert_eq('', (string) ($deletedPoItemLinkRow['lpo_no'] ?? ''), 'deleting a PO item clears the matching PR item PO number');

    $pdo->prepare('UPDATE tblpr_item SET lpo_refno = :po_refno, lpo_no = "PO-UT-DELETE" WHERE lid = :id')
        ->execute(['po_refno' => $deletedPoRefno, 'id' => $deletedPoPrItemId]);
    $pdo->prepare(
        'INSERT INTO tblpo_itemlist (lid, lrefno, litemid, ldesc, lqty, luser, lpartno, litem_code, litem_refno, lsup_price, lsupp_id, lsupp_code, lsupp_name)
         VALUES (:id, :refno, 103, "Delete PO item", 2, :user_id, "PART-DELETE-PO", "ITEM-DELETE-PO", "ITEM-DELETE-PO", "25.00", "7001", "S1", "Supplier One")'
    )->execute(['id' => $deletedPoItemId + 1, 'refno' => $deletedPoRefno, 'user_id' => $userId]);

    $repo->deletePurchaseOrder($mainId, $userId, $deletedPoRefno, 'Remove test PO');
    $deletedPoHeaderLink = $pdo->prepare('SELECT lpo_refno, lpo_no FROM tblpr_item WHERE lid = :id');
    $deletedPoHeaderLink->execute(['id' => $deletedPoPrItemId]);
    $deletedPoHeaderLinkRow = $deletedPoHeaderLink->fetch(PDO::FETCH_ASSOC) ?: [];
    po_cascade_assert_eq('', (string) ($deletedPoHeaderLinkRow['lpo_refno'] ?? ''), 'deleting a PO clears linked PR item PO references');
    po_cascade_assert_eq('', (string) ($deletedPoHeaderLinkRow['lpo_no'] ?? ''), 'deleting a PO clears linked PR item PO numbers');

    $stalePoPrRefno = $prefix . 'pr-stale-po';
    $stalePoRefno = $prefix . 'po-stale-deleted';
    $pdo->prepare(
        'INSERT INTO tblpr_list (lid, lrefno, lprno, ldatetime, luser, lstatus, lremark, lapproval, ldeleted)
         VALUES (870204, :refno, "PR-UT-STALE-PO", NOW(), :user_id, "Submitted", "Stale PO cleanup PR", "Approved", 0)'
    )->execute(['refno' => $stalePoPrRefno, 'user_id' => $userId]);
    $pdo->prepare(
        'INSERT INTO tblpr_item (lid, lrefno, litem_refno, litem_code, lpart_no, ldesc, lqty, lcost, lsupp_id, lsupp_name, lsupp_code, lpo_refno, lpo_no)
         VALUES (880402, :refno, "ITEM-STALE-PO", "ITEM-STALE-PO", "PART-STALE-PO", "Stale PO item", "2", "25", "7001", "Supplier One", "S1", :po_refno, "PO-UT-STALE")'
    )->execute(['refno' => $stalePoPrRefno, 'po_refno' => $stalePoRefno]);
    $pdo->prepare(
        'INSERT INTO tblpo_list (lid, lpurchaseno, ldate, ltime, lmain_id, luser, lrefno, ltransaction_status, lsupplier, lsupplier_name, lsupplier_code, lpr_no, lpr_refno, ldeleted)
         VALUES (870304, "PO-UT-STALE", CURDATE(), CURTIME(), :main_id, :user_id, :refno, "Deleted", "7001", "Supplier One", "S1", "PR-UT-STALE-PO", :pr_refno, 1)'
    )->execute(['main_id' => $mainId, 'user_id' => $userId, 'refno' => $stalePoRefno, 'pr_refno' => $stalePoPrRefno]);
    $stalePr = $prRepo->getPurchaseRequest($mainId, $stalePoPrRefno);
    po_cascade_assert_eq('', (string) ($stalePr['items'][0]['po_refno'] ?? 'not-empty'), 'PR detail hides stale deleted PO references');
    po_cascade_assert_eq('', (string) ($stalePr['items'][0]['po_number'] ?? 'not-empty'), 'PR detail hides stale deleted PO numbers');

    $editedPoItem = $repo->updatePurchaseOrderItem($mainId, $poItemId, ['qty' => 7, 'supplier_price' => 125]);
    po_cascade_assert_eq(7, (int) ($editedPoItem['qty'] ?? 0), 'unposted PO item quantity is editable');
    po_cascade_assert_eq(125.0, (float) ($editedPoItem['supplier_price'] ?? 0), 'unposted PO item cost is editable');

    $backdatedPo = $repo->updatePurchaseOrder($mainId, $poRefno, ['order_date' => '2026-01-15']);
    po_cascade_assert_eq('2026-01-15', (string) ($backdatedPo['order']['order_date'] ?? ''), 'management can edit the PO date after unposting');

    $reposted = $repo->updatePurchaseOrder($mainId, $poRefno, ['status' => 'Posted']);
    po_cascade_assert_eq('Posted', $reposted['order']['status'] ?? null, 'unposted PO can be posted again');

    $rrRepo = new ReceivingStockRepository($db);
    $editedRrItem = $rrRepo->updateReceivingStockItem($mainId, $rrItemId, ['qty' => 7, 'unit_cost' => 125]);
    po_cascade_assert_eq(7, (int) ($editedRrItem['qty'] ?? 0), 'unposted RR item quantity is editable');
    po_cascade_assert_eq(125.0, (float) ($editedRrItem['unit_cost'] ?? 0), 'unposted RR item cost is editable');

    $repostedRr = $rrRepo->finalizeReceivingStock($mainId, $rrRefno, 'Delivered');
    po_cascade_assert_eq('Delivered', $repostedRr['record']['status'] ?? null, 'unposted RR can be posted again');

    $repostedPoItem = $pdo->prepare('SELECT lreceiving_qty, lreceiving_refno, lreceiving_no FROM tblpo_itemlist WHERE lid = :id');
    $repostedPoItem->execute(['id' => $poItemId]);
    $repostedPoItemRow = $repostedPoItem->fetch(PDO::FETCH_ASSOC) ?: [];
    po_cascade_assert_eq('7', (string) ($repostedPoItemRow['lreceiving_qty'] ?? ''), 'corrected RR restores PO received quantity after repost');
    po_cascade_assert_eq($rrRefno, (string) ($repostedPoItemRow['lreceiving_refno'] ?? ''), 'corrected RR restores PO receiving reference after repost');
    po_cascade_assert_eq('RR-UT-01', (string) ($repostedPoItemRow['lreceiving_no'] ?? ''), 'corrected RR restores PO receiving number after repost');

    $poAfterRrRepost = $repo->getPurchaseOrder($mainId, $poRefno);
    po_cascade_assert_eq('Completed', $poAfterRrRepost['order']['status'] ?? null, 'PO completes again after corrected RR repost');
    po_cascade_assert_eq(1, po_cascade_table_count($pdo, 'tblinventory_logs', 'lrefno = :refno AND ltransaction_type = "Receiving" AND lin = 7', ['refno' => $rrRefno]), 'corrected RR recreates receiving inventory log');

    $blockedPrUpdate = false;
    try {
        $prRepo->updatePurchaseRequestItem($mainId, $prItemId, ['quantity' => 8]);
    } catch (RuntimeException) {
        $blockedPrUpdate = true;
    }
    po_cascade_assert($blockedPrUpdate, 'active reposted PO still blocks PR item edits');

    $blockedPoRefno = $prefix . 'po-blocked';
    $blockedRrRefno = $prefix . 'rr-blocked';
    $blockedPoItemId = 881001;
    $blockedRrItemId = 881201;
    $pdo->prepare(
        'INSERT INTO tblpo_list (lid, lpurchaseno, ldate, ltime, lmain_id, luser, lrefno, ltransaction_status, lsupplier, lsupplier_name, lsupplier_code, ldeleted)
         VALUES (871101, "PO-UT-02", CURDATE(), CURTIME(), :main_id, :user_id, :refno, "Posted", "7001", "Supplier One", "S1", 0)'
    )->execute(['main_id' => $mainId, 'user_id' => $userId, 'refno' => $blockedPoRefno]);
    $pdo->prepare(
        'INSERT INTO tblpo_itemlist (lid, lrefno, litemid, ldesc, lqty, luser, lpartno, litem_code, litem_refno, lsup_price, lreceiving_qty, lreceiving_refno, lreceiving_no)
         VALUES (:id, :refno, 102, "Blocked item", 3, :user_id, "PART-2", "ITEM-2", "ITEM-SESSION-2", "20.00", 3, :rr_refno, "RR-UT-02")'
    )->execute(['id' => $blockedPoItemId, 'refno' => $blockedPoRefno, 'user_id' => $userId, 'rr_refno' => $blockedRrRefno]);
    $pdo->prepare(
        'INSERT INTO tblpurchase_order (lid, lpurchaseno, ldate, ltime, lmain_id, luser, lrefno, ltransaction_status, lsupplier, lsupplier_name, lsupplier_code, lpo_refno, lpo_number, ldate_recieved, ldeleted)
         VALUES (871201, "RR-UT-02", CURDATE(), CURTIME(), :main_id, :user_id, :refno, "Delivered", "7001", "Supplier One", "S1", :po_refno, "PO-UT-02", CURDATE(), 0)'
    )->execute(['main_id' => $mainId, 'user_id' => $userId, 'refno' => $blockedRrRefno, 'po_refno' => $blockedPoRefno]);
    $pdo->prepare(
        'INSERT INTO tblpurchase_item (lid, lrefno, luser, litemid, litem_refno, litem_code, lpartno, ldesc, lqty, lsup_price, lpo_itemid)
         VALUES (:id, :refno, :user_id, 102, "ITEM-SESSION-2", "ITEM-2", "PART-2", "Blocked item", 3, "20.00", :po_item_id)'
    )->execute(['id' => $blockedRrItemId, 'refno' => $blockedRrRefno, 'user_id' => $userId, 'po_item_id' => $blockedPoItemId]);
    $pdo->prepare(
        'INSERT INTO tblreturn_supplier (lid, lcredit_no, lrefno, lmainid, ltransaction_refno, lstatus)
         VALUES (871301, "RTS-UT-01", :refno, :main_id, :rr_refno, "Posted")'
    )->execute(['refno' => $prefix . 'rts', 'main_id' => $mainId, 'rr_refno' => $blockedRrRefno]);

    try {
        $repo->unpostPurchaseOrder($mainId, $userId, $blockedPoRefno, 'Try unpost with supplier return');
        po_cascade_assert(false, 'PO unpost is blocked when RR has return-to-supplier dependency');
    } catch (RuntimeException $error) {
        po_cascade_assert(str_contains($error->getMessage(), 'return-to-supplier'), 'PO unpost is blocked when RR has return-to-supplier dependency');
    }
    $statusStmt = $pdo->prepare('SELECT ltransaction_status FROM tblpo_list WHERE lrefno = :refno');
    $statusStmt->execute(['refno' => $blockedPoRefno]);
    po_cascade_assert_eq('Posted', (string) $statusStmt->fetchColumn(), 'blocked PO remains posted after rollback');

    echo "Mock PR/PO/RR data used temporary tables only; no persistent rows were inserted.\n";
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
