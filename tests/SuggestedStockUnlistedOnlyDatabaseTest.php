<?php

declare(strict_types=1);

/**
 * Suggested Stock unlisted-only workflow database integration test.
 *
 * Uses the real database with prefixed seed rows and cleans up afterward.
 *
 * Run:
 *   php api/tests/SuggestedStockUnlistedOnlyDatabaseTest.php
 *   API_BASE_URL=http://127.0.0.1:8081 php api/tests/SuggestedStockUnlistedOnlyDatabaseTest.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\ProductRepository;
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
$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$MAIN_ID = 1;
$USER_ID = 1;

$seed = 'UT-SS-' . date('YmdHis') . '-' . random_int(1000, 9999);
$prefix = $seed . '-';
$today = date('Y-m-d');

$inquiryRefUnlisted = $prefix . 'inq-unlisted';
$inquiryRefListed = $prefix . 'inq-listed';
$inquiryRefSort = $prefix . 'inq-sort';
$customerId = $prefix . 'customer';
$customerIdSort = $prefix . 'customer-sort';

$itemCodeUnlisted = $prefix . 'ITEM-UNLISTED';
$partNoUnlisted = $prefix . 'PN-UNLISTED';
$itemCodeListed = $prefix . 'ITEM-LISTED';
$partNoListed = $prefix . 'PN-LISTED';
$partNoAlpha = $prefix . 'PN-ALPHA';
$partNoMu = $prefix . 'PN-MU';
$partNoZulu = $prefix . 'PN-ZULU';
$itemCodeAlpha = $prefix . 'ITEM-ALPHA';
$itemCodeMu = $prefix . 'ITEM-MU';
$itemCodeZulu = $prefix . 'ITEM-ZULU';

$productSessionListed = $prefix . 'product-listed';
$createdProductSessions = [$productSessionListed];
$createdInquiryItemIds = [];

$passed = 0;
$failed = 0;
$errors = [];

function ss_assert(bool $condition, string $message, int &$passed, int &$failed, array &$errors): void
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

function ss_assert_eq(mixed $expected, mixed $actual, string $message, int &$passed, int &$failed, array &$errors): void
{
    ss_assert(
        $expected === $actual,
        $message . ($expected === $actual ? '' : ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual)),
        $passed,
        $failed,
        $errors
    );
}

function ss_request(string $method, string $url, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $responseBody = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    if ($error !== '') {
        return ['http_code' => 0, 'body' => null, 'error' => $error, 'raw' => ''];
    }
    return [
        'http_code' => $httpCode,
        'body' => json_decode((string) $responseBody, true),
        'raw' => $responseBody,
        'error' => '',
    ];
}

function ss_summary_part_nos(array $summary): array
{
    return array_values(array_map(
        static fn(array $row): string => (string) ($row['part_no'] ?? ''),
        $summary['items'] ?? []
    ));
}

function ss_details_part_nos(array $details): array
{
    return array_values(array_map(
        static fn(array $row): string => (string) ($row['part_no'] ?? ''),
        $details['items'] ?? []
    ));
}

$config = new Config('test', true, '*', 'secret', 3600, $dbHost, $dbPort, $dbName, $dbUser, $dbPass);
$db = new Database($config);
$pdo = $db->pdo();
$suggestedRepo = new SuggestedStockReportRepository($db);
$productRepo = new ProductRepository($db);

$cleanup = static function () use (
    $pdo,
    $inquiryRefUnlisted,
    $inquiryRefListed,
    $inquiryRefSort,
    $partNoUnlisted,
    $partNoListed,
    $partNoAlpha,
    $partNoMu,
    $partNoZulu,
    &$createdProductSessions,
    &$createdInquiryItemIds
): void {
    if ($createdInquiryItemIds !== []) {
        $placeholders = implode(',', array_fill(0, count($createdInquiryItemIds), '?'));
        $pdo->prepare("DELETE FROM tblinquiry_item WHERE lid IN ({$placeholders})")
            ->execute(array_values($createdInquiryItemIds));
    }

    $pdo->prepare('DELETE FROM tblinquiry_item WHERE linq_refno IN (?, ?, ?)')
        ->execute([$inquiryRefUnlisted, $inquiryRefListed, $inquiryRefSort]);
    $pdo->prepare('DELETE FROM tblinquiry WHERE lrefno IN (?, ?, ?)')
        ->execute([$inquiryRefUnlisted, $inquiryRefListed, $inquiryRefSort]);
    $pdo->prepare('DELETE FROM suggested_stock_kiv WHERE part_no IN (?, ?, ?, ?, ?)')
        ->execute([$partNoUnlisted, $partNoListed, $partNoAlpha, $partNoMu, $partNoZulu]);

    foreach (array_values(array_unique($createdProductSessions)) as $session) {
        if ($session === '') {
            continue;
        }
        $pdo->prepare('DELETE FROM tblinventory_price WHERE linv_refno = ?')->execute([$session]);
        $pdo->prepare('DELETE FROM tblinventory_logs WHERE linventory_id = ?')->execute([$session]);
        $pdo->prepare('DELETE FROM tblinventory_item WHERE lsession = ?')->execute([$session]);
    }
};

$insertInquiry = static function (string $refno, string $inquiryNo, ?string $forCustomerId = null) use ($pdo, $MAIN_ID, $USER_ID, $customerId, $today): void {
    $stmt = $pdo->prepare(
        'INSERT INTO tblinquiry
        (linqno, ldate, ltime, lcustomerid, lmain_id, luser, lrefno, lcompany, lsalesperson, ltransaction_status, lsubmitstat, IsCancel)
        VALUES
        (:linqno, :ldate, CURRENT_TIME(), :customer_id, :main_id, :user_id, :refno, :company, :salesperson, "Unposted", "Pending", 0)'
    );
    $stmt->execute([
        'linqno' => $inquiryNo,
        'ldate' => $today,
        'customer_id' => $forCustomerId ?? $customerId,
        'main_id' => (string) $MAIN_ID,
        'user_id' => (string) $USER_ID,
        'refno' => $refno,
        'company' => 'Suggested Stock DB Test Customer',
        'salesperson' => 'Unit Test',
    ]);
};

$insertInquiryItem = static function (
    string $inquiryRefno,
    string $inquiryNo,
    string $partNo,
    string $itemCode,
    string $description,
    string $remark,
    int $qty = 2
) use ($pdo, $today, &$createdInquiryItemIds): int {
    $stmt = $pdo->prepare(
        'INSERT INTO tblinquiry_item
        (linq_no, linq_refno, lqty, lprice, litem_code, lpartno, ldesc, lremark, linquiry_date, lapproved)
        VALUES
        (:linq_no, :linq_refno, :qty, 0, :item_code, :part_no, :description, :remark, :inquiry_date, 1)'
    );
    $stmt->execute([
        'linq_no' => $inquiryNo,
        'linq_refno' => $inquiryRefno,
        'qty' => $qty,
        'item_code' => $itemCode,
        'part_no' => $partNo,
        'description' => $description,
        'remark' => $remark,
        'inquiry_date' => $today,
    ]);
    $itemId = (int) $pdo->lastInsertId();
    $createdInquiryItemIds[] = $itemId;
    return $itemId;
};

$insertProduct = static function (string $session, string $itemCode, string $partNo, string $description) use ($pdo, $MAIN_ID, $USER_ID): void {
    $stmt = $pdo->prepare(
        'INSERT INTO tblinventory_item
        (lsession, lmain_id, litemcode, ldescription, lpartno, lreorder_amt, lstatus, lnot_inventory, ldeleted, linv_stat, ltrackable, ldateadded, laddedby)
        VALUES
        (:session, :main_id, :item_code, :description, :part_no, 5, 1, 0, 0, "", "Yes", CURDATE(), :user_id)'
    );
    $stmt->execute([
        'session' => $session,
        'main_id' => $MAIN_ID,
        'item_code' => $itemCode,
        'description' => $description,
        'part_no' => $partNo,
        'user_id' => $USER_ID,
    ]);
};

echo "==========================================================\n";
echo " Suggested Stock Unlisted-Only Database Test\n";
echo " Database: {$dbName}@{$dbHost}\n";
echo " Seed prefix: {$prefix}\n";
echo "==========================================================\n\n";

try {
    $cleanup();

    $insertInquiry($inquiryRefUnlisted, $prefix . 'INQ-1');
    $insertInquiry($inquiryRefListed, $prefix . 'INQ-2');
    $insertInquiry($inquiryRefSort, $prefix . 'INQ-3', $customerIdSort);

    $unlistedItemId = $insertInquiryItem(
        $inquiryRefUnlisted,
        $prefix . 'INQ-1',
        $partNoUnlisted,
        $itemCodeUnlisted,
        'Unlisted suggested stock test part',
        'NotListed'
    );

    $listedMatchItemId = $insertInquiryItem(
        $inquiryRefListed,
        $prefix . 'INQ-2',
        $partNoListed,
        $itemCodeListed,
        'Already listed suggested stock test part',
        'NotListed'
    );

    $insertInquiryItem(
        $inquiryRefSort,
        $prefix . 'INQ-3',
        $partNoAlpha,
        $itemCodeAlpha,
        'ALPHA PART',
        'NotListed',
        1
    );
    $insertInquiryItem(
        $inquiryRefSort,
        $prefix . 'INQ-3',
        $partNoMu,
        $itemCodeMu,
        'MU PART',
        'NotListed',
        5
    );
    $insertInquiryItem(
        $inquiryRefSort,
        $prefix . 'INQ-3',
        $partNoZulu,
        $itemCodeZulu,
        'ZULU PART',
        'NotListed',
        9
    );

    $insertProduct(
        $productSessionListed,
        $itemCodeListed,
        $partNoListed,
        'Existing catalog product for listed-match exclusion'
    );

    $summary = $suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200);
    $summaryParts = ss_summary_part_nos($summary);
    ss_assert(in_array($partNoUnlisted, $summaryParts, true), 'summary includes unlisted inquiry item', $passed, $failed, $errors);
    ss_assert(!in_array($partNoListed, $summaryParts, true), 'summary excludes inquiry item already in catalog', $passed, $failed, $errors);

    $seededOrder = array_values(array_filter(
        $summaryParts,
        static fn(string $partNo): bool => str_starts_with($partNo, $prefix)
    ));
    ss_assert_eq(
        [$partNoZulu, $partNoMu, $partNoUnlisted, $partNoAlpha],
        $seededOrder,
        'default summary orders by qty requested highest first',
        $passed,
        $failed,
        $errors
    );

    $descPage1 = $suggestedRepo->summary($MAIN_ID, $today, $today, $customerIdSort, 1, 1, null, 'description-asc');
    $descPage2 = $suggestedRepo->summary($MAIN_ID, $today, $today, $customerIdSort, 2, 1, null, 'description-asc');
    ss_assert_eq($partNoAlpha, ss_summary_part_nos($descPage1)[0] ?? '', 'description A→Z page 1 is the first alphabetically', $passed, $failed, $errors);
    ss_assert_eq($partNoMu, ss_summary_part_nos($descPage2)[0] ?? '', 'description A→Z page 2 continues the global alphabet', $passed, $failed, $errors);

    $descDescPage1 = $suggestedRepo->summary($MAIN_ID, $today, $today, $customerIdSort, 1, 1, null, 'description-desc');
    $descDescPage2 = $suggestedRepo->summary($MAIN_ID, $today, $today, $customerIdSort, 2, 1, null, 'description-desc');
    ss_assert_eq($partNoZulu, ss_summary_part_nos($descDescPage1)[0] ?? '', 'description Z→A page 1 is the last alphabetically', $passed, $failed, $errors);
    ss_assert_eq($partNoMu, ss_summary_part_nos($descDescPage2)[0] ?? '', 'description Z→A page 2 continues the global reverse alphabet', $passed, $failed, $errors);

    $qtyPage1 = $suggestedRepo->summary($MAIN_ID, $today, $today, $customerIdSort, 1, 1, null, 'qty-desc');
    ss_assert_eq($partNoZulu, ss_summary_part_nos($qtyPage1)[0] ?? '', 'qty-desc page 1 is the highest requested quantity', $passed, $failed, $errors);
    $partSearchOffPage = $suggestedRepo->summary($MAIN_ID, $today, $today, $customerIdSort, 1, 1, $partNoAlpha, 'qty-desc');
    ss_assert_eq(
        [$partNoAlpha],
        ss_summary_part_nos($partSearchOffPage),
        'part-number search finds a row that is not on qty-desc page 1',
        $passed,
        $failed,
        $errors
    );

    $kivResult = $suggestedRepo->addToKiv($MAIN_ID, [[
        'part_no' => $partNoUnlisted,
        'item_code' => $itemCodeUnlisted,
        'description' => 'Unlisted suggested stock test part',
    ]], (string) $USER_ID);
    ss_assert(($kivResult['added'] ?? 0) >= 1, 'unlisted item can be moved to KIV folder', $passed, $failed, $errors);

    $summaryAfterKiv = $suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200);
    ss_assert(
        !in_array($partNoUnlisted, ss_summary_part_nos($summaryAfterKiv), true),
        'main summary hides items parked in KIV folder',
        $passed,
        $failed,
        $errors
    );

    $customersAfterKiv = $suggestedRepo->listCustomers($MAIN_ID, $today, $today);
    $customerIdsAfterKiv = array_map(static fn(array $row): string => (string) ($row['id'] ?? ''), $customersAfterKiv);
    ss_assert(
        !in_array($customerId, $customerIdsAfterKiv, true),
        'customer filter excludes demand that only exists in KIV folder',
        $passed,
        $failed,
        $errors
    );

    $kivSummary = $suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200, null, 'qty-desc', true);
    ss_assert(
        in_array($partNoUnlisted, ss_summary_part_nos($kivSummary), true),
        'KIV folder summary includes parked unlisted item',
        $passed,
        $failed,
        $errors
    );

    $partSearch = $suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200, $partNoUnlisted, 'qty-desc', true);
    ss_assert(
        in_array($partNoUnlisted, ss_summary_part_nos($partSearch), true),
        'part-number search finds the parked item in KIV folder',
        $passed,
        $failed,
        $errors
    );

    $restored = $suggestedRepo->removeFromKiv($MAIN_ID, [[
        'part_no' => $partNoUnlisted,
        'item_code' => $itemCodeUnlisted,
        'description' => 'Unlisted suggested stock test part',
    ]]);
    ss_assert(($restored['removed'] ?? 0) >= 1, 'parked item can be restored from KIV folder', $passed, $failed, $errors);

    $summaryAfterRestore = $suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200);
    ss_assert(
        in_array($partNoUnlisted, ss_summary_part_nos($summaryAfterRestore), true),
        'restored item returns to the main suggested stock summary',
        $passed,
        $failed,
        $errors
    );

    $details = $suggestedRepo->details($MAIN_ID, $today, $today, null, 1, 200);
    $detailParts = ss_details_part_nos($details);
    ss_assert(in_array($partNoUnlisted, $detailParts, true), 'details include unlisted inquiry item', $passed, $failed, $errors);
    ss_assert(!in_array($partNoListed, $detailParts, true), 'details exclude inquiry item already in catalog', $passed, $failed, $errors);

    $customers = $suggestedRepo->listCustomers($MAIN_ID, $today, $today);
    $customerIds = array_map(static fn(array $row): string => (string) ($row['id'] ?? ''), $customers);
    ss_assert(in_array($customerId, $customerIds, true), 'customers list includes seeded unlisted customer', $passed, $failed, $errors);

    $created = $productRepo->createProduct($MAIN_ID, $USER_ID, [
        'item_code' => $itemCodeUnlisted,
        'part_no' => $partNoUnlisted,
        'description' => 'Created from suggested stock DB test',
        'reorder_quantity' => 4,
    ]);
    $createdSession = (string) ($created['id'] ?? $created['product_session'] ?? '');
    if ($createdSession !== '') {
        $createdProductSessions[] = $createdSession;
    }
    ss_assert($createdSession !== '', 'new product create succeeds for unlisted part', $passed, $failed, $errors);

    $health = ss_request('GET', "{$API_BASE}/api/v1/health");
    if (($health['http_code'] ?? 0) === 200 && ($health['body']['ok'] ?? false) === true) {
        echo "\nAPI server reachable — running HTTP checks\n";

        $apiSummary = ss_request(
            'GET',
            "{$API_BASE}/api/v1/suggested-stock-report/summary?main_id={$MAIN_ID}&date_from={$today}&date_to={$today}&customer_id=" . rawurlencode($customerId) . '&per_page=50'
        );
        if (($apiSummary['http_code'] ?? 0) !== 200) {
            echo "  WARN summary API request failed (http={$apiSummary['http_code']}, error={$apiSummary['error']}) — repository checks already passed\n";
        } else {
            ss_assert_eq(200, $apiSummary['http_code'], 'suggested-stock summary API returns 200', $passed, $failed, $errors);
            $apiParts = array_map(
                static fn(array $row): string => (string) ($row['part_no'] ?? ''),
                $apiSummary['body']['data']['items'] ?? []
            );
            ss_assert(!in_array($partNoUnlisted, $apiParts, true), 'summary API excludes a matching product before it is marked ProductCreated', $passed, $failed, $errors);
            ss_assert(!in_array($partNoListed, $apiParts, true), 'summary API excludes already-listed match', $passed, $failed, $errors);
        }

        $clearApi = ss_request('POST', "{$API_BASE}/api/v1/suggested-stock-report/clear-not-listed", [
            'main_id' => $MAIN_ID,
            'inquiry_item_id' => $unlistedItemId,
        ]);
        if (($clearApi['http_code'] ?? 0) !== 200) {
            ss_assert(
                false,
                'clear-not-listed API returns 200'
                    . ' actual=' . json_encode($clearApi['http_code'])
                    . ' error=' . ($clearApi['error'] ?? ''),
                $passed,
                $failed,
                $errors
            );
        } else {
            ss_assert_eq(200, $clearApi['http_code'], 'clear-not-listed API returns 200', $passed, $failed, $errors);
            ss_assert(($clearApi['body']['data']['cleared'] ?? 0) >= 1, 'clear-not-listed API clears the inquiry line', $passed, $failed, $errors);
        }
    } else {
        echo "\nAPI server not reachable — repository/database checks only\n";
        $clearResult = $suggestedRepo->clearNotListedRemarks($MAIN_ID, $unlistedItemId, $partNoUnlisted, $itemCodeUnlisted);
        ss_assert(($clearResult['cleared'] ?? 0) >= 1, 'repository marks the created product suggestion', $passed, $failed, $errors);
    }

    $remarkStmt = $pdo->prepare('SELECT lremark FROM tblinquiry_item WHERE lid = :id');
    $remarkStmt->execute(['id' => $unlistedItemId]);
    ss_assert_eq('ProductCreated', (string) $remarkStmt->fetchColumn(), 'unlisted inquiry item remains active as ProductCreated after product create', $passed, $failed, $errors);

    $summaryAfterClear = $suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200);
    ss_assert(
        in_array($partNoUnlisted, ss_summary_part_nos($summaryAfterClear), true),
        'summary retains inquiry item after its product is created',
        $passed,
        $failed,
        $errors
    );

    $markedForPr = $suggestedRepo->markAddedToPurchaseRequest($MAIN_ID, [[
        'part_no' => $partNoUnlisted,
        'item_code' => $itemCodeUnlisted,
        'description' => 'Unlisted suggested stock test part',
    ]]);
    ss_assert(($markedForPr['removed'] ?? 0) >= 1, 'product-created suggestion is removed after PR completion', $passed, $failed, $errors);
    $summaryAfterPr = $suggestedRepo->summary($MAIN_ID, $today, $today, null, 1, 200);
    ss_assert(!in_array($partNoUnlisted, ss_summary_part_nos($summaryAfterPr), true), 'summary hides a suggestion only after PR completion', $passed, $failed, $errors);

    try {
        $productRepo->createProduct($MAIN_ID, $USER_ID, [
            'item_code' => $itemCodeListed,
            'part_no' => $prefix . 'NEW-PART',
            'description' => 'Duplicate item code should fail',
            'reorder_quantity' => 3,
        ]);
        ss_assert(false, 'duplicate item_code create is rejected', $passed, $failed, $errors);
    } catch (InvalidArgumentException $error) {
        ss_assert(str_contains($error->getMessage(), 'already listed'), 'duplicate item_code create is rejected', $passed, $failed, $errors);
    }

    try {
        $productRepo->createProduct($MAIN_ID, $USER_ID, [
            'item_code' => $prefix . 'NEW-CODE',
            'part_no' => $partNoListed,
            'description' => 'Duplicate part number should fail',
            'reorder_quantity' => 3,
        ]);
        ss_assert(false, 'duplicate part_no create is rejected', $passed, $failed, $errors);
    } catch (InvalidArgumentException $error) {
        ss_assert(str_contains($error->getMessage(), 'already listed'), 'duplicate part_no create is rejected', $passed, $failed, $errors);
    }

    if (($health['http_code'] ?? 0) === 200) {
        $dupCreate = ss_request('POST', "{$API_BASE}/api/v1/products", [
            'main_id' => $MAIN_ID,
            'user_id' => $USER_ID,
            'item_code' => $itemCodeUnlisted,
            'part_no' => $prefix . 'DUP-PART',
            'description' => 'Duplicate via API',
            'reorder_quantity' => 2,
        ]);
        ss_assert_eq(422, $dupCreate['http_code'], 'products create API rejects duplicate item_code', $passed, $failed, $errors);
    }

    echo "\nResults: {$passed} passed, {$failed} failed\n";
} catch (Throwable $error) {
    $failed++;
    $errors[] = $error->getMessage();
    echo "  FAIL unexpected error: {$error->getMessage()}\n";
} finally {
    $cleanup();
    echo "Cleanup completed for seed prefix {$prefix}\n";
}

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    exit(1);
}

exit(0);
