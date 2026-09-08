<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/Exceptions/HttpException.php';
require __DIR__ . '/../src/Repositories/CallSystemRepositoryInterface.php';
require __DIR__ . '/../src/Controllers/CallSystemController.php';

use App\Controllers\CallSystemController;
use App\Repositories\CallSystemRepositoryInterface;
use App\Support\Exceptions\HttpException;

final class FakeCallSystemRepository implements CallSystemRepositoryInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $devices = [];

    /** @var array<string, mixed>|null */
    public ?array $lastCall = null;

    /** @var array<string, mixed>|null */
    public ?array $lastDialRequest = null;

    /** @var array<string, mixed>|null */
    public ?array $autoSettings = null;

    public function findDevice(string $deviceId): ?array
    {
        return $this->devices[$deviceId] ?? null;
    }

    public function upsertDevice(int $agentId, string $deviceId, string $status): array
    {
        $this->devices[$deviceId] = [
            'lid' => 1,
            'lagent_id' => $agentId,
            'ldevice_id' => $deviceId,
            'llast_seen' => '2026-08-24 00:00:00',
            'lstatus' => $status,
        ];
        return $this->devices[$deviceId];
    }

    public function updateHeartbeat(int $agentId, string $deviceId, string $status): array
    {
        $device = $this->devices[$deviceId] ?? null;
        if ($device === null || (int) $device['lagent_id'] !== $agentId) {
            throw new RuntimeException('Call device is not registered to this staff account');
        }
        $device['lstatus'] = $status;
        $this->devices[$deviceId] = $device;
        return $device;
    }

    public function findCustomerIdByPhone(int $mainId, string $phoneNumber): ?int
    {
        return $mainId === 7 && $phoneNumber !== '' ? 101 : null;
    }

    public function createCallLog(int $agentId, string $deviceId, array $call): array
    {
        $this->lastCall = $call;
        return [
            'created' => true,
            'duplicate' => false,
            'call' => $call + ['lagent_id' => $agentId, 'ldevice_id' => $deviceId],
        ];
    }

    public function createDialRequest(int $agentId, ?int $customerId, string $phoneNumber): array
    {
        $this->lastDialRequest = [
            'lid' => 5,
            'lagent_id' => $agentId,
            'lcustomer_id' => $customerId,
            'lphone_number' => $phoneNumber,
            'lstatus' => 'pending',
        ];
        return $this->lastDialRequest;
    }

    public function listPendingDialRequests(int $agentId): array
    {
        return array_values(array_filter(
            $this->lastDialRequest === null ? [] : [$this->lastDialRequest],
            static fn(array $request): bool => (int) $request['lagent_id'] === $agentId
        ));
    }

    public function updateDialRequestStatus(int $agentId, int $requestId, string $status): array
    {
        if ($this->lastDialRequest === null
            || (int) $this->lastDialRequest['lagent_id'] !== $agentId
            || (int) $this->lastDialRequest['lid'] !== $requestId
        ) {
            throw new RuntimeException('Dial request was not found for this staff account');
        }
        $this->lastDialRequest['lstatus'] = $status;
        return $this->lastDialRequest;
    }

    public function listDevices(int $viewerId, int $mainId, bool $canViewTeam): array
    {
        return [[
            'lagent_id' => $viewerId,
            'effective_status' => $canViewTeam ? 'device_offline' : 'background_active',
        ]];
    }

    public function listCallLogs(int $viewerId, int $mainId, bool $canViewTeam, array $filters = []): array
    {
        return [[
            'lagent_id' => $viewerId,
            'ldirection' => $filters['direction'] ?? '',
            'from_date' => $filters['from_date'] ?? '',
            'to_date' => $filters['to_date'] ?? '',
            'customer_id' => $filters['customer_id'] ?? 0,
        ]];
    }

    public function listCallRecords(int $viewerId, int $mainId, bool $canViewTeam, array $filters = []): array
    {
        return [[
            'lagent_id' => $viewerId,
            'ldirection' => $filters['direction'] ?? '',
            'month' => $filters['month'] ?? 0,
            'year' => $filters['year'] ?? 0,
        ]];
    }

    public function getAutoReplySettings(?int $agentId = null): ?array
    {
        return $this->autoSettings;
    }

    public function saveAutoReplySettings(?int $agentId, bool $active, int $templateId, int $cooldownMinutes): array
    {
        $this->autoSettings = [
            'lid' => 1,
            'lagent_id' => $agentId,
            'lis_active' => $active ? 1 : 0,
            'ltemplate_id' => $templateId,
            'lcooldown_minutes' => $cooldownMinutes,
        ];
        return $this->autoSettings;
    }

    public function processMissedCallAutoReply(int $agentId, string $phoneNumber, int $templateId, int $cooldownMinutes): array
    {
        return [
            'queued' => true,
            'reason' => 'queued',
            'phone_number' => $phoneNumber,
            'template_id' => $templateId,
            'cooldown_minutes' => $cooldownMinutes,
        ];
    }

    public function listAutoReplyAudit(int $viewerId, int $mainId, bool $canViewTeam): array
    {
        return [[
            'lagent_id' => $viewerId,
            'lphone_number' => '09171234567',
            'lmessage_sent' => 'We missed your call.',
        ]];
    }
}

$passed = 0;
$failed = 0;

function expect_true(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$message}\n";
        return;
    }
    $failed++;
    echo "FAIL {$message}\n";
}

function expect_http_exception(callable $callback, int $status, string $message): void
{
    try {
        $callback();
        expect_true(false, $message . ' (no exception)');
    } catch (HttpException $e) {
        expect_true($e->statusCode() === $status, $message);
    }
}

$repo = new FakeCallSystemRepository();
$controller = new CallSystemController($repo);

$registered = $controller->registerDevice([], [], [
    '__auth_claims' => ['sub' => 42],
    'device_id' => 'device-42',
    'status' => 'background_active',
]);
expect_true(($registered['registered'] ?? false) === true, 'register returns registered=true');
expect_true(($registered['device']['lagent_id'] ?? 0) === 42, 'register binds the device to the authenticated staff account');
expect_true(($registered['device']['lstatus'] ?? '') === 'background_active', 'register persists the reported device status');

$heartbeat = $controller->heartbeat([], [], [
    '__auth_claims' => ['sub' => 42],
    'device_id' => 'device-42',
]);
expect_true(($heartbeat['accepted'] ?? false) === true, 'heartbeat returns accepted=true');
expect_true(($heartbeat['device']['lstatus'] ?? '') === 'background_active', 'heartbeat defaults to background_active');

$reassigned = $controller->registerDevice([], [], [
    '__auth_claims' => ['sub' => 99],
    'device_id' => 'device-42',
]);
expect_true(($reassigned['registered'] ?? false) === true, 'registration rebinds a device already assigned to another staff account');
expect_true(($reassigned['device']['lagent_id'] ?? 0) === 99, 'registration stores the newly authenticated staff account as the device owner');

$newOwnerHeartbeat = $controller->heartbeat([], [], [
    '__auth_claims' => ['sub' => 99],
    'device_id' => 'device-42',
]);
expect_true(($newOwnerHeartbeat['accepted'] ?? false) === true, 'heartbeat accepts the staff account that now owns the device');

expect_http_exception(
    static fn() => $controller->heartbeat([], [], [
        '__auth_claims' => ['sub' => 42],
        'device_id' => 'device-42',
    ]),
    409,
    'heartbeat rejects the previous staff account after the device is rebound'
);

$sameAccount = $controller->registerDevice([], [], [
    '__auth_claims' => ['sub' => 99],
    'device_id' => 'device-42',
    'status' => 'background_active',
]);
expect_true(($sameAccount['registered'] ?? false) === true, 'registration still succeeds for the staff account that already owns the device');
expect_true(($sameAccount['device']['lagent_id'] ?? 0) === 99, 'same-account registration keeps the current owner');

$controller->registerDevice([], [], [
    '__auth_claims' => ['sub' => 42],
    'device_id' => 'device-42',
    'status' => 'background_active',
]);

expect_http_exception(
    static fn() => $controller->heartbeat([], [], [
        '__auth_claims' => ['sub' => 42],
    ]),
    422,
    'heartbeat rejects a missing device id'
);

expect_http_exception(
    static fn() => $controller->heartbeat([], [], [
        '__auth_claims' => ['sub' => 42],
        'device_id' => 'unknown-device',
    ]),
    409,
    'heartbeat rejects an unregistered device'
);

expect_http_exception(
    static fn() => $controller->registerDevice([], [], [
        '__auth_claims' => ['sub' => 42],
        'device_id' => 'device-42',
        'status' => 'unknown',
    ]),
    422,
    'registration rejects an invalid status'
);

$call = $controller->createCallLog([], [], [
    '__auth_claims' => ['sub' => 42, 'main_userid' => 7],
    'device_id' => 'device-42',
    'phone_number' => '+63 917 123 4567',
    'direction' => 'outbound',
    'duration_seconds' => 45,
    'call_timestamp' => '2026-08-24T10:00:00+08:00',
]);
expect_true(($call['created'] ?? false) === true, 'call-log ingestion returns created=true');
expect_true(($call['customer_matched'] ?? false) === true, 'call-log ingestion reports a matched customer');
expect_true(($repo->lastCall['customer_id'] ?? 0) === 101, 'call-log ingestion uses the server-side customer match');
expect_true(($repo->lastCall['call_timestamp'] ?? '') === '2026-08-24 02:00:00', 'call-log ingestion normalizes the timestamp to UTC');

expect_http_exception(
    static fn() => $controller->createCallLog([], [], [
        '__auth_claims' => ['sub' => 42, 'main_userid' => 7],
        'device_id' => 'device-42',
        'phone_number' => '09171234567',
        'direction' => 'connected',
        'call_timestamp' => '2026-08-24T10:00:00+08:00',
    ]),
    422,
    'call-log ingestion rejects an invalid direction'
);

expect_http_exception(
    static fn() => $controller->createCallLog([], [], [
        '__auth_claims' => ['sub' => 42, 'main_userid' => 7],
        'device_id' => 'device-42',
        'phone_number' => '09171234567',
        'direction' => 'missed',
        'call_timestamp' => 'not-a-date',
    ]),
    422,
    'call-log ingestion rejects an invalid timestamp'
);

$dial = $controller->createDialRequest([], [], [
    '__auth_claims' => ['sub' => 42, 'main_userid' => 7],
    'phone_number' => '09171234567',
]);
expect_true(($dial['queued'] ?? false) === true, 'dial request returns queued=true');
expect_true(($dial['customer_matched'] ?? false) === true, 'dial request reports a matched customer');

$pending = $controller->listPendingDialRequests([], [], [
    '__auth_claims' => ['sub' => 42, 'main_userid' => 7],
    'device_id' => 'device-42',
]);
expect_true(count($pending['requests'] ?? []) === 1, 'registered phone receives its pending dial request');

$repo->lastCall = null;
$updated = $controller->updateDialRequestStatus(['requestId' => '5'], [], [
    '__auth_claims' => ['sub' => 42, 'main_userid' => 7],
    'device_id' => 'device-42',
    'status' => 'dialed',
]);
expect_true(($updated['updated'] ?? false) === true, 'dial request status update returns updated=true');
expect_true(($updated['request']['lstatus'] ?? '') === 'dialed', 'dial request status changes to dialed');
expect_true(($repo->lastCall['phone_number'] ?? '') === '09171234567', 'dialed request is stored as a system call log');
expect_true(($repo->lastCall['direction'] ?? '') === 'outbound', 'system call log for a dialed request is outbound');
expect_true(($repo->lastCall['source'] ?? '') === 'manual', 'system call log does not wait for phone hardware sync');
expect_true(($repo->lastCall['duration_seconds'] ?? -1) === 0, 'system call log starts at zero duration until hardware sync');
expect_true(($repo->lastCall['customer_id'] ?? 0) === 101, 'system call log keeps the matched customer');

$controller->createDialRequest([], [], [
    '__auth_claims' => ['sub' => 42, 'main_userid' => 7],
    'phone_number' => '09179876543',
]);
$repo->lastCall = null;
$failedUpdate = $controller->updateDialRequestStatus(['requestId' => '5'], [], [
    '__auth_claims' => ['sub' => 42, 'main_userid' => 7],
    'device_id' => 'device-42',
    'status' => 'failed',
]);
expect_true(($failedUpdate['request']['lstatus'] ?? '') === 'failed', 'dial request status can be marked failed');
expect_true($repo->lastCall === null, 'a failed dial request is not stored as a call log');

expect_http_exception(
    static fn() => $controller->updateDialRequestStatus(['requestId' => '5'], [], [
        '__auth_claims' => ['sub' => 42, 'main_userid' => 7],
        'device_id' => 'device-42',
        'status' => 'pending',
    ]),
    422,
    'dial request status update rejects pending as a final status'
);

expect_http_exception(
    static fn() => $controller->listPendingDialRequests([], [], [
        '__auth_claims' => ['sub' => 42, 'main_userid' => 7],
        'device_id' => 'unregistered-device',
    ]),
    409,
    'dial-request polling rejects an unregistered device'
);

$agentDevices = $controller->listDevices([], [], [
    '__auth_claims' => ['sub' => 42, 'main_userid' => 7, 'user_type' => '2'],
]);
expect_true(($agentDevices['devices'][0]['effective_status'] ?? '') === 'background_active', 'staff device list is scoped to the authenticated agent');

$teamDevices = $controller->listDevices([], [], [
    '__auth_claims' => ['sub' => 1, 'main_userid' => 1, 'user_type' => '1'],
]);
expect_true(($teamDevices['devices'][0]['effective_status'] ?? '') === 'device_offline', 'Master User device list enables team visibility');

$logs = $controller->listCallLogs([], [
    'direction' => 'missed',
    'customer_id' => '77',
    'from_date' => '2026-08-01',
    'to_date' => '2026-08-24',
], [
    '__auth_claims' => ['sub' => 1, 'main_userid' => 1, 'user_type' => '1'],
]);
expect_true(($logs['calls'][0]['ldirection'] ?? '') === 'missed', 'call-history filters preserve direction');
expect_true(($logs['calls'][0]['from_date'] ?? '') === '2026-08-01', 'call-history filters preserve from_date');
expect_true(($logs['calls'][0]['customer_id'] ?? 0) === 77, 'call-history filters preserve customer_id');

expect_http_exception(
    static fn() => $controller->listCallLogs([], ['customer_id' => 'not-a-number'], [
        '__auth_claims' => ['sub' => 1, 'main_userid' => 1, 'user_type' => '1'],
    ]),
    422,
    'call-history rejects invalid customer_id'
);

expect_http_exception(
    static fn() => $controller->listCallLogs([], ['from_date' => '24-08-2026'], [
        '__auth_claims' => ['sub' => 1, 'main_userid' => 1, 'user_type' => '1'],
    ]),
    422,
    'call-history rejects non-ISO dates'
);

expect_http_exception(
    static fn() => $controller->listCallLogs([], ['from_date' => '2026-08-24', 'to_date' => '2026-08-01'], [
        '__auth_claims' => ['sub' => 1, 'main_userid' => 1, 'user_type' => '1'],
    ]),
    422,
    'call-history rejects reversed date ranges'
);

expect_http_exception(
    static fn() => $controller->saveAutoReplySettings([], [], [
        '__auth_claims' => ['sub' => 42, 'main_userid' => 7, 'user_type' => '2'],
        'template_id' => 5,
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]),
    403,
    'regular staff cannot change missed-call auto-reply settings'
);

$settings = $controller->saveAutoReplySettings([], [], [
    '__auth_claims' => ['sub' => 1, 'main_userid' => 1, 'user_type' => '1'],
    'template_id' => 5,
    'is_active' => true,
    'cooldown_minutes' => 120,
]);
expect_true(($settings['saved'] ?? false) === true, 'Master User can save missed-call auto-reply settings');
expect_true(($settings['settings']['lcooldown_minutes'] ?? 0) === 120, 'auto-reply cooldown is persisted');

$missedCall = $controller->createCallLog([], [], [
    '__auth_claims' => ['sub' => 42, 'main_userid' => 7, 'user_type' => '2'],
    'device_id' => 'device-42',
    'phone_number' => '09171234567',
    'direction' => 'missed',
    'call_timestamp' => '2026-08-24T10:00:00+08:00',
]);
expect_true(($missedCall['auto_reply']['queued'] ?? false) === true, 'missed call triggers the configured auto-reply flow');

$audit = $controller->listAutoReplyAudit([], [], [
    '__auth_claims' => ['sub' => 1, 'main_userid' => 1, 'user_type' => '1'],
]);
expect_true(count($audit['replies'] ?? []) === 1, 'Master User can view missed-call auto-reply audit history');

$callRecords = $controller->listCallRecords([], ['month' => '8', 'year' => '2026'], [
    '__auth_claims' => ['sub' => 1, 'main_userid' => 1, 'user_type' => '1'],
]);
expect_true(count($callRecords['records'] ?? []) > 0, 'Master User call records returns rows');
expect_true(($callRecords['records'][0]['month'] ?? 0) === 8, 'call records uses the month filter');
expect_true(($callRecords['records'][0]['year'] ?? 0) === 2026, 'call records uses the year filter');

expect_http_exception(
    static fn() => $controller->listCallRecords([], ['direction' => 'connected'], [
        '__auth_claims' => ['sub' => 1, 'main_userid' => 1, 'user_type' => '1'],
    ]),
    422,
    'call records rejects an invalid direction'
);

expect_http_exception(
    static fn() => $controller->listCallRecords([], ['month' => '8', 'year' => '2026'], [
        '__auth_claims' => ['sub' => 2, 'main_userid' => 1, 'user_type' => '0'],
    ]),
    403,
    'call records rejects non-Master-User access'
);

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
