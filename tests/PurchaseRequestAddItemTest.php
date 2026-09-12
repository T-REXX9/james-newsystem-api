<?php

declare(strict_types=1);

/**
 * Adding a line to a saved purchase request must return the stored row.
 * The returned row reports whether the line already sits on a live purchase
 * order, which needs tblpo_list joined in; a missing join made every add fail
 * with a SQL error after the row had already been inserted.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\PurchaseRequestRepository;

$passed = 0;
$failed = 0;
$errors = [];

function pr_add_assert(bool $condition, string $message): void
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

function pr_add_assert_eq(mixed $expected, mixed $actual, string $message): void
{
    pr_add_assert(
        $expected === $actual,
        $message . ($expected === $actual ? '' : ' expected=' . json_encode($expected) . ' got=' . json_encode($actual))
    );
}

function pr_add_create_temp_like(PDO $pdo, string $table): void
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
$mainId = 998866;
$userId = 998867;
$prefix = 'UT-pr-additem-';

$tempTables = [
    'tblusertype',
    'tblaccount',
    'tblsupplier',
    'tblsupplier_cost',
    'tblinventory_item',
    'tblpr_list',
    'tblpr_item',
    'tblpo_list',
    'tblpo_itemlist',
    'tblpurchase_order',
    'tblnumber_generator',
    'tblaudit_trail',
];

try {
    foreach ($tempTables as $table) {
        pr_add_create_temp_like($pdo, $table);
    }

    $session = $prefix . 'session';
    $pdo->prepare('INSERT INTO tblsupplier (lid, lmain_id, lcode, lname, lstatus) VALUES (7001, :main_id, "S1", "Supplier One", 1)')
        ->execute(['main_id' => $mainId]);
    $pdo->prepare(
        'INSERT INTO tblinventory_item (lid, lsession, litemcode, lpartno, ldescription, lbrand, lcog, lopn_number, linv_stat)
         VALUES (5001, :session, "ITEM-A1", "PART-A1", "Addable item", "BrandA", "150.00", "OPN-A1", "1")'
    )->execute(['session' => $session]);

    $draftRefno = $prefix . 'draft';
    $pdo->prepare(
        'INSERT INTO tblpr_list (lid, lrefno, lprno, ldatetime, luser, lstatus, lremark, lapproval, ldeleted)
         VALUES (860001, :refno, "PR-UT-ADD", NOW(), :user_id, "Draft", "Draft PR", "Pending", 0)'
    )->execute(['refno' => $draftRefno, 'user_id' => $userId]);

    $repo = new PurchaseRequestRepository($db);

    echo "\n--- adding a line to a Draft purchase request ---\n";
    $row = $repo->addPurchaseRequestItem($mainId, $userId, $draftRefno, [
        'item_id' => $session,
        'supplier_id' => '7001',
        'quantity' => 3,
    ]);

    pr_add_assert(is_array($row) && $row !== [], 'add returns the stored line instead of throwing');
    pr_add_assert_eq('PART-A1', (string) ($row['part_number'] ?? ''), 'stored line carries the inventory part number');
    pr_add_assert_eq('Addable item', (string) ($row['description'] ?? ''), 'stored line carries the description');
    pr_add_assert_eq(3.0, (float) ($row['quantity'] ?? 0), 'stored line carries the requested quantity');
    pr_add_assert_eq('7001', (string) ($row['supplier_id'] ?? ''), 'stored line carries the chosen supplier');
    pr_add_assert_eq('', (string) ($row['po_refno'] ?? 'missing'), 'a fresh line reports no purchase order yet');
    pr_add_assert_eq('', (string) ($row['po_number'] ?? 'missing'), 'a fresh line reports no purchase order number yet');

    $detail = $repo->getPurchaseRequest($mainId, $draftRefno);
    pr_add_assert_eq(1, count($detail['items'] ?? []), 'the request detail shows the added line');
    pr_add_assert_eq('Draft', (string) ($detail['request']['status'] ?? ''), 'the request is still a Draft after adding');

    echo "\n--- a line already on a live purchase order reports that order ---\n";
    $linkedRefno = $prefix . 'linked';
    $linkedPoRefno = $prefix . 'linked-po';
    $linkedSession = $prefix . 'linked-session';
    $pdo->prepare(
        'INSERT INTO tblinventory_item (lid, lsession, litemcode, lpartno, ldescription, lbrand, lcog, lopn_number, linv_stat)
         VALUES (5002, :session, "ITEM-A2", "PART-A2", "Linked item", "BrandA", "90.00", "OPN-A2", "1")'
    )->execute(['session' => $linkedSession]);
    $pdo->prepare(
        'INSERT INTO tblpr_list (lid, lrefno, lprno, ldatetime, luser, lstatus, lremark, lapproval, ldeleted)
         VALUES (860002, :refno, "PR-UT-ADD2", NOW(), :user_id, "Draft", "Draft PR 2", "Pending", 0)'
    )->execute(['refno' => $linkedRefno, 'user_id' => $userId]);
    $pdo->prepare(
        'INSERT INTO tblpo_list (lid, lpurchaseno, ldate, ltime, lmain_id, luser, lrefno, ltransaction_status, lsupplier, lsupplier_name, lsupplier_code, ldeleted)
         VALUES (860102, "PO-UT-ADD", CURDATE(), CURTIME(), :main_id, :user_id, :refno, "Posted", "7001", "Supplier One", "S1", 0)'
    )->execute(['main_id' => $mainId, 'user_id' => $userId, 'refno' => $linkedPoRefno]);

    $repo->addPurchaseRequestItem($mainId, $userId, $linkedRefno, [
        'item_id' => $linkedSession,
        'supplier_id' => '7001',
        'quantity' => 1,
    ]);
    $addedId = (int) $pdo->query('SELECT MAX(lid) FROM tblpr_item')->fetchColumn();
    $pdo->prepare('UPDATE tblpr_item SET lpo_refno = :po_refno, lpo_no = "PO-UT-ADD" WHERE lid = :id')
        ->execute(['po_refno' => $linkedPoRefno, 'id' => $addedId]);

    $linkedDetail = $repo->getPurchaseRequest($mainId, $linkedRefno);
    pr_add_assert_eq('PO-UT-ADD', (string) ($linkedDetail['items'][0]['po_number'] ?? ''), 'a converted line reports its live purchase order');

    echo "\nMock data used temporary tables only; no persistent rows were inserted.\n";
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
