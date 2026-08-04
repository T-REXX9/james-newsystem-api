<?php

declare(strict_types=1);

use App\Repositories\PurchaseWorkflowGuard;

require_once __DIR__ . '/../src/Repositories/PurchaseWorkflowGuard.php';

function workflowPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE tblpr_list (lrefno TEXT, lprno TEXT, lstatus TEXT)');
    $pdo->exec('CREATE TABLE tblpr_item (lid INTEGER, lrefno TEXT, litem_code TEXT, litem_refno TEXT, lpo_refno TEXT)');
    $pdo->exec('CREATE TABLE tblpo_list (lrefno TEXT, lpurchaseno TEXT, ltransaction_status TEXT)');
    $pdo->exec('CREATE TABLE tblpo_itemlist (lid INTEGER, lrefno TEXT, litem_code TEXT, litem_refno TEXT)');
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
    $pdo->exec("INSERT INTO tblpr_list VALUES ('PR-REF-1', 'PR-2601', 'Pending')");
    $pdo->exec("INSERT INTO tblpr_item VALUES (1, 'PR-REF-1', 'ITEM-1', 'SESSION-1', '')");
    $guard = new PurchaseWorkflowGuard($pdo);
    expectWorkflowConflict(
        static fn () => $guard->assertItemsAvailable([['item_code' => 'ITEM-1', 'item_id' => 'SESSION-1']]),
        'PR-2601'
    );
});

$run('blocks an item already in an active PO', static function (): void {
    $pdo = workflowPdo();
    $pdo->exec("INSERT INTO tblpo_list VALUES ('PO-REF-1', 'PO-2601', 'Posted')");
    $pdo->exec("INSERT INTO tblpo_itemlist VALUES (1, 'PO-REF-1', 'ITEM-2', 'SESSION-2')");
    $guard = new PurchaseWorkflowGuard($pdo);
    expectWorkflowConflict(
        static fn () => $guard->assertItemsAvailable([['item_code' => 'ITEM-2', 'item_id' => 'SESSION-2']]),
        'PO-2601'
    );
});

$run('allows a new workflow after receiving is completed', static function (): void {
    $pdo = workflowPdo();
    $pdo->exec("INSERT INTO tblpo_list VALUES ('PO-REF-2', 'PO-2602', 'Posted')");
    $pdo->exec("INSERT INTO tblpo_itemlist VALUES (2, 'PO-REF-2', 'ITEM-3', 'SESSION-3')");
    $pdo->exec("INSERT INTO tblpurchase_order VALUES ('RR-REF-1', 'PO-REF-2', 'Posted')");
    (new PurchaseWorkflowGuard($pdo))->assertItemsAvailable([
        ['item_code' => 'ITEM-3', 'item_id' => 'SESSION-3'],
    ]);
});

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
