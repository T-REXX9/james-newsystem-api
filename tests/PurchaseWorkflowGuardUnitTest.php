<?php

declare(strict_types=1);

use App\Repositories\PurchaseWorkflowGuard;

require_once __DIR__ . '/../src/Repositories/PurchaseWorkflowGuard.php';

function workflowPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE tblpr_list (lrefno TEXT, lprno TEXT, lstatus TEXT, ldeleted INTEGER)');
    $pdo->exec('CREATE TABLE tblpr_item (lid INTEGER, lrefno TEXT, litem_code TEXT, litem_refno TEXT, lpo_refno TEXT)');
    $pdo->exec('CREATE TABLE tblpo_list (lrefno TEXT, lpurchaseno TEXT, ltransaction_status TEXT, ldeleted INTEGER)');
    $pdo->exec('CREATE TABLE tblpo_itemlist (lid INTEGER, lrefno TEXT, litem_code TEXT, litem_refno TEXT, lqty REAL, lreceiving_qty REAL)');
    $pdo->exec('CREATE TABLE tblpurchase_order (lrefno TEXT, lpo_refno TEXT, ltransaction_status TEXT)');
    return $pdo;
}

function expectWorkflowConflict(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $error) {
        if (str_contains($error->getMessage(), $message)) return;
        throw new RuntimeException('Unexpected conflict message: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected an active-workflow conflict');
}

$passed = 0;
$failed = 0;
$run = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo "  PASS {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "  FAIL {$name}: {$error->getMessage()}\n";
    }
};

$run('blocks an item already in an active PR', static function (): void {
    $pdo = workflowPdo();
    $pdo->exec("INSERT INTO tblpr_list VALUES ('PR-REF-1', 'PR-2601', 'Pending', 0)");
    $pdo->exec("INSERT INTO tblpr_item VALUES (1, 'PR-REF-1', 'ITEM-1', 'SESSION-1', '')");
    $guard = new PurchaseWorkflowGuard($pdo);
    expectWorkflowConflict(
        static fn () => $guard->assertItemsAvailable([['item_code' => 'ITEM-1', 'item_id' => 'SESSION-1']]),
        'PR-2601'
    );
});

$run('blocks an item already in an active PO', static function (): void {
    $pdo = workflowPdo();
    $pdo->exec("INSERT INTO tblpo_list VALUES ('PO-REF-1', 'PO-2601', 'Posted', 0)");
    $pdo->exec("INSERT INTO tblpo_itemlist VALUES (1, 'PO-REF-1', 'ITEM-2', 'SESSION-2', 10, 0)");
    $guard = new PurchaseWorkflowGuard($pdo);
    expectWorkflowConflict(
        static fn () => $guard->assertItemsAvailable([['item_code' => 'ITEM-2', 'item_id' => 'SESSION-2']]),
        'PO-2601'
    );
});

$run('allows a new workflow when the matching PR is soft-deleted', static function (): void {
    $pdo = workflowPdo();
    $pdo->exec("INSERT INTO tblpr_list VALUES ('PR-REF-4', 'PR-2604', 'Deleted', 1)");
    $pdo->exec("INSERT INTO tblpr_item VALUES (4, 'PR-REF-4', 'ITEM-5', 'SESSION-5', '')");
    (new PurchaseWorkflowGuard($pdo))->assertItemsAvailable([
        ['item_code' => 'ITEM-5', 'item_id' => 'SESSION-5'],
    ]);
});

$run('allows a new workflow when the matching PR has deleted status', static function (): void {
    $pdo = workflowPdo();
    $pdo->exec("INSERT INTO tblpr_list VALUES ('PR-REF-5', 'PR-2605', 'Deleted', 0)");
    $pdo->exec("INSERT INTO tblpr_item VALUES (5, 'PR-REF-5', 'ITEM-6', 'SESSION-6', '')");
    (new PurchaseWorkflowGuard($pdo))->assertItemsAvailable([
        ['item_code' => 'ITEM-6', 'item_id' => 'SESSION-6'],
    ]);
});

$run('allows a new workflow after receiving is completed', static function (): void {
    $pdo = workflowPdo();
    $pdo->exec("INSERT INTO tblpo_list VALUES ('PO-REF-2', 'PO-2602', 'Posted', 0)");
    $pdo->exec("INSERT INTO tblpo_itemlist VALUES (2, 'PO-REF-2', 'ITEM-3', 'SESSION-3', 10, 10)");
    $pdo->exec("INSERT INTO tblpurchase_order VALUES ('RR-REF-1', 'PO-REF-2', 'Posted')");
    (new PurchaseWorkflowGuard($pdo))->assertItemsAvailable([
        ['item_code' => 'ITEM-3', 'item_id' => 'SESSION-3'],
    ]);
});

$run('allows a new workflow after receiving is delivered', static function (): void {
    $pdo = workflowPdo();
    $pdo->exec("INSERT INTO tblpo_list VALUES ('PO-REF-3', 'PO-2603', 'Posted', 0)");
    $pdo->exec("INSERT INTO tblpo_itemlist VALUES (3, 'PO-REF-3', 'ITEM-4', 'SESSION-4', 10, 10)");
    $pdo->exec("INSERT INTO tblpurchase_order VALUES ('RR-REF-2', 'PO-REF-3', 'Delivered')");
    (new PurchaseWorkflowGuard($pdo))->assertItemsAvailable([
        ['item_code' => 'ITEM-4', 'item_id' => 'SESSION-4'],
    ]);
});

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
