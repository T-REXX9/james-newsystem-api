<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CallSystemRepositoryInterface;
use App\Services\InternalChatRealtimeNotifier;
use DateTimeImmutable;
use DateTimeZone;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class CallSystemController
{
    /**
     * @param CallSystemRepositoryInterface $repo
     */
    public function __construct(
        private readonly CallSystemRepositoryInterface $repo,
        private readonly ?InternalChatRealtimeNotifier $realtimeNotifier = null
    ) {
    }

    /**
     * Register the authenticated staff member's Android calling device.
     * The device cannot silently move between staff accounts.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function registerDevice(array $params = [], array $query = [], array $body = []): array
    {
        $agentId = $this->authenticatedAgentId($body);
        $deviceId = $this->deviceId($body);
        $status = $this->status($body, 'app_open');
        $existing = $this->repo->findDevice($deviceId);

        if ($existing !== null && (int) ($existing['lagent_id'] ?? 0) !== $agentId) {
            throw new HttpException(409, 'This phone is already assigned to another staff account');
        }

        try {
            return [
                'registered' => true,
                'device' => $this->repo->upsertDevice($agentId, $deviceId, $status),
            ];
        } catch (RuntimeException $e) {
            if (str_contains(strtolower($e->getMessage()), 'another staff')) {
                throw new HttpException(409, $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Record that the visible background service is still running.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function heartbeat(array $params = [], array $query = [], array $body = []): array
    {
        $agentId = $this->authenticatedAgentId($body);
        $deviceId = $this->deviceId($body);
        $status = $this->status($body, 'background_active');

        try {
            return [
                'accepted' => true,
                'device' => $this->repo->updateHeartbeat($agentId, $deviceId, $status),
            ];
        } catch (RuntimeException $e) {
            if (str_contains(strtolower($e->getMessage()), 'not registered')) {
                throw new HttpException(409, 'Call device is not registered to this staff account');
            }
            throw $e;
        }
    }

    /**
     * Ingest one call record reported by the authenticated Android device.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createCallLog(array $params = [], array $query = [], array $body = []): array
    {
        $agentId = $this->authenticatedAgentId($body);
        $deviceId = $this->deviceId($body);
        $device = $this->repo->findDevice($deviceId);
        if ($device === null || (int) ($device['lagent_id'] ?? 0) !== $agentId) {
            throw new HttpException(409, 'Call device is not registered to this staff account');
        }

        $mainId = $this->mainUserId($body);

        $phoneNumber = trim((string) ($body['phone_number'] ?? ''));
        if ($phoneNumber === '' || strlen($phoneNumber) > 50) {
            throw new HttpException(422, 'phone_number is required and must not exceed 50 characters');
        }

        $direction = strtolower(trim((string) ($body['direction'] ?? '')));
        if (!in_array($direction, ['inbound', 'outbound', 'missed'], true)) {
            throw new HttpException(422, 'direction must be inbound, outbound, or missed');
        }

        $durationSeconds = (int) ($body['duration_seconds'] ?? 0);
        if ($durationSeconds < 0 || $durationSeconds > 86400) {
            throw new HttpException(422, 'duration_seconds must be between 0 and 86400');
        }

        $callTimestamp = trim((string) ($body['call_timestamp'] ?? ''));
        if ($callTimestamp === '') {
            throw new HttpException(422, 'call_timestamp is required');
        }

        try {
            $timestamp = new DateTimeImmutable($callTimestamp);
        } catch (\Exception) {
            throw new HttpException(422, 'call_timestamp must be a valid date/time');
        }

        $customerId = $this->repo->findCustomerIdByPhone($mainId, $phoneNumber);
        $result = $this->repo->createCallLog($agentId, $deviceId, [
            'customer_id' => $customerId,
            'phone_number' => $phoneNumber,
            'direction' => $direction,
            'duration_seconds' => $durationSeconds,
            'call_timestamp' => $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'source' => 'hardware',
        ]);
        $result['customer_matched'] = $customerId !== null;
        $result['auto_reply'] = $this->processConfiguredMissedCallReply($agentId, $phoneNumber, $direction);

        return $result;
    }

    /**
     * Queue a dial request for the authenticated staff member's registered phone.
     * The website confirms the action before the Android app opens the native dialer.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createDialRequest(array $params = [], array $query = [], array $body = []): array
    {
        $agentId = $this->authenticatedAgentId($body);
        $mainId = $this->mainUserId($body);
        $phoneNumber = trim((string) ($body['phone_number'] ?? ''));
        if ($phoneNumber === '' || strlen($phoneNumber) > 50) {
            throw new HttpException(422, 'phone_number is required and must not exceed 50 characters');
        }

        $customerId = $this->repo->findCustomerIdByPhone($mainId, $phoneNumber);
        $request = $this->repo->createDialRequest($agentId, $customerId, $phoneNumber);
        $this->realtimeNotifier?->notifyDialRequestCreated($agentId, $request);

        return [
            'queued' => true,
            'request' => $request,
            'customer_matched' => $customerId !== null,
        ];
    }

    /**
     * Return pending dial requests for the authenticated phone.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function listPendingDialRequests(array $params = [], array $query = [], array $body = []): array
    {
        $agentId = $this->authenticatedAgentId($body);
        $this->registeredDevice($body, $agentId);

        return [
            'requests' => $this->repo->listPendingDialRequests($agentId),
        ];
    }

    /**
     * Mark a request as dialed or failed after the Android native-dial step.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateDialRequestStatus(array $params = [], array $query = [], array $body = []): array
    {
        $agentId = $this->authenticatedAgentId($body);
        $this->registeredDevice($body, $agentId);
        $requestId = (int) ($params['requestId'] ?? 0);
        if ($requestId <= 0) {
            throw new HttpException(422, 'requestId is required');
        }

        $status = strtolower(trim((string) ($body['status'] ?? '')));
        if (!in_array($status, ['dialed', 'failed'], true)) {
            throw new HttpException(422, 'status must be dialed or failed');
        }

        try {
            return [
                'updated' => true,
                'request' => $this->repo->updateDialRequestStatus($agentId, $requestId, $status),
            ];
        } catch (RuntimeException $e) {
            throw new HttpException(404, 'Pending dial request was not found for this staff account');
        }
    }

    /**
     * Return the global missed-call auto-reply configuration.
     * Only the Master User can view or change this setting.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function getAutoReplySettings(array $params = [], array $query = [], array $body = []): array
    {
        $this->assertMasterUser($body);
        return [
            'settings' => $this->repo->getAutoReplySettings(null),
        ];
    }

    /**
     * Save the global missed-call auto-reply configuration.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function saveAutoReplySettings(array $params = [], array $query = [], array $body = []): array
    {
        $this->assertMasterUser($body);
        $templateId = (int) ($body['template_id'] ?? 0);
        $cooldownMinutes = (int) ($body['cooldown_minutes'] ?? 60);
        if ($templateId <= 0) {
            throw new HttpException(422, 'template_id is required');
        }
        if ($cooldownMinutes < 1 || $cooldownMinutes > 10080) {
            throw new HttpException(422, 'cooldown_minutes must be between 1 and 10080');
        }

        try {
            return [
                'saved' => true,
                'settings' => $this->repo->saveAutoReplySettings(
                    null,
                    filter_var($body['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    $templateId,
                    $cooldownMinutes
                ),
            ];
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    /**
     * List the missed-call auto-reply audit history.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function listAutoReplyAudit(array $params = [], array $query = [], array $body = []): array
    {
        $this->assertMasterUser($body);
        $agentId = $this->authenticatedAgentId($body);
        $mainId = $this->mainUserId($body);
        return [
            'replies' => $this->repo->listAutoReplyAudit($agentId, $mainId, true),
        ];
    }

    /**
     * List registered devices with a server-derived health status.
     * Master Users can see devices under their account; staff can see only their own.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function listDevices(array $params = [], array $query = [], array $body = []): array
    {
        $agentId = $this->authenticatedAgentId($body);
        $mainId = $this->mainUserId($body);

        return [
            'devices' => $this->repo->listDevices($agentId, $mainId, $this->canViewTeam($body)),
        ];
    }

    /**
     * List call records within the authenticated staff/company scope.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function listCallLogs(array $params = [], array $query = [], array $body = []): array
    {
        $agentId = $this->authenticatedAgentId($body);
        $mainId = $this->mainUserId($body);
        $canViewTeam = $this->canViewTeam($body);
        $direction = strtolower(trim((string) ($query['direction'] ?? '')));
        if ($direction !== '' && !in_array($direction, ['inbound', 'outbound', 'missed'], true)) {
            throw new HttpException(422, 'direction must be inbound, outbound, or missed');
        }

        $customerId = (int) ($query['customer_id'] ?? 0);
        if (($query['customer_id'] ?? '') !== '' && $customerId <= 0) {
            throw new HttpException(422, 'customer_id must be a positive integer');
        }

        $filters = [
            'agent_id' => (int) ($query['agent_id'] ?? 0),
            'customer_id' => $customerId,
            'direction' => $direction,
            'from_date' => $this->dateFilter($query['from_date'] ?? null, 'from_date'),
            'to_date' => $this->dateFilter($query['to_date'] ?? null, 'to_date'),
        ];
        if ($filters['from_date'] !== '' && $filters['to_date'] !== '' && $filters['from_date'] > $filters['to_date']) {
            throw new HttpException(422, 'from_date must not be after to_date');
        }
        if (!$canViewTeam) {
            $filters['agent_id'] = 0;
        }

        return [
            'calls' => $this->repo->listCallLogs($agentId, $mainId, $canViewTeam, $filters),
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function authenticatedAgentId(array $body): int
    {
        $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
        $agentId = (int) ($claims['sub'] ?? 0);
        if ($agentId <= 0) {
            throw new HttpException(401, 'Invalid authenticated staff account');
        }

        return $agentId;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertMasterUser(array $body): void
    {
        if (!$this->canViewTeam($body)) {
            throw new HttpException(403, 'Master User access is required');
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function canViewTeam(array $body): bool
    {
        $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
        return (string) ($claims['user_type'] ?? '') === '1';
    }

    /**
     * @param mixed $value
     */
    private function dateFilter(mixed $value, string $field): string
    {
        $date = trim((string) ($value ?? ''));
        if ($date === '') {
            return '';
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $date) {
            throw new HttpException(422, $field . ' must use YYYY-MM-DD format');
        }

        return $date;
    }

    /**
     * @return array<string, mixed>
     */
    private function processConfiguredMissedCallReply(int $agentId, string $phoneNumber, string $direction): array
    {
        if ($direction !== 'missed') {
            return ['queued' => false, 'reason' => 'not_applicable'];
        }

        $settings = $this->repo->getAutoReplySettings(null);
        if (!is_array($settings) || (int) ($settings['lis_active'] ?? 0) !== 1) {
            return ['queued' => false, 'reason' => 'disabled'];
        }

        $templateId = (int) ($settings['ltemplate_id'] ?? 0);
        $cooldownMinutes = (int) ($settings['lcooldown_minutes'] ?? 60);
        if ($templateId <= 0) {
            return ['queued' => false, 'reason' => 'template_missing'];
        }

        return $this->repo->processMissedCallAutoReply($agentId, $phoneNumber, $templateId, $cooldownMinutes);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function mainUserId(array $body): int
    {
        $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
        $mainId = (int) ($claims['main_userid'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(403, 'Authenticated account has no valid company scope');
        }

        return $mainId;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function registeredDevice(array $body, int $agentId): array
    {
        $device = $this->repo->findDevice($this->deviceId($body));
        if ($device === null || (int) ($device['lagent_id'] ?? 0) !== $agentId) {
            throw new HttpException(409, 'Call device is not registered to this staff account');
        }

        return $device;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function deviceId(array $body): string
    {
        $deviceId = trim((string) ($body['device_id'] ?? ''));
        if ($deviceId === '') {
            throw new HttpException(422, 'device_id is required');
        }
        if (strlen($deviceId) > 255) {
            throw new HttpException(422, 'device_id must not exceed 255 characters');
        }

        return $deviceId;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function status(array $body, string $default): string
    {
        $status = trim((string) ($body['status'] ?? $default));
        $allowed = [
            'online',
            'app_open',
            'background_active',
            'signed_out',
            'permission_missing',
            'no_network',
        ];

        if (!in_array($status, $allowed, true)) {
            throw new HttpException(422, 'Invalid call-device status');
        }

        return $status;
    }
}
