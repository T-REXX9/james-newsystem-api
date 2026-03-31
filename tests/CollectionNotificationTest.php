<?php

declare(strict_types=1);

/**
 * Tests all 8 notification actions from the old system are present in the new system:
 *   1. Sales Order Submitted
 *   2. Sales Order Approved
 *   3. Transfer Stock Submitted
 *   4. Transfer Stock Approved
 *   5. Collection Submitted for Approval
 *   6. Collection Disapproved
 *   7. Collection Approved
 *   8. Collection Waiting for Next Approver
 *
 * Verifies that correct recipients see each notification.
 *
 * Run:
 *   API_BASE_URL=http://127.0.0.1:8081 php api/tests/CollectionNotificationTest.php
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$MAIN_ID  = (int) (getenv('TEST_MAIN_ID') ?: 1);

$passed = 0;
$failed = 0;
$errors = [];

/* ------------------------------------------------------------------ */
/*  Helpers                                                           */
/* ------------------------------------------------------------------ */

function request(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== null && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw      = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);

    if ($error !== '') {
        return ['http_code' => 0, 'body' => null, 'error' => $error];
    }

    return ['http_code' => $httpCode, 'body' => json_decode((string) $raw, true), 'raw' => $raw];
}

function assert_true(bool $condition, string $message, int &$passed, int &$failed, array &$errors): void
{
    if ($condition) {
        $passed++;
        echo "  \033[32mPASS\033[0m $message\n";
        return;
    }
    $failed++;
    $errors[] = $message;
    echo "  \033[31mFAIL\033[0m $message\n";
}

function assert_eq(mixed $expected, mixed $actual, string $message, int &$passed, int &$failed, array &$errors): void
{
    assert_true(
        $expected === $actual,
        $message . ' (expected=' . json_encode($expected) . ' actual=' . json_encode($actual) . ')',
        $passed, $failed, $errors
    );
}

function section(string $title): void
{
    echo "\n----------------------------------------------------------\n";
    echo " $title\n";
    echo "----------------------------------------------------------\n";
}

/* ------------------------------------------------------------------ */
/*  Auth: generate token from secret (no login needed for local dev)   */
/* ------------------------------------------------------------------ */

function generate_auth_token(): string
{
    $token = trim((string) getenv('TEST_TOKEN'));
    if ($token !== '') {
        return $token;
    }

    $envFile = __DIR__ . '/../.env';
    $secret = 'local-dev-secret-change-me';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            [$k, $v] = explode('=', $line, 2) + [1 => ''];
            if (trim($k) === 'AUTH_SECRET') {
                $secret = trim($v);
            }
        }
    }

    $now = time();
    $payload = json_encode(['sub' => 1, 'main_userid' => 1, 'iat' => $now, 'exp' => $now + 28800], JSON_UNESCAPED_SLASHES);
    $payloadB64 = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    $sigB64 = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadB64, $secret, true)), '+/', '-_'), '=');
    return $payloadB64 . '.' . $sigB64;
}

/* ------------------------------------------------------------------ */
/*  Database helpers                                                   */
/* ------------------------------------------------------------------ */

function get_db(): PDO
{
    static $pdo;
    if ($pdo !== null) {
        return $pdo;
    }

    $envFile = __DIR__ . '/../.env';
    $vars = [];
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            [$k, $v] = explode('=', $line, 2) + [1 => ''];
            $vars[trim($k)] = trim($v);
        }
    }

    $host = $vars['DB_HOST'] ?? 'localhost';
    $port = $vars['DB_PORT'] ?? '3306';
    $name = $vars['DB_NAME'] ?? 'topnotch_migrate';
    $user = $vars['DB_USER'] ?? 'root';
    $pass = $vars['DB_PASS'] ?? '';

    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    return $pdo;
}

function find_owner_user_id(int $mainId): ?string
{
    $stmt = get_db()->prepare(
        'SELECT a.lid
         FROM tblaccount a
         LEFT JOIN tblusertype ut ON ut.lid = a.ltype
         WHERE COALESCE(a.lstatus, 0) = 1
           AND (ut.ltype_name LIKE "%Owner%" OR ut.ltype_name = "main" OR a.lid = :main_id)
         ORDER BY a.lid ASC
         LIMIT 1'
    );
    $stmt->execute(['main_id' => $mainId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string) $row['lid'] : null;
}

/** Dispatch a workflow notification via the API. */
function dispatch_notification(string $apiBase, string $token, array $payload): array
{
    // Mark existing notifications as read to avoid idempotency collisions
    $recipientIds = $payload['targetUserIds'] ?? [];
    foreach ($recipientIds as $rid) {
        request('POST', "{$apiBase}/api/v1/notifications/mark-all-read", ['user_id' => (string) $rid], $token);
    }

    return request('POST', "{$apiBase}/api/v1/notifications/workflow-dispatch", $payload, $token);
}

/** Get notifications for a user, optionally filtered by title. */
function get_notifications(string $apiBase, string $token, string $userId, ?string $filterTitle = null): array
{
    $res = request('GET', "{$apiBase}/api/v1/notifications?user_id={$userId}&limit=100", null, $token);
    if (($res['http_code'] ?? 0) !== 200) {
        return [];
    }
    $all = $res['body']['data']['data'] ?? $res['body']['data'] ?? [];
    if (!is_array($all)) return [];
    if ($filterTitle === null) return $all;

    return array_values(array_filter($all, function ($n) use ($filterTitle) {
        return ($n['title'] ?? '') === $filterTitle;
    }));
}

function get_approvers_at_order(int $mainId, int $order): array
{
    $stmt = get_db()->prepare(
        'SELECT lstaff_id FROM tblapprover WHERE lmain_id = :main_id AND lorder = :lorder AND ltrans_type = :type'
    );
    $stmt->execute(['main_id' => $mainId, 'lorder' => $order, 'type' => 'Collection']);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function get_max_approver_order(int $mainId): int
{
    $stmt = get_db()->prepare(
        'SELECT MAX(lorder) AS max_order FROM tblapprover WHERE lmain_id = :main_id AND ltrans_type = :type'
    );
    $stmt->execute(['main_id' => $mainId, 'type' => 'Collection']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['max_order'] ?? 0);
}

function ensure_test_collection(string $apiBase, int $mainId): ?string
{
    $list = request('GET', "{$apiBase}/api/v1/collections?main_id={$mainId}&status=Pending&page=1&per_page=1");
    $items = $list['body']['data']['items'] ?? [];
    if (!empty($items)) {
        return (string) $items[0]['lrefno'];
    }
    $create = request('POST', "{$apiBase}/api/v1/collections", ['main_id' => $mainId, 'user_id' => 1]);
    if (($create['http_code'] ?? 0) === 200) {
        return (string) ($create['body']['data']['lrefno'] ?? '');
    }
    return null;
}

function cleanup_approver_logs(string $refno): void
{
    get_db()->prepare('DELETE FROM tblapprove_logs WHERE lsales_refno = :refno')->execute(['refno' => $refno]);
}

function reset_collection_status(string $refno, string $status = 'Pending'): void
{
    get_db()->prepare('UPDATE tblcollection SET lstatus = :status WHERE lrefno = :refno')
        ->execute(['status' => $status, 'refno' => $refno]);
}

/** Count notification results from dispatch response. */
function count_created(array $response): int
{
    $data = $response['body']['data']['data'] ?? $response['body']['data'] ?? [];
    return is_array($data) ? count($data) : 0;
}

/* ------------------------------------------------------------------ */
/*  START TEST SUITE                                                   */
/* ------------------------------------------------------------------ */

echo "==========================================================\n";
echo " Workflow Notification Test Suite (All 8 Actions)\n";
echo " Base URL: {$API_BASE}\n";
echo " Main ID:  {$MAIN_ID}\n";
echo "==========================================================\n";

// 0. Health check
section('0. Health Check');
$health = request('GET', "{$API_BASE}/api/v1/health");
assert_eq(200, $health['http_code'], 'API health endpoint returns 200', $passed, $failed, $errors);
if (($health['http_code'] ?? 0) !== 200) {
    echo "\n  Cannot reach API. Aborting.\n";
    exit(1);
}

$token = generate_auth_token();
assert_true($token !== '', 'Generated auth token', $passed, $failed, $errors);

$ownerUserId = find_owner_user_id($MAIN_ID);
assert_true($ownerUserId !== null && $ownerUserId !== '', "Resolved owner user ID: {$ownerUserId}", $passed, $failed, $errors);
if (!$ownerUserId) exit(1);

/* ================================================================== */
/*  TEST 1: Sales Order Submitted                                      */
/* ================================================================== */
section('1. Sales Order Submitted Notification');

$soList = request('GET', "{$API_BASE}/api/v1/sales-orders?main_id={$MAIN_ID}&status=Pending&page=1&per_page=1&viewer_user_id={$ownerUserId}");
$soItems = $soList['body']['data']['items'] ?? [];
$testSoRefno = !empty($soItems) ? (string) $soItems[0]['sales_refno'] : '';
$soNo = !empty($soItems) ? (string) ($soItems[0]['sales_no'] ?? $testSoRefno) : '';

if ($testSoRefno !== '') {
    // Submit the SO
    $soSubmit = request('POST', "{$API_BASE}/api/v1/sales-orders/{$testSoRefno}/actions/submit", [
        'main_id' => $MAIN_ID,
        'user_id' => (int) $ownerUserId,
    ]);
    assert_eq(200, $soSubmit['http_code'], 'SO submit action returns 200', $passed, $failed, $errors);

    // Dispatch notification (mimicking frontend)
    $dispatch = dispatch_notification($API_BASE, $token, [
        'title'      => 'Sales Order Submitted',
        'message'    => "SO {$soNo} is submitted and waiting for your approval.",
        'type'       => 'info',
        'action'     => 'confirm',
        'status'     => 'submitted',
        'entityType' => 'sales_order',
        'entityId'   => $testSoRefno,
        'targetRoles' => ['Owner'],
        'actorId'    => $ownerUserId,
        'actorRole'  => 'Owner',
    ]);
    assert_eq(200, $dispatch['http_code'], 'SO Submitted notification dispatch returns 200', $passed, $failed, $errors);
    assert_true(count_created($dispatch) > 0, 'SO Submitted notification created for Owner', $passed, $failed, $errors);

    // Verify owner can see it
    $notifs = get_notifications($API_BASE, $token, $ownerUserId, 'Sales Order Submitted');
    assert_true(count($notifs) > 0, 'Owner sees SO Submitted notification', $passed, $failed, $errors);
} else {
    echo "  SKIP - No Pending SO found\n";
}

/* ================================================================== */
/*  TEST 2: Sales Order Approved                                       */
/* ================================================================== */
section('2. Sales Order Approved Notification');

if ($testSoRefno !== '') {
    // Approve the SO
    $soApprove = request('POST', "{$API_BASE}/api/v1/sales-orders/{$testSoRefno}/actions/approve", [
        'main_id' => $MAIN_ID,
        'user_id' => (int) $ownerUserId,
    ]);
    assert_eq(200, $soApprove['http_code'], 'SO approve action returns 200', $passed, $failed, $errors);

    $dispatch2 = dispatch_notification($API_BASE, $token, [
        'title'       => 'Sales Order Approved',
        'message'     => "SO {$soNo} has been approved.",
        'type'        => 'success',
        'action'      => 'approve_sales_order',
        'status'      => 'approved',
        'entityType'  => 'sales_order',
        'entityId'    => $testSoRefno,
        'targetUserIds' => [$ownerUserId],
        'actorId'     => $ownerUserId,
        'actorRole'   => 'Owner',
    ]);
    assert_eq(200, $dispatch2['http_code'], 'SO Approved notification dispatch returns 200', $passed, $failed, $errors);
    assert_true(count_created($dispatch2) > 0, 'SO Approved notification created', $passed, $failed, $errors);

    $notifs2 = get_notifications($API_BASE, $token, $ownerUserId, 'Sales Order Approved');
    assert_true(count($notifs2) > 0, 'Owner sees SO Approved notification', $passed, $failed, $errors);
} else {
    echo "  SKIP - No SO available\n";
}

/* ================================================================== */
/*  TEST 3: Transfer Stock Submitted                                   */
/* ================================================================== */
section('3. Transfer Stock Submitted Notification');

$tsList = request('GET', "{$API_BASE}/api/v1/transfer-stocks?main_id={$MAIN_ID}&status=Pending&page=1&per_page=1");
$tsItems = $tsList['body']['data']['items'] ?? [];
$testTsRefno = !empty($tsItems) ? (string) ($tsItems[0]['transfer_refno'] ?? '') : '';
$tsNo = !empty($tsItems) ? (string) ($tsItems[0]['transfer_no'] ?? $testTsRefno) : '';

if ($testTsRefno !== '') {
    $tsSubmit = request('POST', "{$API_BASE}/api/v1/transfer-stocks/{$testTsRefno}/actions/submit", [
        'main_id' => $MAIN_ID,
        'user_id' => (int) $ownerUserId,
    ]);
    assert_true(
        in_array($tsSubmit['http_code'] ?? 0, [200, 422], true),
        'Transfer submit returns 200 or 422',
        $passed, $failed, $errors
    );

    $dispatch3 = dispatch_notification($API_BASE, $token, [
        'title'      => 'Transfer Submitted',
        'message'    => "Transfer {$tsNo} is submitted and waiting for your approval.",
        'type'       => 'success',
        'action'     => 'submit_transfer_stock',
        'status'     => 'submitted',
        'entityType' => 'transfer_stock',
        'entityId'   => $testTsRefno,
        'targetRoles' => ['Owner'],
        'actorId'    => $ownerUserId,
        'actorRole'  => 'Owner',
    ]);
    assert_eq(200, $dispatch3['http_code'], 'Transfer Submitted notification dispatch returns 200', $passed, $failed, $errors);
    assert_true(count_created($dispatch3) > 0, 'Transfer Submitted notification created for Owner', $passed, $failed, $errors);

    $notifs3 = get_notifications($API_BASE, $token, $ownerUserId, 'Transfer Submitted');
    assert_true(count($notifs3) > 0, 'Owner sees Transfer Submitted notification', $passed, $failed, $errors);
} else {
    echo "  SKIP - No Pending transfer found\n";
}

/* ================================================================== */
/*  TEST 4: Transfer Stock Approved                                    */
/* ================================================================== */
section('4. Transfer Stock Approved Notification');

if ($testTsRefno !== '') {
    $dispatch4 = dispatch_notification($API_BASE, $token, [
        'title'       => 'Transfer Approved',
        'message'     => "Transfer {$tsNo} has been approved.",
        'type'        => 'success',
        'action'      => 'approve_transfer_stock',
        'status'      => 'approved',
        'entityType'  => 'transfer_stock',
        'entityId'    => $testTsRefno,
        'targetUserIds' => [$ownerUserId],
        'actorId'     => $ownerUserId,
        'actorRole'   => 'Owner',
    ]);
    assert_eq(200, $dispatch4['http_code'], 'Transfer Approved notification dispatch returns 200', $passed, $failed, $errors);
    assert_true(count_created($dispatch4) > 0, 'Transfer Approved notification created', $passed, $failed, $errors);

    $notifs4 = get_notifications($API_BASE, $token, $ownerUserId, 'Transfer Approved');
    assert_true(count($notifs4) > 0, 'Owner sees Transfer Approved notification', $passed, $failed, $errors);
} else {
    echo "  SKIP - No transfer available\n";
}

/* ================================================================== */
/*  TEST 5: Collection Submitted for Approval                          */
/* ================================================================== */
section('5. Collection Submitted for Approval Notification');

$testCollRefno = ensure_test_collection($API_BASE, $MAIN_ID);
assert_true($testCollRefno !== null && $testCollRefno !== '', "Test collection: {$testCollRefno}", $passed, $failed, $errors);

if ($testCollRefno) {
    reset_collection_status($testCollRefno, 'Pending');
    cleanup_approver_logs($testCollRefno);

    $collSubmit = request('POST', "{$API_BASE}/api/v1/collections/{$testCollRefno}/actions/submitrecord", [
        'main_id' => $MAIN_ID,
    ]);
    assert_eq(200, $collSubmit['http_code'], 'Collection submit action returns 200', $passed, $failed, $errors);

    $collNo = $collSubmit['body']['data']['collection_no'] ?? $testCollRefno;

    $dispatch5 = dispatch_notification($API_BASE, $token, [
        'title'      => 'Collection Submitted',
        'message'    => "Collection {$collNo} is submitted and waiting for your approval.",
        'type'       => 'info',
        'action'     => 'submit_collection',
        'status'     => 'submitted',
        'entityType' => 'daily_collection',
        'entityId'   => $testCollRefno,
        'targetRoles' => ['Owner'],
        'actorId'    => $ownerUserId,
        'actorRole'  => 'Owner',
    ]);
    assert_eq(200, $dispatch5['http_code'], 'Collection Submitted notification dispatch returns 200', $passed, $failed, $errors);
    assert_true(count_created($dispatch5) > 0, 'Collection Submitted notification created for Owner', $passed, $failed, $errors);

    $notifs5 = get_notifications($API_BASE, $token, $ownerUserId, 'Collection Submitted');
    assert_true(count($notifs5) > 0, 'Owner sees Collection Submitted notification', $passed, $failed, $errors);
}

/* ================================================================== */
/*  TEST 6: Collection Disapproved                                     */
/* ================================================================== */
section('6. Collection Disapproved Notification');

if ($testCollRefno) {
    $dispatch6 = dispatch_notification($API_BASE, $token, [
        'title'       => 'Collection Disapproved',
        'message'     => "Collection {$testCollRefno} was disapproved. Reason: Test disapproval.",
        'type'        => 'error',
        'action'      => 'disapprove_collection',
        'status'      => 'disapproved',
        'entityType'  => 'daily_collection',
        'entityId'    => $testCollRefno,
        'targetUserIds' => [$ownerUserId],
        'actorId'     => $ownerUserId,
        'actorRole'   => 'Owner',
        'metadata'    => ['disapproval_reason' => 'Test disapproval'],
    ]);
    assert_eq(200, $dispatch6['http_code'], 'Collection Disapproved notification dispatch returns 200', $passed, $failed, $errors);
    assert_true(count_created($dispatch6) > 0, 'Collection Disapproved notification created', $passed, $failed, $errors);

    $notifs6 = get_notifications($API_BASE, $token, $ownerUserId, 'Collection Disapproved');
    assert_true(count($notifs6) > 0, 'Submitter sees Collection Disapproved notification', $passed, $failed, $errors);
}

/* ================================================================== */
/*  TEST 7: Collection Approved                                        */
/* ================================================================== */
section('7. Collection Approved Notification');

if ($testCollRefno) {
    $dispatch7 = dispatch_notification($API_BASE, $token, [
        'title'       => 'Collection Approved',
        'message'     => "Collection {$testCollRefno} has been approved.",
        'type'        => 'success',
        'action'      => 'approve_collection',
        'status'      => 'approved',
        'entityType'  => 'daily_collection',
        'entityId'    => $testCollRefno,
        'targetUserIds' => [$ownerUserId],
        'actorId'     => $ownerUserId,
        'actorRole'   => 'Owner',
    ]);
    assert_eq(200, $dispatch7['http_code'], 'Collection Approved notification dispatch returns 200', $passed, $failed, $errors);
    assert_true(count_created($dispatch7) > 0, 'Collection Approved notification created', $passed, $failed, $errors);

    $notifs7 = get_notifications($API_BASE, $token, $ownerUserId, 'Collection Approved');
    assert_true(count($notifs7) > 0, 'Submitter sees Collection Approved notification', $passed, $failed, $errors);
}

/* ================================================================== */
/*  TEST 8: Collection Waiting for Next Approver                       */
/* ================================================================== */
section('8. Collection Waiting for Next Approver Notification');

if ($testCollRefno) {
    $maxOrder = get_max_approver_order($MAIN_ID);

    if ($maxOrder > 1) {
        // Multi-level approval — test the real flow
        reset_collection_status($testCollRefno, 'Pending');
        cleanup_approver_logs($testCollRefno);

        $resubmit = request('POST', "{$API_BASE}/api/v1/collections/{$testCollRefno}/actions/submitrecord", [
            'main_id' => $MAIN_ID,
        ]);
        assert_eq(200, $resubmit['http_code'], 'Re-submit collection for multi-level test', $passed, $failed, $errors);

        $level1Approvers = get_approvers_at_order($MAIN_ID, 1);
        assert_true(count($level1Approvers) > 0, 'Found level-1 approvers: ' . implode(', ', $level1Approvers), $passed, $failed, $errors);

        if (!empty($level1Approvers)) {
            $firstApprover = (string) $level1Approvers[0];

            // Level-1 approver approves — API should return next_approvers
            $approveResult = request('POST', "{$API_BASE}/api/v1/collections/{$testCollRefno}/actions/approverecord", [
                'main_id'  => $MAIN_ID,
                'staff_id' => $firstApprover,
            ]);
            assert_eq(200, $approveResult['http_code'], 'Level-1 approve returns 200', $passed, $failed, $errors);

            $nextApprovers = $approveResult['body']['data']['next_approvers'] ?? [];
            $collectionStatus = $approveResult['body']['data']['collection_status'] ?? null;
            assert_true(count($nextApprovers) > 0, 'API returns next_approvers: ' . json_encode($nextApprovers), $passed, $failed, $errors);
            assert_true($collectionStatus === null, 'collection_status is null (not final)', $passed, $failed, $errors);

            if (!empty($nextApprovers)) {
                $dispatch8 = dispatch_notification($API_BASE, $token, [
                    'title'       => 'Collection',
                    'message'     => "Collection {$testCollRefno} is waiting for your approval.",
                    'type'        => 'info',
                    'action'      => 'waiting_next_approver',
                    'status'      => 'pending_approval',
                    'entityType'  => 'daily_collection',
                    'entityId'    => $testCollRefno,
                    'targetUserIds' => array_map('strval', $nextApprovers),
                    'actorId'     => $firstApprover,
                    'actorRole'   => 'Approver',
                ]);
                assert_eq(200, $dispatch8['http_code'], 'Waiting for Next Approver dispatch returns 200', $passed, $failed, $errors);
                assert_true(count_created($dispatch8) > 0, 'Waiting for Next Approver notification(s) created', $passed, $failed, $errors);

                foreach ($nextApprovers as $nextApproverId) {
                    $nxt = get_notifications($API_BASE, $token, (string) $nextApproverId, 'Collection');
                    assert_true(
                        count($nxt) > 0,
                        "Next approver {$nextApproverId} sees 'waiting for approval' notification",
                        $passed, $failed, $errors
                    );
                }
            }
        }
    } else {
        echo "  INFO: {$maxOrder} approval level(s). Testing dispatch directly.\n";

        $dispatch8 = dispatch_notification($API_BASE, $token, [
            'title'       => 'Collection',
            'message'     => "Collection {$testCollRefno} is waiting for your approval.",
            'type'        => 'info',
            'action'      => 'waiting_next_approver',
            'status'      => 'pending_approval',
            'entityType'  => 'daily_collection',
            'entityId'    => $testCollRefno,
            'targetUserIds' => [$ownerUserId],
            'actorId'     => $ownerUserId,
            'actorRole'   => 'Owner',
        ]);
        assert_eq(200, $dispatch8['http_code'], 'Waiting for Next Approver dispatch returns 200', $passed, $failed, $errors);
        assert_true(count_created($dispatch8) > 0, 'Waiting for Next Approver notification created', $passed, $failed, $errors);

        $nxt = get_notifications($API_BASE, $token, $ownerUserId, 'Collection');
        assert_true(count($nxt) > 0, "User {$ownerUserId} sees 'waiting for approval' notification", $passed, $failed, $errors);
    }

    // Cleanup
    reset_collection_status($testCollRefno, 'Pending');
    cleanup_approver_logs($testCollRefno);
}

/* ================================================================== */
/*  BONUS: Verify notification list & unread count                     */
/* ================================================================== */
section('Bonus: Notification List & Unread Count');

$listRes = request('GET', "{$API_BASE}/api/v1/notifications?user_id={$ownerUserId}&limit=10", null, $token);
assert_eq(200, $listRes['http_code'], 'Notification list returns 200', $passed, $failed, $errors);

$unreadRes = request('GET', "{$API_BASE}/api/v1/notifications/unread-count?user_id={$ownerUserId}", null, $token);
assert_eq(200, $unreadRes['http_code'], 'Unread count returns 200', $passed, $failed, $errors);
$unreadCount = (int) ($unreadRes['body']['data']['count'] ?? $unreadRes['body']['count'] ?? -1);
assert_true($unreadCount >= 0, "Unread count: {$unreadCount}", $passed, $failed, $errors);

/* ================================================================== */
/*  RESULTS                                                            */
/* ================================================================== */
echo "\n==========================================================\n";
echo " RESULTS\n";
echo "==========================================================\n";
echo " Passed: \033[32m{$passed}\033[0m\n";
echo " Failed: \033[31m{$failed}\033[0m\n";
echo "==========================================================\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $err) {
        echo "  \033[31m- {$err}\033[0m\n";
    }
    exit(1);
}

echo "\n\033[32mAll tests passed!\033[0m\n";
