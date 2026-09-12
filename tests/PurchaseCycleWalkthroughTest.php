<?php

declare(strict_types=1);

/**
 * Walks a purchase request all the way to a received receiving report, then
 * back out again, asserting that no status along the way is a dead end.
 *
 * PurchaseOrderUnpostCascadeWorkflowTest already covers the recovery loop from
 * a document set that starts out completed. This one starts from an empty Draft
 * request, which is where a user actually starts, and covers the parts that
 * loop does not: the forward path Draft -> Pending -> Approved -> PO -> RR,
 * receiving a purchase order across two deliveries, refusing to receive more
 * than was ordered, and whether a cancelled document can move again.
 *
 * Uses TEMPORARY tables cloned from the real schema, so it writes nothing
 * durable. Remember MySQL cannot open a temporary table twice in one query.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\PurchaseRequestRepository;
use App\Repositories\ReceivingStockRepository;

$passed = 0;
$failed = 0;
$errors = [];

function cycle_assert(bool $condition, string $message): void
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

function cycle_assert_eq(mixed $expected, mixed $actual, string $message): void
{
    cycle_assert(
        $expected === $actual,
        $message . ($expected === $actual ? '' : ' expected=' . json_encode($expected) . ' got=' . json_encode($actual))
    );
}

/** Asserts the callable is refused, and that the refusal says why. */
function cycle_assert_refused(callable $attempt, string $expectedFragment, string $message): void
{
    try {
        $attempt();
    } catch (Throwable $error) {
        cycle_assert(
            stripos($error->getMessage(), $expectedFragment) !== false,
            $message . ' (said: "' . $error->getMessage() . '")'
        );
        return;
    }
    cycle_assert(false, $message . ' (was allowed instead)');
}

function cycle_create_temp_like(PDO $pdo, string $table): void
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

$mainId = 998776;
$userId = 998777;

$tempTables = [
    'tblusertype',
    'tblaccount',
    'tblsupplier',
    'tblinventory_item',
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
        cycle_create_temp_like($pdo, $table);
    }

    $pdo->prepare('INSERT INTO tblusertype (lid, ltype_name) VALUES (1, "Owner")')->execute();
    $pdo->prepare('INSERT INTO tblaccount (lid, lmother_id, ltype, lfname, llname) VALUES (:id, :main_id, "1", "Cycle", "Owner")')
        ->execute(['id' => $userId, 'main_id' => $mainId]);
    $pdo->prepare('INSERT INTO tblsupplier (lid, lmain_id, lcode, lname, lstatus) VALUES (7101, :main_id, "SC1", "Cycle Supplier", 1)')
        ->execute(['main_id' => $mainId]);

    // Receiving resolves the stock item by session, so both request lines need one.
    $insertInventory = $pdo->prepare(
        'INSERT INTO tblinventory_item (lid, lmain_id, lsession, litemcode, lpartno, ldescription, linv_stat)
         VALUES (:id, :main_id, :session, :item_code, :part_no, :description, "1")'
    );
    $insertInventory->execute([
        'id' => 991101,
        'main_id' => $mainId,
        'session' => 'CY-SESSION-1',
        'item_code' => 'CY-ITEM-1',
        'part_no' => 'CY-PART-1',
        'description' => 'Cycle item',
    ]);
    $insertInventory->execute([
        'id' => 991102,
        'main_id' => $mainId,
        'session' => 'CY-SESSION-2',
        'item_code' => 'CY-ITEM-2',
        'part_no' => 'CY-PART-2',
        'description' => 'Cancellable item',
    ]);

    $prRepo = new PurchaseRequestRepository($db);
    $poRepo = new PurchaseOrderRepository($db);
    $rrRepo = new ReceivingStockRepository($db);

    // ---------------------------------------------------------------- forward
    echo "\nForward path: Draft request to received stock\n";

    $draft = $prRepo->createPurchaseRequest($mainId, $userId, [
        'refno' => 'UT-cycle-pr',
        'pr_number' => 'PR-CY-01',
        'status' => 'Draft',
        'approval_status' => 'Pending',
        'items' => [[
            'item_id' => 'CY-SESSION-1',
            'item_code' => 'CY-ITEM-1',
            'part_number' => 'CY-PART-1',
            'description' => 'Cycle item',
            'quantity' => 10,
            'unit_cost' => 50,
            'supplier_id' => '7101',
        ]],
    ]);
    $prRefno = (string) ($draft['request']['refno'] ?? '');
    cycle_assert_eq('Draft', $draft['request']['status'] ?? null, 'a new request starts as Draft');

    cycle_assert_refused(
        fn () => $prRepo->applyAction($mainId, $userId, $prRefno, 'convert-po', []),
        'approved',
        'a Draft request cannot skip straight to a purchase order'
    );

    $submitted = $prRepo->applyAction($mainId, $userId, $prRefno, 'submit', []);
    cycle_assert_eq('Pending', $submitted['request']['status'] ?? null, 'Draft moves forward when submitted for approval');

    $approved = $prRepo->applyAction($mainId, $userId, $prRefno, 'approve', []);
    cycle_assert_eq('Approved', $approved['request']['status'] ?? null, 'a submitted request can be approved');

    $conversion = $prRepo->applyAction($mainId, $userId, $prRefno, 'convert-po', []);
    $poRefno = (string) ($conversion['conversion']['po_refno'] ?? '');
    cycle_assert(1 === (int) ($conversion['conversion']['po_count'] ?? 0), 'an approved request generates a purchase order');
    cycle_assert_eq('Pending', $poRepo->getPurchaseOrder($mainId, $poRefno)['order']['status'] ?? null, 'the new purchase order starts Pending');

    cycle_assert_refused(
        fn () => $rrRepo->createReceivingStock($mainId, $userId, ['po_refno' => $poRefno, 'refno' => 'UT-cycle-rr-early']),
        'posted purchase order',
        'stock cannot be received against a purchase order that is not posted yet'
    );

    $postedPo = $poRepo->updatePurchaseOrder($mainId, $poRefno, ['status' => 'Posted']);
    cycle_assert_eq('Posted', $postedPo['order']['status'] ?? null, 'a Pending purchase order can be posted');

    // ------------------------------------------- receiving across deliveries
    echo "\nReceiving 10 ordered units across two deliveries\n";

    $poItemId = (int) ($poRepo->getPurchaseOrder($mainId, $poRefno)['items'][0]['id'] ?? 0);

    $firstRr = $rrRepo->createReceivingStock($mainId, $userId, [
        'refno' => 'UT-cycle-rr-1',
        'rr_number' => 'RR-CY-01',
        'po_refno' => $poRefno,
    ]);
    $firstRrRefno = (string) ($firstRr['record']['refno'] ?? '');
    $rrRepo->addReceivingStockItem($mainId, $userId, $firstRrRefno, [
        'po_refno' => $poRefno,
        'po_item_id' => $poItemId,
        'item_id' => 'CY-SESSION-1',
        'item_code' => 'CY-ITEM-1',
        'part_number' => 'CY-PART-1',
        'description' => 'Cycle item',
        'qty' => 4,
        'unit_cost' => 50,
    ]);

    cycle_assert_refused(
        fn () => $rrRepo->finalizeReceivingStock($mainId, $firstRrRefno),
        'reason',
        'a short delivery must say why before it can be received'
    );

    $firstReceived = $rrRepo->finalizeReceivingStock(
        $mainId,
        $firstRrRefno,
        'Delivered',
        false,
        'Partial delivery — remaining quantity to follow'
    );
    cycle_assert_eq('Delivered', $firstReceived['record']['status'] ?? null, 'a partial delivery can be received');
    cycle_assert_eq('Posted', $poRepo->getPurchaseOrder($mainId, $poRefno)['order']['status'] ?? null, 'the purchase order stays open while quantity is outstanding');
    cycle_assert_eq('Partially Fulfilled', $prRepo->getPurchaseRequest($mainId, $prRefno)['request']['cycle_status'] ?? null, 'the request reports itself partially fulfilled');

    $secondRr = $rrRepo->createReceivingStock($mainId, $userId, [
        'refno' => 'UT-cycle-rr-2',
        'rr_number' => 'RR-CY-02',
        'po_refno' => $poRefno,
    ]);
    $secondRrRefno = (string) ($secondRr['record']['refno'] ?? '');

    cycle_assert_refused(
        fn () => $rrRepo->addReceivingStockItem($mainId, $userId, $secondRrRefno, [
            'po_refno' => $poRefno,
            'po_item_id' => $poItemId,
            'item_id' => 'CY-SESSION-1',
            'qty' => 99,
            'unit_cost' => 50,
        ]),
        'exceed',
        'more than the outstanding quantity cannot be received'
    );

    $rrRepo->addReceivingStockItem($mainId, $userId, $secondRrRefno, [
        'po_refno' => $poRefno,
        'po_item_id' => $poItemId,
        'item_id' => 'CY-SESSION-1',
        'item_code' => 'CY-ITEM-1',
        'part_number' => 'CY-PART-1',
        'description' => 'Cycle item',
        'qty' => 6,
        'unit_cost' => 50,
    ]);
    $secondReceived = $rrRepo->finalizeReceivingStock($mainId, $secondRrRefno);
    cycle_assert_eq('Delivered', $secondReceived['record']['status'] ?? null, 'the balancing delivery can be received');
    cycle_assert_eq('Completed', $poRepo->getPurchaseOrder($mainId, $poRefno)['order']['status'] ?? null, 'the purchase order completes once everything is received');
    cycle_assert_eq('Completed', $prRepo->getPurchaseRequest($mainId, $prRefno)['request']['cycle_status'] ?? null, 'the request reports the cycle complete');

    // ----------------------------------------------------------- recovery out
    echo "\nUnwinding the whole chain from the request\n";

    $unposted = $prRepo->unpostPurchaseRequest($mainId, $userId, $prRefno, 'Wrong quantity ordered');
    cycle_assert_eq('Unposted', $unposted['request']['status'] ?? null, 'the request can be unposted after the cycle completed');
    cycle_assert_eq(1, count($unposted['cascade']['purchase_orders'] ?? []), 'unposting the request reports the purchase order it took with it');
    cycle_assert_eq(2, count($unposted['cascade']['receiving_reports'] ?? []), 'unposting the request reports both receiving reports');
    cycle_assert_eq('Unposted', $poRepo->getPurchaseOrder($mainId, $poRefno)['order']['status'] ?? null, 'the purchase order came back to Unposted');
    cycle_assert_eq('Unposted', $rrRepo->getReceivingStock($mainId, $firstRrRefno)['record']['status'] ?? null, 'the first receiving report came back to Unposted');
    cycle_assert_eq('Unposted', $rrRepo->getReceivingStock($mainId, $secondRrRefno)['record']['status'] ?? null, 'the second receiving report came back to Unposted');
    cycle_assert_eq(
        0,
        (int) $pdo->query('SELECT COUNT(*) FROM tblinventory_logs WHERE ltransaction_type = "Receiving"')->fetchColumn(),
        'the stock those deliveries added is taken back out'
    );

    // --------------------------------------------------------- forward again
    echo "\nGoing forward again from every Unposted document\n";

    $reapproved = $prRepo->applyAction($mainId, $userId, $prRefno, 'approve', []);
    cycle_assert_eq('Approved', $reapproved['request']['status'] ?? null, 'an Unposted request can be approved again');

    $reposted = $poRepo->updatePurchaseOrder($mainId, $poRefno, ['status' => 'Posted']);
    cycle_assert_eq('Posted', $reposted['order']['status'] ?? null, 'an Unposted purchase order can be posted again');

    // Still a short delivery on its own, so it needs the same reason as before.
    $rereceived = $rrRepo->finalizeReceivingStock(
        $mainId,
        $firstRrRefno,
        'Delivered',
        false,
        'Partial delivery — remaining quantity to follow'
    );
    cycle_assert_eq('Delivered', $rereceived['record']['status'] ?? null, 'an Unposted receiving report can be received again');
    $rereceivedSecond = $rrRepo->finalizeReceivingStock($mainId, $secondRrRefno);
    cycle_assert_eq('Delivered', $rereceivedSecond['record']['status'] ?? null, 'the second receiving report can be received again');
    cycle_assert_eq('Completed', $poRepo->getPurchaseOrder($mainId, $poRefno)['order']['status'] ?? null, 'the purchase order completes again');

    // ------------------------------------------------------------- cancelling
    echo "\nCancelling, and whether a cancelled document can move again\n";

    $cancelPr = $prRepo->createPurchaseRequest($mainId, $userId, [
        'refno' => 'UT-cycle-pr-cancel',
        'pr_number' => 'PR-CY-02',
        'status' => 'Pending',
        'items' => [[
            'item_id' => 'CY-SESSION-2',
            'item_code' => 'CY-ITEM-2',
            'part_number' => 'CY-PART-2',
            'description' => 'Cancellable item',
            'quantity' => 3,
            'unit_cost' => 20,
            'supplier_id' => '7101',
        ]],
    ]);
    $cancelPrRefno = (string) ($cancelPr['request']['refno'] ?? '');
    $cancelled = $prRepo->applyAction($mainId, $userId, $cancelPrRefno, 'cancel', []);
    cycle_assert_eq('Cancelled', $cancelled['request']['status'] ?? null, 'a request with no purchase order can be cancelled');

    $reopened = $prRepo->applyAction($mainId, $userId, $cancelPrRefno, 'submit', []);
    cycle_assert_eq('Pending', $reopened['request']['status'] ?? null, 'the API lets a cancelled request be reopened');

    // A cancelled purchase order must not strand the request that made it: the
    // lines have to become available for a fresh purchase order.
    echo "\nCancelling a purchase order and ordering again\n";

    $retryPr = $prRepo->createPurchaseRequest($mainId, $userId, [
        'refno' => 'UT-cycle-pr-retry',
        'pr_number' => 'PR-CY-03',
        'status' => 'Pending',
        'items' => [[
            'item_id' => 'CY-SESSION-3',
            'item_code' => 'CY-ITEM-3',
            'part_number' => 'CY-PART-3',
            'description' => 'Retried item',
            'quantity' => 2,
            'unit_cost' => 30,
            'supplier_id' => '7101',
        ]],
    ]);
    $retryPrRefno = (string) ($retryPr['request']['refno'] ?? '');
    $prRepo->applyAction($mainId, $userId, $retryPrRefno, 'approve', []);
    $firstAttempt = $prRepo->applyAction($mainId, $userId, $retryPrRefno, 'convert-po', []);
    $abandonedPoRefno = (string) ($firstAttempt['conversion']['po_refno'] ?? '');

    $cancelledPo = $poRepo->updatePurchaseOrder($mainId, $abandonedPoRefno, ['status' => 'Cancelled']);
    cycle_assert_eq('Cancelled', $cancelledPo['order']['status'] ?? null, 'a purchase order can be cancelled');

    $afterCancel = $prRepo->getPurchaseRequest($mainId, $retryPrRefno);
    cycle_assert_eq('', (string) ($afterCancel['items'][0]['po_refno'] ?? 'still-linked'), 'the request line is released when its purchase order is cancelled');

    $secondAttempt = $prRepo->applyAction($mainId, $userId, $retryPrRefno, 'convert-po', []);
    cycle_assert(
        1 === (int) ($secondAttempt['conversion']['po_count'] ?? 0),
        'the request can raise a fresh purchase order after the first was cancelled'
    );

    echo "\nMock PR/PO/RR data used temporary tables only; no persistent rows were inserted.\n";
} catch (Throwable $error) {
    $failed++;
    $errors[] = 'setup or unexpected error: ' . $error->getMessage();
    echo "  FAIL setup or unexpected error: {$error->getMessage()}\n";
    echo '        ' . $error->getFile() . ':' . $error->getLine() . "\n";
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "Errors:\n";
    foreach ($errors as $message) {
        echo "  - {$message}\n";
    }
}
exit($failed === 0 ? 0 : 1);
