<?php

declare(strict_types=1);

/**
 * Regression test: saving a physical count must file the stock adjustment against
 * the item's REAL warehouse (the one its stock lives in), not the synthetic
 * 'CENTRALIZED' display label. Filing under 'CENTRALIZED' hid the adjustment from
 * Stock Movement because every other movement for an item uses a real warehouse
 * (e.g. WH1).
 *
 * Plain PHP, run directly. Everything happens inside a rolled-back transaction so
 * the live/local DB is never mutated.
 *
 * Run:
 *   php api/tests/StockAdjustmentRealWarehouseTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\InventoryAuditRepository;

$mainId = 1;
$passed = 0;
$failed = 0;
$errors = [];

function sarw_assert(bool $condition, string $message, int &$passed, int &$failed, array &$errors): void
{
    if ($condition) {
        $passed++;
        echo "  PASS {$message}\n";
        return;
    }
    $failed++;
    $errors[] = $message;
    echo "  FAIL {$message}\n";
}

echo "Stock Adjustment real-warehouse regression test\n";

$db = new Database(app_config());
$pdo = $db->pdo();
$repo = new InventoryAuditRepository($db);

$uniq = 'TEST-SARW-' . date('YmdHis') . random_int(1000, 9999);
$refno = 'REF-' . $uniq;
$realWarehouse = 'WH1';

// Use an existing active inventory item (read-only) rather than inserting into the
// wide legacy tblinventory_item, which has many NOT-NULL columns without defaults.
$itemStmt = $pdo->prepare(
    'SELECT lsession FROM tblinventory_item
     WHERE lmain_id = :main AND COALESCE(lstatus, 1) = 1 AND COALESCE(lnot_inventory, 0) = 0
       AND TRIM(COALESCE(lpartno, "")) <> "" AND TRIM(COALESCE(litemcode, "")) <> ""
     ORDER BY lid LIMIT 1'
);
$itemStmt->execute(['main' => $mainId]);
$itemSession = (string) ($itemStmt->fetchColumn() ?: '');
if ($itemSession === '') {
    throw new RuntimeException('No active inventory item available for the test');
}

// The legacy inventory tables are non-transactional (MyISAM), so we cannot wrap
// this in a rolled-back transaction under GTID. Seed real rows, then remove exactly
// what we seeded in the finally block. Every seeded row is tagged with $uniq refnos.
$seedRef = 'SEED-' . $uniq;
try {
    // Seed existing stock in the REAL warehouse (WH1) so the resolver has a row to
    // pick up, and so the item has a positive base stock for the difference math.
    $pdo->prepare(
        "INSERT INTO tblinventory_logs
           (linvent_id, lin, lout, ltotal, ldateadded, lstatus_logs, lwarehouse, ltransaction_type, lrefno)
         VALUES (:sess, 100, 0, 100, NOW(), '+', :wh, 'Receiving', :seed_ref)"
    )->execute(['sess' => $itemSession, 'wh' => $realWarehouse, 'seed_ref' => $seedRef]);

    // Compute the item's actual base stock (all warehouses, matching getBaseStock).
    $baseStmt = $pdo->prepare(
        'SELECT CAST(COALESCE(SUM(lin),0) - COALESCE(SUM(lout),0) AS SIGNED)
         FROM tblinventory_logs WHERE linvent_id = :sess'
    );
    $baseStmt->execute(['sess' => $itemSession]);
    $systemStock = (int) ($baseStmt->fetchColumn() ?: 0);
    $physicalCount = $systemStock - 10; // deliberate shortfall of 10

    // Seed a pending stock-adjustment header the save method requires.
    $pdo->prepare(
        "INSERT INTO tblstock_adjustment
           (lrefno, ldatetime, ladjustment_number, luser_id, lmain_id, lstatus, ladjustment_type)
         VALUES (:refno, NOW(), :adjno, '1', :main, 'Pending', 'physical_count')"
    )->execute(['refno' => $refno, 'adjno' => 'SA-' . $uniq, 'main' => (string) $mainId]);

    // Save the physical count. Deliberately pass warehouse 'CENTRALIZED' to prove
    // the old hardcoded behaviour is gone and the real warehouse is resolved instead.
    $repo->saveStockAdjustmentCounts($mainId, $refno, [[
        'item_session' => $itemSession,
        'warehouse' => 'CENTRALIZED',
        'physical_count' => $physicalCount,
        'remarks' => 'SARW test',
    ]]);

    // Adjustment item row must be on the real warehouse, not CENTRALIZED.
    $saiStmt = $pdo->prepare(
        'SELECT lwarehouse, lold_qty, ladjust_qty FROM tblstock_adjustment_item WHERE ladjustment_refno = :refno'
    );
    $saiStmt->execute(['refno' => $refno]);
    $sai = $saiStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    sarw_assert(
        strtoupper((string) ($sai['lwarehouse'] ?? '')) === $realWarehouse,
        "Adjustment item filed under real warehouse {$realWarehouse} (got '" . ($sai['lwarehouse'] ?? '') . "')",
        $passed, $failed, $errors
    );
    sarw_assert(
        (int) ($sai['lold_qty'] ?? -1) === $systemStock && (int) ($sai['ladjust_qty'] ?? -1) === $physicalCount,
        "Adjustment recorded system={$systemStock} / physical={$physicalCount}",
        $passed, $failed, $errors
    );

    // Inventory-log movement row must also be on the real warehouse and be an OUT of 10.
    $logStmt = $pdo->prepare(
        "SELECT lwarehouse, lin, lout, lstatus_logs FROM tblinventory_logs
         WHERE lrefno = :refno AND ltransaction_type = 'Stock Adjustment'"
    );
    $logStmt->execute(['refno' => $refno]);
    $log = $logStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    sarw_assert(
        strtoupper((string) ($log['lwarehouse'] ?? '')) === $realWarehouse,
        "Movement log filed under real warehouse {$realWarehouse} (got '" . ($log['lwarehouse'] ?? '') . "')",
        $passed, $failed, $errors
    );
    sarw_assert(
        strtoupper((string) ($log['lwarehouse'] ?? '')) !== 'CENTRALIZED',
        'Movement log is NOT written as CENTRALIZED',
        $passed, $failed, $errors
    );
    sarw_assert(
        (int) ($log['lout'] ?? 0) === 10 && (string) ($log['lstatus_logs'] ?? '') === '-',
        'Shortfall recorded as OUT 10 (status -)',
        $passed, $failed, $errors
    );
} finally {
    // Remove exactly what we seeded (both the seed log and the adjustment rows).
    $pdo->prepare("DELETE FROM tblinventory_logs WHERE lrefno = :ref AND ltransaction_type = 'Stock Adjustment'")->execute(['ref' => $refno]);
    $pdo->prepare('DELETE FROM tblinventory_logs WHERE lrefno = :ref')->execute(['ref' => $seedRef]);
    $pdo->prepare('DELETE FROM tblstock_adjustment_item WHERE ladjustment_refno = :ref')->execute(['ref' => $refno]);
    $pdo->prepare('DELETE FROM tblstock_adjustment WHERE lrefno = :ref')->execute(['ref' => $refno]);
}

echo "\nPassed: {$passed}; Failed: {$failed}\n";
if ($failed > 0) {
    echo implode("\n", $errors) . "\n";
    exit(1);
}
