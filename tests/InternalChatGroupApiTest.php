<?php

declare(strict_types=1);

/**
 * Internal chat group API integration tests.
 *
 * Run:
 *   API_BASE_URL=http://127.0.0.1:8081 php api/tests/InternalChatGroupApiTest.php
 */

$API_BASE = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$MAIN_ID = (int) (getenv('TEST_MAIN_ID') ?: 1);

$passed = 0;
$failed = 0;
$errors = [];

function request(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== null && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    if ($error !== '') {
        return ['http_code' => 0, 'body' => null, 'error' => $error];
    }

    return [
        'http_code' => $httpCode,
        'body' => json_decode((string) $raw, true),
        'raw' => $raw,
    ];
}

function assert_true(bool $condition, string $message, int &$passed, int &$failed, array &$errors): void
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

function assert_eq($expected, $actual, string $message, int &$passed, int &$failed, array &$errors): void
{
    assert_true(
        $expected === $actual,
        $message . ' (expected=' . json_encode($expected) . ' actual=' . json_encode($actual) . ')',
        $passed,
        $failed,
        $errors
    );
}

function section(string $title): void
{
    echo "\n----------------------------------------------------------\n";
    echo " {$title}\n";
    echo "----------------------------------------------------------\n";
}

function body_data(array $response): array
{
    $data = $response['body']['data'] ?? null;
    return is_array($data) ? $data : [];
}

function env_vars(): array
{
    static $vars;
    if (is_array($vars)) {
        return $vars;
    }

    $vars = [];
    $envFile = __DIR__ . '/../.env';
    if (!is_file($envFile)) {
        return $vars;
    }

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2) + [1 => ''];
        $vars[trim($key)] = trim($value);
    }

    return $vars;
}

function get_db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $vars = env_vars();
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $vars['DB_HOST'] ?? 'localhost',
            $vars['DB_PORT'] ?? '3306',
            $vars['DB_NAME'] ?? 'topnotch_migrate'
        ),
        $vars['DB_USER'] ?? 'root',
        $vars['DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    return $pdo;
}

function generate_auth_token(int $userId, int $mainId): string
{
    $token = trim((string) getenv('TEST_TOKEN'));
    if ($token !== '' && $userId === 1 && $mainId === 1) {
        return $token;
    }

    $secret = trim((string) (env_vars()['AUTH_SECRET'] ?? 'local-dev-secret-change-me'));
    $now = time();
    $payload = json_encode([
        'sub' => $userId,
        'main_userid' => $mainId,
        'iat' => $now,
        'exp' => $now + 28800,
    ], JSON_UNESCAPED_SLASHES);

    $payloadB64 = rtrim(strtr(base64_encode((string) $payload), '+/', '-_'), '=');
    $signatureB64 = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadB64, $secret, true)), '+/', '-_'), '=');

    return $payloadB64 . '.' . $signatureB64;
}

function find_test_users(int $mainId): array
{
    $stmt = get_db()->prepare(
        'SELECT
            CAST(a.lid AS CHAR) AS id,
            TRIM(CONCAT_WS(" ", COALESCE(a.lfname, ""), COALESCE(a.llname, ""))) AS full_name
         FROM tblaccount a
         WHERE COALESCE(a.lstatus, 0) = 1
           AND COALESCE(a.lactivation, 1) <> 0
           AND (a.lid = :main_id_self OR a.lmother_id = :main_id_child)
         ORDER BY CASE WHEN a.lid = :preferred_creator THEN 0 ELSE 1 END, a.lid ASC
         LIMIT 8'
    );
    $stmt->execute([
        ':main_id_self' => $mainId,
        ':main_id_child' => $mainId,
        ':preferred_creator' => $mainId,
    ]);

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($users) < 4) {
        throw new RuntimeException('Need at least 4 active users in the tenant for internal chat group API tests');
    }

    return [
        'creator' => $users[0],
        'member_read' => $users[1],
        'member_remove' => $users[2],
        'member_add' => $users[3],
    ];
}

function unread_count(string $apiBase, string $token): int
{
    return (int) (body_data(request('GET', "{$apiBase}/api/v1/internal-chat/unread-count", null, $token))['count'] ?? 0);
}

function conversation_summary(string $apiBase, string $token, string $conversationKey): ?array
{
    $response = request('GET', "{$apiBase}/api/v1/internal-chat/conversations", null, $token);
    $items = body_data($response)['items'] ?? [];
    if (!is_array($items)) {
        return null;
    }

    foreach ($items as $item) {
        if (($item['conversation_key'] ?? '') === $conversationKey) {
            return is_array($item) ? $item : null;
        }
    }

    return null;
}

function find_message_by_id(array $messages, string $messageId): ?array
{
    foreach ($messages as $message) {
        if (is_array($message) && (string) ($message['id'] ?? '') === $messageId) {
            return $message;
        }
    }

    return null;
}

function cleanup_group_artifacts(int $groupId, string $conversationKey): void
{
    if ($groupId <= 0 && $conversationKey === '') {
        return;
    }

    $pdo = get_db();

    if ($conversationKey !== '') {
        $pdo->prepare('DELETE FROM internal_chat_typing_states WHERE conversation_key = :conversation_key')
            ->execute([':conversation_key' => $conversationKey]);
        $pdo->prepare('DELETE FROM internal_chat_message_replies WHERE conversation_key = :conversation_key')
            ->execute([':conversation_key' => $conversationKey]);
        $pdo->prepare('DELETE FROM internal_chat_message_reactions WHERE conversation_key = :conversation_key')
            ->execute([':conversation_key' => $conversationKey]);

        $pdo->prepare(
            "DELETE FROM tblusers_alerts
             WHERE COALESCE(ltype, '') = 'internal_chat'
               AND COALESCE(lrefno, '') = :conversation_key"
        )->execute([
            ':conversation_key' => $conversationKey,
        ]);

        $pdo->prepare(
            "DELETE FROM tblsms
             WHERE COALESCE(ltype, '') = 'internal_chat'
               AND COALESCE(lsource, '') = :conversation_key"
        )->execute([
            ':conversation_key' => $conversationKey,
        ]);
    }

    if ($groupId > 0) {
        $pdo->prepare('DELETE FROM internal_chat_group_members WHERE group_id = :group_id')
            ->execute([':group_id' => $groupId]);
        $pdo->prepare('DELETE FROM internal_chat_groups WHERE id = :group_id')
            ->execute([':group_id' => $groupId]);
    }
}

echo "==========================================================\n";
echo " Internal Chat Group API Integration Tests\n";
echo " Base URL: {$API_BASE}\n";
echo " Main ID:  {$MAIN_ID}\n";
echo "==========================================================\n";

$groupId = 0;
$conversationKey = '';

try {
    section('0. Health Check');
    $health = request('GET', "{$API_BASE}/api/v1/health");
    assert_eq(200, $health['http_code'], 'Health endpoint returns 200', $passed, $failed, $errors);
    assert_eq(true, $health['body']['ok'] ?? false, 'Health response ok=true', $passed, $failed, $errors);

    if (($health['http_code'] ?? 0) !== 200) {
        throw new RuntimeException('API server is not reachable');
    }

    section('1. Test Identities');
    $users = find_test_users($MAIN_ID);
    $creator = $users['creator'];
    $memberRead = $users['member_read'];
    $memberRemove = $users['member_remove'];
    $memberAdd = $users['member_add'];

    $creatorToken = generate_auth_token((int) $creator['id'], $MAIN_ID);
    $memberReadToken = generate_auth_token((int) $memberRead['id'], $MAIN_ID);
    $memberRemoveToken = generate_auth_token((int) $memberRemove['id'], $MAIN_ID);
    $memberAddToken = generate_auth_token((int) $memberAdd['id'], $MAIN_ID);

    assert_true($creatorToken !== '', 'Generated creator auth token', $passed, $failed, $errors);
    assert_true($memberReadToken !== '', 'Generated member-read auth token', $passed, $failed, $errors);
    assert_true($memberRemoveToken !== '', 'Generated member-remove auth token', $passed, $failed, $errors);
    assert_true($memberAddToken !== '', 'Generated member-add auth token', $passed, $failed, $errors);

    section('2. Create And Rename Group');
    $initialUnreadRead = unread_count($API_BASE, $memberReadToken);
    $initialUnreadRemove = unread_count($API_BASE, $memberRemoveToken);
    $initialUnreadAdd = unread_count($API_BASE, $memberAddToken);

    $seed = (string) time();
    $create = request('POST', "{$API_BASE}/api/v1/internal-chat/groups", [
        'name' => 'API Group ' . $seed,
        'member_ids' => [$memberRead['id'], $memberRemove['id']],
    ], $creatorToken);
    $createData = body_data($create);
    $groupId = (int) ($createData['id'] ?? 0);
    $conversationKey = (string) ($createData['conversation_key'] ?? '');

    assert_eq(200, $create['http_code'], 'Create group returns 200', $passed, $failed, $errors);
    assert_true($groupId > 0, 'Create group returns a numeric id', $passed, $failed, $errors);
    assert_true($conversationKey !== '', 'Create group returns a conversation key', $passed, $failed, $errors);
    assert_eq(3, (int) ($createData['member_count'] ?? 0), 'Create group returns creator plus 2 members', $passed, $failed, $errors);

    $rename = request('PATCH', "{$API_BASE}/api/v1/internal-chat/groups/{$groupId}", [
        'name' => 'Renamed API Group ' . $seed,
    ], $creatorToken);
    $renameData = body_data($rename);

    assert_eq(200, $rename['http_code'], 'Rename group returns 200', $passed, $failed, $errors);
    assert_eq('Renamed API Group ' . $seed, (string) ($renameData['name'] ?? ''), 'Rename group updates the stored name', $passed, $failed, $errors);

    section('3. Send, List, And Mark Read');
    $sendFirst = request('POST', "{$API_BASE}/api/v1/internal-chat/messages", [
        'conversation_key' => $conversationKey,
        'message' => 'First group message ' . $seed,
    ], $creatorToken);
    $sendFirstData = body_data($sendFirst);
    $sendFirstItems = $sendFirstData['items'] ?? [];
    $firstMessageId = (string) (($sendFirstItems[0]['id'] ?? ''));

    assert_eq(200, $sendFirst['http_code'], 'Send first group message returns 200', $passed, $failed, $errors);
    assert_true(is_array($sendFirstItems) && count($sendFirstItems) === 1, 'Send first group message returns one created item', $passed, $failed, $errors);
    assert_true($firstMessageId !== '', 'First group message returns a message id', $passed, $failed, $errors);

    $messagesForReadMember = request(
        'GET',
        "{$API_BASE}/api/v1/internal-chat/conversations/" . rawurlencode($conversationKey) . '/messages',
        null,
        $memberReadToken
    );
    $messageItems = body_data($messagesForReadMember)['items'] ?? [];
    assert_eq(200, $messagesForReadMember['http_code'], 'Group messages returns 200 for active member', $passed, $failed, $errors);
    assert_true(is_array($messageItems) && count($messageItems) >= 1, 'Group messages includes the sent message', $passed, $failed, $errors);

    $afterUnreadRead = unread_count($API_BASE, $memberReadToken);
    assert_eq($initialUnreadRead + 1, $afterUnreadRead, 'Unread count increases for receiving group member', $passed, $failed, $errors);

    $readSummary = conversation_summary($API_BASE, $memberReadToken, $conversationKey);
    assert_true(is_array($readSummary), 'Group summary is visible to the receiving member', $passed, $failed, $errors);
    assert_eq(1, (int) ($readSummary['unread_count'] ?? 0), 'Group summary shows one unread message', $passed, $failed, $errors);

    $markRead = request(
        'POST',
        "{$API_BASE}/api/v1/internal-chat/conversations/" . rawurlencode($conversationKey) . '/read',
        null,
        $memberReadToken
    );
    $markReadData = body_data($markRead);
    assert_eq(200, $markRead['http_code'], 'Mark group conversation read returns 200', $passed, $failed, $errors);
    assert_true((int) ($markReadData['updated_count'] ?? 0) >= 1, 'Mark read updates at least one unread alert', $passed, $failed, $errors);
    assert_eq($initialUnreadRead, unread_count($API_BASE, $memberReadToken), 'Unread count returns to baseline after mark read', $passed, $failed, $errors);

    section('4. Membership Changes');
    $addMember = request('POST', "{$API_BASE}/api/v1/internal-chat/groups/{$groupId}/members", [
        'member_ids' => [$memberAdd['id']],
    ], $creatorToken);
    $addMemberData = body_data($addMember);
    assert_eq(200, $addMember['http_code'], 'Add group member returns 200', $passed, $failed, $errors);
    assert_eq(4, (int) ($addMemberData['member_count'] ?? 0), 'Add group member increases member count to 4', $passed, $failed, $errors);

    $sendSecond = request('POST', "{$API_BASE}/api/v1/internal-chat/messages", [
        'conversation_key' => $conversationKey,
        'message' => 'Second group message ' . $seed,
    ], $creatorToken);
    assert_eq(200, $sendSecond['http_code'], 'Send second group message returns 200', $passed, $failed, $errors);
    assert_eq($initialUnreadAdd + 1, unread_count($API_BASE, $memberAddToken), 'Unread count increases for newly added member', $passed, $failed, $errors);

    $beforeRemoveUnread = unread_count($API_BASE, $memberRemoveToken);
    assert_eq($initialUnreadRemove + 2, $beforeRemoveUnread, 'Unread count accumulates for member before removal', $passed, $failed, $errors);

    $removeMember = request('DELETE', "{$API_BASE}/api/v1/internal-chat/groups/{$groupId}/members/{$memberRemove['id']}", null, $creatorToken);
    $removeMemberData = body_data($removeMember);
    assert_eq(200, $removeMember['http_code'], 'Remove group member returns 200', $passed, $failed, $errors);
    assert_eq(3, (int) ($removeMemberData['member_count'] ?? 0), 'Remove group member decreases member count to 3', $passed, $failed, $errors);

    section('5. Removed Member Unread Cleanup');
    assert_eq(
        $initialUnreadRemove,
        unread_count($API_BASE, $memberRemoveToken),
        'Unread count returns to baseline after group member removal',
        $passed,
        $failed,
        $errors
    );

    $removedSummary = conversation_summary($API_BASE, $memberRemoveToken, $conversationKey);
    assert_eq(null, $removedSummary, 'Removed member no longer sees the group conversation summary', $passed, $failed, $errors);

    $removedMessages = request(
        'GET',
        "{$API_BASE}/api/v1/internal-chat/conversations/" . rawurlencode($conversationKey) . '/messages',
        null,
        $memberRemoveToken
    );
    assert_eq(404, $removedMessages['http_code'], 'Removed member cannot fetch group messages', $passed, $failed, $errors);

    $removedRead = request(
        'POST',
        "{$API_BASE}/api/v1/internal-chat/conversations/" . rawurlencode($conversationKey) . '/read',
        null,
        $memberRemoveToken
    );
    assert_eq(404, $removedRead['http_code'], 'Removed member cannot mark removed conversation as read', $passed, $failed, $errors);

    section('6. Replies, Reactions, And Typing');
    $replySend = request('POST', "{$API_BASE}/api/v1/internal-chat/messages", [
        'conversation_key' => $conversationKey,
        'message' => 'Replying in group ' . $seed,
        'reply_to_message_id' => $firstMessageId,
    ], $memberReadToken);
    $replySendItems = body_data($replySend)['items'] ?? [];
    $replyMessageId = (string) (($replySendItems[0]['id'] ?? ''));

    assert_eq(200, $replySend['http_code'], 'Reply send returns 200', $passed, $failed, $errors);
    assert_true(is_array($replySendItems) && count($replySendItems) === 1, 'Reply send returns one created item', $passed, $failed, $errors);
    assert_eq($firstMessageId, (string) ($replySendItems[0]['reply_to_message_id'] ?? ''), 'Reply send echoes reply_to_message_id', $passed, $failed, $errors);
    assert_eq($firstMessageId, (string) (($replySendItems[0]['reply_preview']['message_id'] ?? '')), 'Reply send includes reply preview metadata', $passed, $failed, $errors);
    assert_true($replyMessageId !== '', 'Reply send returns a reply message id', $passed, $failed, $errors);

    $creatorMessagesAfterReply = request(
        'GET',
        "{$API_BASE}/api/v1/internal-chat/conversations/" . rawurlencode($conversationKey) . '/messages',
        null,
        $creatorToken
    );
    $creatorReplyMessages = body_data($creatorMessagesAfterReply)['items'] ?? [];
    $replyMessage = find_message_by_id(is_array($creatorReplyMessages) ? $creatorReplyMessages : [], $replyMessageId);

    assert_eq(200, $creatorMessagesAfterReply['http_code'], 'Creator can fetch group messages after reply', $passed, $failed, $errors);
    assert_true(is_array($replyMessage), 'Fetched messages include the reply item', $passed, $failed, $errors);
    assert_eq($firstMessageId, (string) ($replyMessage['reply_to_message_id'] ?? ''), 'Fetched reply item includes reply_to_message_id', $passed, $failed, $errors);
    assert_eq($firstMessageId, (string) (($replyMessage['reply_preview']['message_id'] ?? '')), 'Fetched reply item includes reply preview', $passed, $failed, $errors);

    $toggleReaction = request(
        'POST',
        "{$API_BASE}/api/v1/internal-chat/messages/{$firstMessageId}/reaction",
        ['emoji' => '👍'],
        $memberReadToken
    );
    $toggleReactionData = body_data($toggleReaction);

    assert_eq(200, $toggleReaction['http_code'], 'Toggle reaction returns 200', $passed, $failed, $errors);
    assert_eq($firstMessageId, (string) ($toggleReactionData['message_id'] ?? ''), 'Reaction payload returns the message id', $passed, $failed, $errors);
    assert_eq('👍', (string) ($toggleReactionData['current_user_reaction'] ?? ''), 'Reaction payload returns the current user reaction', $passed, $failed, $errors);
    assert_eq(1, (int) (($toggleReactionData['reactions'][0]['count'] ?? 0)), 'Reaction payload returns a single reaction count', $passed, $failed, $errors);

    $memberReadMessages = request(
        'GET',
        "{$API_BASE}/api/v1/internal-chat/conversations/" . rawurlencode($conversationKey) . '/messages',
        null,
        $memberReadToken
    );
    $memberReadMessage = find_message_by_id(
        is_array(body_data($memberReadMessages)['items'] ?? null) ? body_data($memberReadMessages)['items'] : [],
        $firstMessageId
    );

    assert_eq(200, $memberReadMessages['http_code'], 'Reacting member can refetch group messages', $passed, $failed, $errors);
    assert_eq('👍', (string) ($memberReadMessage['current_user_reaction'] ?? ''), 'Reacting member sees their current reaction in message metadata', $passed, $failed, $errors);
    assert_eq(1, (int) (($memberReadMessage['reactions'][0]['count'] ?? 0)), 'Reacting member sees the persisted reaction count', $passed, $failed, $errors);

    $creatorMessagesAfterReaction = request(
        'GET',
        "{$API_BASE}/api/v1/internal-chat/conversations/" . rawurlencode($conversationKey) . '/messages',
        null,
        $creatorToken
    );
    $creatorReactedMessage = find_message_by_id(
        is_array(body_data($creatorMessagesAfterReaction)['items'] ?? null) ? body_data($creatorMessagesAfterReaction)['items'] : [],
        $firstMessageId
    );

    assert_eq(200, $creatorMessagesAfterReaction['http_code'], 'Creator can refetch group messages after reaction update', $passed, $failed, $errors);
    assert_eq(null, $creatorReactedMessage['current_user_reaction'] ?? null, 'Other members do not inherit the reactor current_user_reaction value', $passed, $failed, $errors);
    assert_eq(1, (int) (($creatorReactedMessage['reactions'][0]['count'] ?? 0)), 'Other members see the shared reaction summary', $passed, $failed, $errors);

    $typingStart = request(
        'POST',
        "{$API_BASE}/api/v1/internal-chat/conversations/" . rawurlencode($conversationKey) . '/typing',
        ['is_typing' => true],
        $memberReadToken
    );
    $typingStartData = body_data($typingStart);

    assert_eq(200, $typingStart['http_code'], 'Typing start returns 200', $passed, $failed, $errors);
    assert_eq([], $typingStartData['typing_user_ids'] ?? null, 'Typing start hides the current user from their own response payload', $passed, $failed, $errors);

    $typingStateForCreator = request(
        'GET',
        "{$API_BASE}/api/v1/internal-chat/conversations/" . rawurlencode($conversationKey) . '/typing',
        null,
        $creatorToken
    );
    $typingStateData = body_data($typingStateForCreator);
    assert_eq(200, $typingStateForCreator['http_code'], 'Typing state fetch returns 200 for another group member', $passed, $failed, $errors);
    assert_true(
        in_array((string) $memberRead['id'], $typingStateData['typing_user_ids'] ?? [], true),
        'Typing state includes the active typing user for other members',
        $passed,
        $failed,
        $errors
    );

    $typingStop = request(
        'POST',
        "{$API_BASE}/api/v1/internal-chat/conversations/" . rawurlencode($conversationKey) . '/typing',
        ['is_typing' => false],
        $memberReadToken
    );
    assert_eq(200, $typingStop['http_code'], 'Typing stop returns 200', $passed, $failed, $errors);

    $typingStateAfterStop = request(
        'GET',
        "{$API_BASE}/api/v1/internal-chat/conversations/" . rawurlencode($conversationKey) . '/typing',
        null,
        $creatorToken
    );
    assert_eq(200, $typingStateAfterStop['http_code'], 'Typing state still returns 200 after typing stops', $passed, $failed, $errors);
    assert_true(
        !in_array((string) $memberRead['id'], body_data($typingStateAfterStop)['typing_user_ids'] ?? [], true),
        'Typing state no longer includes the user after typing stops',
        $passed,
        $failed,
        $errors
    );
} catch (Throwable $error) {
    $failed++;
    $errors[] = $error->getMessage();
    echo "  FAIL Unhandled exception: {$error->getMessage()}\n";
} finally {
    cleanup_group_artifacts($groupId, $conversationKey);
}

echo "\n==========================================================\n";
echo " Result: {$passed} passed, {$failed} failed\n";
echo "==========================================================\n";

if ($failed > 0) {
    echo "Failures:\n";
    foreach ($errors as $message) {
        echo " - {$message}\n";
    }
    exit(1);
}
