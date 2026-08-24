<?php

declare(strict_types=1);

namespace App\Repositories;

interface CallSystemRepositoryInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findDevice(string $deviceId): ?array;

    /**
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    public function upsertDevice(int $agentId, string $deviceId, string $status): array;

    /**
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    public function updateHeartbeat(int $agentId, string $deviceId, string $status): array;

    public function findCustomerIdByPhone(int $mainId, string $phoneNumber): ?int;

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public function createCallLog(int $agentId, string $deviceId, array $call): array;

    /**
     * @return array<string, mixed>
     */
    public function createDialRequest(int $agentId, ?int $customerId, string $phoneNumber): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPendingDialRequests(int $agentId): array;

    /**
     * @return array<string, mixed>
     */
    public function updateDialRequestStatus(int $agentId, int $requestId, string $status): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDevices(int $viewerId, int $mainId, bool $canViewTeam): array;

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listCallLogs(int $viewerId, int $mainId, bool $canViewTeam, array $filters = []): array;

    /**
     * @return array<string, mixed>|null
     */
    public function getAutoReplySettings(?int $agentId = null): ?array;

    /**
     * @return array<string, mixed>
     */
    public function saveAutoReplySettings(?int $agentId, bool $active, int $templateId, int $cooldownMinutes): array;

    /**
     * @return array<string, mixed>
     */
    public function processMissedCallAutoReply(int $agentId, string $phoneNumber, int $templateId, int $cooldownMinutes): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAutoReplyAudit(int $viewerId, int $mainId, bool $canViewTeam): array;
}
