<?php

declare(strict_types=1);

/**
 * Old-system compatible Inventory Audit / Stock Adjustment workflow test.
 *
 * Run:
 *   API_BASE_URL=http://127.0.0.1:8081 php api/tests/InventoryAuditWorkflowApiTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;

$apiBase = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$mainId = 1;
$userId = '1';
$passed = 0;
$failed = 0;
$errors = [];

function audit_request(string $method, string $url, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 45,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    return [
        'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body' => json_decode((string) $raw, true),
        'raw' => (string) $raw,
        'error' => curl_error($ch),
    ];
}

function audit_assert(bool $condition, string $message, int &$passed, int &$failed, array &$errors): void
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

function cleanup_audit(PDO $pdo, string $refno): void
{
    if ($refno === '') return;
    $pdo->prepare("DELETE FROM tblinventory_logs WHERE lrefno = :refno AND ltransaction_type = 'Stock Adjustment'")->execute(['refno' => $refno]);
    $pdo->prepare('DELETE FROM tblstock_adjustment_item WHERE ladjustment_refno = :refno')->execute(['refno' => $refno]);
    $pdo->prepare('DELETE FROM tblstock_adjustment WHERE lrefno = :refno')->execute(['refno' => $refno]);
}

echo "Inventory Audit workflow regression test\n";
$health = audit_request('GET', "{$apiBase}/api/v1/health");
audit_assert($health['code'] === 200, 'API health check', $passed, $failed, $errors);
if ($health['code'] !== 200) exit(1);

$db = new Database(app_config());
$pdo = $db->pdo();
$productStmt = $pdo->prepare(
    'SELECT lsession, COALESCE(lpartno, "") AS part_no, COALESCE(litemcode, "") AS item_code
     FROM tblinventory_item
     WHERE lmain_id = :main_id AND COALESCE(lstatus, 1) = 1 AND COALESCE(lnot_inventory, 0) = 0
       AND TRIM(COALESCE(lpartno, "")) <> "" AND TRIM(COALESCE(litemcode, "")) <> ""
     ORDER BY lid LIMIT 1'
);
$productStmt->execute(['main_id' => $mainId]);
$product = $productStmt->fetch(PDO::FETCH_ASSOC);
if ($product === false) throw new RuntimeException('No active inventory product is available for the test');

$warehouseStmt = $pdo->prepare(
    'SELECT UPPER(TRIM(lname)) FROM tblbranch
     WHERE CAST(COALESCE(lmain_id, 0) AS SIGNED) = :main_id AND COALESCE(lstatus, 1) = 1
     ORDER BY lname LIMIT 1'
);
$warehouseStmt->execute(['main_id' => $mainId]);
$warehouse = (string) ($warehouseStmt->fetchColumn() ?: 'WH1');
$itemSession = (string) $product['lsession'];

$stockStmt = $pdo->prepare(
    'SELECT CAST(COALESCE(SUM(lin), 0) - COALESCE(SUM(lout), 0) AS SIGNED)
     FROM tblinventory_logs WHERE linvent_id = :item AND UPPER(COALESCE(lwarehouse, "")) = :warehouse'
);
$stockStmt->execute(['item' => $itemSession, 'warehouse' => $warehouse]);
$baseStock = (int) ($stockStmt->fetchColumn() ?: 0);
$refno = '';

try {
    $create = audit_request('POST', "{$apiBase}/api/v1/inventory-audits/stock-adjustments", [
        'main_id' => $mainId,
        'user_id' => $userId,
    ]);
    $refno = (string) ($create['body']['data']['refno'] ?? '');
    audit_assert($create['code'] === 200 && $refno !== '', 'Create New returns a pending SA header', $passed, $failed, $errors);

    $today = date('Y-m-d');
    $list = audit_request('GET', "{$apiBase}/api/v1/inventory-audits/stock-adjustments?main_id={$mainId}&month=" . date('n') . '&year=' . date('Y'));
    $listed = array_filter($list['body']['data']['items'] ?? [], static fn (array $row): bool => ($row['refno'] ?? '') === $refno);
    audit_assert($list['code'] === 200 && count($listed) === 1, 'Month list includes an empty newly-created SA', $passed, $failed, $errors);

    $detailUrl = "{$apiBase}/api/v1/inventory-audits/stock-adjustments/" . rawurlencode($refno)
        . '?main_id=' . $mainId . '&part_no=' . rawurlencode((string) $product['part_no'])
        . '&item_code=' . rawurlencode((string) $product['item_code']) . '&page=1&per_page=20';
    $detail = audit_request('GET', $detailUrl);
    audit_assert($detail['code'] === 200 && count($detail['body']['data']['items'] ?? []) >= 1, 'Part No. and Item Code filters load inventory', $passed, $failed, $errors);

    $save = audit_request('POST', "{$apiBase}/api/v1/inventory-audits/stock-adjustments/" . rawurlencode($refno) . '/counts', [
        'main_id' => $mainId,
        'entries' => [[
            'item_session' => $itemSession,
            'warehouse' => $warehouse,
            'physical_count' => $baseStock + 1,
            'remarks' => 'Automated parity test',
        ]],
    ]);
    audit_assert($save['code'] === 200, 'Saving a physical count succeeds', $passed, $failed, $errors);

    $itemCountStmt = $pdo->prepare('SELECT COUNT(*) FROM tblstock_adjustment_item WHERE ladjustment_refno = :refno');
    $itemCountStmt->execute(['refno' => $refno]);
    $logCountStmt = $pdo->prepare("SELECT COUNT(*) FROM tblinventory_logs WHERE lrefno = :refno AND ltransaction_type = 'Stock Adjustment'");
    $logCountStmt->execute(['refno' => $refno]);
    audit_assert((int) $itemCountStmt->fetchColumn() === 1 && (int) $logCountStmt->fetchColumn() === 1, 'Count creates both adjustment detail and immediate inventory log', $passed, $failed, $errors);

    $deleteItem = audit_request('DELETE', "{$apiBase}/api/v1/inventory-audits/stock-adjustments/" . rawurlencode($refno) . '/items/' . rawurlencode($itemSession) . "?main_id={$mainId}");
    audit_assert($deleteItem['code'] === 200 && ($deleteItem['body']['data']['deleted'] ?? false) === true, 'Deleting an item reverses its adjustment', $passed, $failed, $errors);

    $saveAgain = audit_request('POST', "{$apiBase}/api/v1/inventory-audits/stock-adjustments/" . rawurlencode($refno) . '/counts', [
        'main_id' => $mainId,
        'entries' => [['item_session' => $itemSession, 'warehouse' => $warehouse, 'physical_count' => $baseStock + 1]],
    ]);
    audit_assert($saveAgain['code'] === 200, 'Adjustment can be re-entered while pending', $passed, $failed, $errors);

    $dateUpdate = audit_request('PATCH', "{$apiBase}/api/v1/inventory-audits/stock-adjustments/" . rawurlencode($refno) . '/date', [
        'main_id' => $mainId,
        'date' => $today,
    ]);
    audit_assert($dateUpdate['code'] === 200, 'Pending SA date and logs can be updated together', $passed, $failed, $errors);

    $post = audit_request('POST', "{$apiBase}/api/v1/inventory-audits/stock-adjustments/" . rawurlencode($refno) . '/post', ['main_id' => $mainId, 'user_id' => $userId]);
    audit_assert($post['code'] === 200 && ($post['body']['data']['status'] ?? '') === 'Posted', 'Post locks the SA', $passed, $failed, $errors);

    $lockedDelete = audit_request('DELETE', "{$apiBase}/api/v1/inventory-audits/stock-adjustments/" . rawurlencode($refno) . "?main_id={$mainId}");
    audit_assert($lockedDelete['code'] === 422, 'Posted SA cannot be deleted', $passed, $failed, $errors);
    $lockedDate = audit_request('PATCH', "{$apiBase}/api/v1/inventory-audits/stock-adjustments/" . rawurlencode($refno) . '/date', ['main_id' => $mainId, 'date' => $today]);
    audit_assert($lockedDate['code'] === 422, 'Posted SA date cannot be edited', $passed, $failed, $errors);
} finally {
    cleanup_audit($pdo, $refno);
}

echo "\nPassed: {$passed}; Failed: {$failed}\n";
if ($failed > 0) {
    echo implode("\n", $errors) . "\n";
    exit(1);
}
