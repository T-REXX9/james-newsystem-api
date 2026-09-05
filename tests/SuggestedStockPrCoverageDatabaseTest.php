<?php

declare(strict_types=1);

/**
 * Suggested-stock visibility follows Live Purchase Request coverage.
 *
 * Run:
 *   php api/tests/SuggestedStockPrCoverageDatabaseTest.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\LocalRecycleBinRepository;
use App\Repositories\PurchaseRequestRepository;
use App\Repositories\SuggestedStockReportRepository;

$ROOT = dirname(__DIR__);
$vars = [];
foreach (file($ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $vars[trim($key)] = trim($value);
}

$dbHost = (string) ($vars['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1');
$dbPort = (int) ($vars['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
$dbName = (string) ($vars['DB_NAME'] ?? getenv('DB_NAME') ?: 'topnotch_migrate');
$dbUser = (string) ($vars['DB_USER'] ?? getenv('DB_USER') ?: 'root');
$dbPass = (string) ($vars['DB_PASS'] ?? getenv('DB_PASS') ?: '');
$MAIN_ID = 1;
$USER_ID = 1;

$seed = 'UT-SSPR-' . date('YmdHis') . '-' . random_int(1000, 9999);
$prefix = $seed . '-';
$today = date('Y-m-d');

$inquiryRef = $prefix . 'inq';
$customerId = $prefix . 'customer';
$partNo = $prefix . 'PN';
$itemCode = $prefix . 'ITEM';
$description = 'Coverage restore test part';
$notListedPart = $prefix . 'PN-NL';
$notListedCode = $prefix . 'ITEM-NL';
$notListedDesc = 'NotListed coverage control';
$productSession = $prefix . 'product';
$kivPart = $prefix . 'PN-KIV';
$kivCode = $prefix . 'ITEM-KIV';
$kivDesc = 'KIV coverage part';
$kivSession = $prefix . 'product-kiv';
$strandedPart = $prefix . 'PN-STRAND';
$strandedCode = $prefix . 'ITEM-STRAND';
$strandedDesc = 'Stranded AddedToPR part';
$secondPrRef = $prefix . 'pr-cover';

$passed = 0;
$failed = 0;
$errors = [];
$createdPrRefnos = [];
$createdInquiryItemIds = [];

function sspr_assert(bool $condition, string $message, int &$passed, int &$failed, array &$errors): void
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

function sspr_summary_parts(array $summary): array
{
    return array_values(array_map(
        static fn(array $row): string => (string) ($row['part_no'] ?? ''),
        $summary['items'] ?? []
    ));
}

$config = new Config('test', true, '*', 'secret', 3600, $dbHost, $dbPort, $dbName, $dbUser, $dbPass);
$db = new Database($config);
$pdo = $db->pdo();
$suggestedRepo = new SuggestedStockReportRepository($db);
$prRepo = new PurchaseRequestRepository($db);
$recycle = new LocalRecycleBinRepository($db);

$cleanup = static function () use (
    $pdo,
    $inquiryRef,
    $partNo,
    $notListedPart,
    $kivPart,
    $strandedPart,
    $productSession,
    $kivSession,
    $secondPrRef,
    &$createdPrRefnos,
    &$createdInquiryItemIds
): void {
    $prRefnos = array_values(array_unique(array_filter([...$createdPrRefnos, $secondPrRef])));
    if ($prRefnos !== []) {
        $placeholders = implode(',', array_fill(0, count($prRefnos), '?'));
        $pdo->prepare("DELETE FROM tblpr_item WHERE lrefno IN ({$placeholders})")->execute($prRefnos);
        $pdo->prepare("DELETE FROM tblpr_list WHERE lrefno IN ({$placeholders})")->execute($prRefnos);
    }
    if ($createdInquiryItemIds !== []) {
        $placeholders = implode(',', array_fill(0, count($createdInquiryItemIds), '?'));
        $pdo->prepare("DELETE FROM tblinquiry_item WHERE lid IN ({$placeholders})")
            ->execute(array_values($createdInquiryItemIds));
    }
    $pdo->prepare('DELETE FROM tblinquiry_item WHERE linq_refno = ?')->execute([$inquiryRef]);
    $pdo->prepare('DELETE FROM tblinquiry WHERE lrefno = ?')->execute([$inquiryRef]);
    $pdo->prepare('DELETE FROM suggested_stock_kiv WHERE part_no IN (?, ?, ?, ?)')
        ->execute([$partNo, $notListedPart, $kivPart, $strandedPart]);
    foreach ([$productSession, $kivSession] as $session) {
        $pdo->prepare('DELETE FROM tblinventory_price WHERE linv_refno = ?')->execute([$session]);
        $pdo->prepare('DELETE FROM tblinventory_logs WHERE linventory_id = ?')->execute([$session]);
        $pdo->prepare('DELETE FROM tblinventory_item WHERE lsession = ?')->execute([$session]);
    }
};

$insertInquiry = static function () use ($pdo, $MAIN_ID, $USER_ID, $customerId, $today, $inquiryRef, $prefix): void {
    $stmt = $pdo->prepare(
        'INSERT INTO tblinquiry
        (linqno, ldate, ltime, lcustomerid, lmain_id, luser, lrefno, lcompany, lsalesperson, ltransaction_status, lsubmitstat, IsCancel)
        VALUES
        (:linqno, :ldate, CURRENT_TIME(), :customer_id, :main_id, :user_id, :refno, :company, :salesperson, "Unposted", "Pending", 0)'
    );
    $stmt->execute([
        'linqno' => $prefix . 'INQ',
        'ldate' => $today,
        'customer_id' => $customerId,
        'main_id' => (string) $MAIN_ID,
        'user_id' => (string) $USER_ID,
        'refno' => $inquiryRef,
        'company' => 'Suggested Stock PR Coverage Customer',
        'salesperson' => 'Unit Test',
    ]);
};

$insertInquiryItem = static function (
    string $part,
    string $code,
    string $desc,
    string $remark
) use ($pdo, $today, $inquiryRef, $prefix, &$createdInquiryItemIds): int {
    $stmt = $pdo->prepare(
        'INSERT INTO tblinquiry_item
        (linq_no, linq_refno, lqty, lprice, litem_code, lpartno, ldesc, lremark, linquiry_date, lapproved)
        VALUES
        (:linq_no, :linq_refno, 3, 0, :item_code, :part_no, :description, :remark, :inquiry_date, 1)'
    );
    $stmt->execute([
        'linq_no' => $prefix . 'INQ',
        'linq_refno' => $inquiryRef,
        'item_code' => $code,
        'part_no' => $part,
        'description' => $desc,
        'remark' => $remark,
        'inquiry_date' => $today,
    ]);
    $itemId = (int) $pdo->lastInsertId();
    $createdInquiryItemIds[] = $itemId;
    return $itemId;
};

$insertProduct = static function (string $session, string $code, string $part, string $desc) use ($pdo, $MAIN_ID, $USER_ID): void {
    $stmt = $pdo->prepare(
        'INSERT INTO tblinventory_item
        (lsession, lmain_id, litemcode, ldescription, lpartno, lreorder_amt, lstatus, lnot_inventory, ldeleted, linv_stat, ltrackable, ldateadded, laddedby)
        VALUES
        (:session, :main_id, :item_code, :description, :part_no, 5, 1, 0, 0, "", "Yes", CURDATE(), :user_id)'
    );
    $stmt->execute([
        'session' => $session,
        'main_id' => $MAIN_ID,
        'item_code' => $code,
        'description' => $desc,
        'part_no' => $part,
        'user_id' => $USER_ID,
    ]);
};

$remarkFor = static function (string $part) use ($pdo, $inquiryRef): string {
    $stmt = $pdo->prepare('SELECT COALESCE(lremark, "") FROM tblinquiry_item WHERE linq_refno = :refno AND lpartno = :part LIMIT 1');
    $stmt->execute(['refno' => $inquiryRef, 'part' => $part]);
    return (string) ($stmt->fetchColumn() ?: '');
};

$kivCount = static function (string $part) use ($pdo, $MAIN_ID): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM suggested_stock_kiv WHERE main_id = :main_id AND part_no = :part');
    $stmt->execute(['main_id' => (string) $MAIN_ID, 'part' => $part]);
    return (int) $stmt->fetchColumn();
};

$createCoveringPr = static function (string $session, string $code, string $part, string $desc) use ($prRepo, $MAIN_ID, $USER_ID, $today, $prefix, &$createdPrRefnos): array {
    $created = $prRepo->createPurchaseRequest($MAIN_ID, $USER_ID, [
        'pr_number' => $prefix . 'PR-' . substr($part, -6),
        'request_date' => $today,
        'notes' => 'Suggested stock coverage test',
        'items' => [[
            'item_id' => $session,
            'item_code' => $code,
            'part_number' => $part,
            'description' => $desc,
            'quantity' => 3,
            'unit_cost' => 0,
        ]],
    ]);
    $refno = (string) ($created['request']['refno'] ?? '');
    $createdPrRefnos[] = $refno;
    return $created;
};

echo "==========================================================\n";
echo " Suggested Stock PR Coverage Database Test\n";
echo " Database: {$dbName}@{$dbHost}\n";
echo " Seed prefix: {$prefix}\n";
echo "==========================================================\n\n";

try {
    $cleanup();
    $insertInquiry();
    $insertProduct($productSession, $itemCode, $partNo, $description);
    $insertProduct($kivSession, $kivCode, $kivPart, $kivDesc);
    $insertInquiryItem($partNo, $itemCode, $description, 'ProductCreated');
    $insertInquiryItem($notListedPart, $notListedCode, $notListedDesc, 'NotListed');
    $insertInquiryItem($kivPart, $kivCode, $kivDesc, 'ProductCreated');
    $insertInquiryItem($strandedPart, $strandedCode, $strandedDesc, 'AddedToPR');

    $summary = $suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200);
    sspr_assert(in_array($partNo, sspr_summary_parts($summary), true), 'Product Created suggestion starts on the active report', $passed, $failed, $errors);

    $pr = $createCoveringPr($productSession, $itemCode, $partNo, $description);
    $prRefno = (string) ($pr['request']['refno'] ?? '');
    $prItemId = (int) ($pr['items'][0]['id'] ?? 0);
    $suggestedRepo->markAddedToPurchaseRequest($MAIN_ID, [[
        'part_no' => $partNo,
        'item_code' => $itemCode,
        'description' => $description,
    ]]);
    sspr_assert(
        !in_array($partNo, sspr_summary_parts($suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200)), true),
        'adding to a Purchase Request hides the suggestion as AddedToPR',
        $passed,
        $failed,
        $errors
    );

    $prRepo->applyAction($MAIN_ID, $USER_ID, $prRefno, 'cancel', []);
    sspr_assert(
        !in_array($partNo, sspr_summary_parts($suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200)), true),
        'cancelling a Live Purchase Request does not return the suggestion',
        $passed,
        $failed,
        $errors
    );

    $pdo->prepare('UPDATE tblpr_list SET lstatus = "Submitted", lapproval = "Approved" WHERE lrefno = ?')->execute([$prRefno]);
    $prRepo->unpostPurchaseRequest($MAIN_ID, $USER_ID, $prRefno, 'coverage unpost test');
    sspr_assert(
        !in_array($partNo, sspr_summary_parts($suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200)), true),
        'unposting a Live Purchase Request does not return the suggestion',
        $passed,
        $failed,
        $errors
    );

    $notListedBefore = $remarkFor($notListedPart);
    $prRepo->deletePurchaseRequest($MAIN_ID, $USER_ID, $prRefno, 'coverage delete test');
    sspr_assert(
        in_array($partNo, sspr_summary_parts($suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200)), true),
        'deleting the covering Purchase Request returns the suggestion as Ready for PR',
        $passed,
        $failed,
        $errors
    );
    sspr_assert($remarkFor($partNo) === 'ProductCreated', 'restored suggestion remark is ProductCreated', $passed, $failed, $errors);
    sspr_assert($remarkFor($notListedPart) === $notListedBefore, 'NotListed inquiries are not rewritten by coverage sync', $passed, $failed, $errors);

    $recycle->restore($MAIN_ID, 'purchase_request', $prRefno);
    sspr_assert(
        !in_array($partNo, sspr_summary_parts($suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200)), true),
        'restoring the Purchase Request from the recycle bin hides the suggestion again',
        $passed,
        $failed,
        $errors
    );
    sspr_assert($remarkFor($partNo) === 'AddedToPR', 'recycle-bin restore stamps AddedToPR again', $passed, $failed, $errors);

    $pdo->prepare(
        'INSERT INTO tblpr_list (lrefno, lprno, ldatetime, luser, lstatus, lremark, lapproval, ldeleted)
         VALUES (?, ?, NOW(), ?, "Pending", "second covering PR", "Pending", 0)'
    )->execute([$secondPrRef, $prefix . 'PR-2', (string) $USER_ID]);
    $pdo->prepare(
        'INSERT INTO tblpr_item (lrefno, litem_code, lpart_no, lqty, lcost, lstatus, ldesc)
         VALUES (?, ?, ?, 1, 0, "Pending", ?)'
    )->execute([$secondPrRef, $itemCode, $partNo, $description]);

    $prRepo->deletePurchaseRequest($MAIN_ID, $USER_ID, $prRefno, 'delete one of two covering requests');
    sspr_assert(
        !in_array($partNo, sspr_summary_parts($suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200)), true),
        'suggestion stays hidden when another Live Purchase Request still covers it',
        $passed,
        $failed,
        $errors
    );

    $pdo->prepare('DELETE FROM tblpr_item WHERE lrefno = ?')->execute([$secondPrRef]);
    $pdo->prepare('DELETE FROM tblpr_list WHERE lrefno = ?')->execute([$secondPrRef]);
    $recycle->restore($MAIN_ID, 'purchase_request', $prRefno);
    $pdo->prepare(
        'INSERT INTO tblpr_item (lrefno, litem_code, lpart_no, lqty, lcost, lstatus, ldesc)
         VALUES (?, ?, ?, 1, 0, "Pending", ?)'
    )->execute([$prRefno, $itemCode, $partNo, $description]);
    $lineStmt = $pdo->prepare('SELECT lid FROM tblpr_item WHERE lrefno = ? ORDER BY lid ASC');
    $lineStmt->execute([$prRefno]);
    $lineIds = array_map('intval', $lineStmt->fetchAll(PDO::FETCH_COLUMN));
    $prRepo->deletePurchaseRequestItem($MAIN_ID, $lineIds[0]);
    sspr_assert(
        !in_array($partNo, sspr_summary_parts($suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200)), true),
        'suggestion stays hidden when another live line still covers it',
        $passed,
        $failed,
        $errors
    );
    $prRepo->deletePurchaseRequestItem($MAIN_ID, $lineIds[1]);
    sspr_assert(
        in_array($partNo, sspr_summary_parts($suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200)), true),
        'removing the covering line returns the suggestion as Ready for PR',
        $passed,
        $failed,
        $errors
    );

    $suggestedRepo->addToKiv($MAIN_ID, [[
        'part_no' => $kivPart,
        'item_code' => $kivCode,
        'description' => $kivDesc,
    ]], 'coverage-test');
    $kivPr = $createCoveringPr($kivSession, $kivCode, $kivPart, $kivDesc);
    $kivPrRef = (string) ($kivPr['request']['refno'] ?? '');
    $suggestedRepo->markAddedToPurchaseRequest($MAIN_ID, [[
        'part_no' => $kivPart,
        'item_code' => $kivCode,
        'description' => $kivDesc,
    ]]);
    $kivBefore = $kivCount($kivPart);
    $prRepo->deletePurchaseRequest($MAIN_ID, $USER_ID, $kivPrRef, 'kiv coverage delete');
    sspr_assert($kivCount($kivPart) === $kivBefore, 'KIV membership is unchanged when coverage is restored', $passed, $failed, $errors);
    sspr_assert(
        in_array($kivPart, sspr_summary_parts($suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200, null, SuggestedStockReportRepository::SORT_QTY_DESC, true)), true),
        'restored KIV suggestion remains in the KIV folder view',
        $passed,
        $failed,
        $errors
    );

    $repaired = $suggestedRepo->repairUncoveredAddedToPr($MAIN_ID);
    sspr_assert($repaired >= 1, 'repair returns stranded AddedToPR rows', $passed, $failed, $errors);
    sspr_assert(
        in_array($strandedPart, sspr_summary_parts($suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200)), true),
        'stranded AddedToPR demand reappears as Ready for PR after repair',
        $passed,
        $failed,
        $errors
    );
    sspr_assert($remarkFor($strandedPart) === 'ProductCreated', 'repaired remark is ProductCreated', $passed, $failed, $errors);
} catch (Throwable $error) {
    $failed++;
    $errors[] = $error->getMessage();
    echo '  FAIL ' . $error->getMessage() . "\n";
} finally {
    $cleanup();
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
if ($errors !== []) {
    echo "Failures:\n- " . implode("\n- ", $errors) . "\n";
}
exit($failed === 0 ? 0 : 1);
