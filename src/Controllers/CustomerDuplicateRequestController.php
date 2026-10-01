<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Repositories\CustomerDuplicateRequestRepository;
use App\Services\CustomerMergeService;
use App\Support\Exceptions\HttpException;

/** Legacy endpoints that can only approve through the controlled merge flow. */
final class CustomerDuplicateRequestController
{
    private CustomerDuplicateRequestRepository $repository;

    public function __construct(
        private readonly Database $db,
        private readonly CustomerMergeService $mergeService
    ) {
        $this->repository = new CustomerDuplicateRequestRepository($db);
    }

    public function listPending(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->mainId($body, $query);
        $limit = min(500, max(1, (int) ($query['limit'] ?? 50)));
        $items = $this->repository->getPendingDuplicates($this->userId($body), $limit, $mainId);

        return ['items' => $items, 'count' => count($items), 'main_id' => $mainId];
    }

    public function getPendingCount(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->mainId($body, $query);
        return ['pendingCount' => $this->repository->getPendingCount($mainId), 'main_id' => $mainId];
    }

    public function approve(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->mainId($body, $query);
        $userId = $this->userId($body);
        $requestId = (int) ($params['id'] ?? 0);
        $request = $this->repository->getById($requestId, $mainId);
        if ($request === null || $request['status'] !== 'pending') {
            throw new HttpException(404, 'Pending duplicate request not found in this account.');
        }

        $this->requireMergePayload($body);
        $result = $this->mergeService->execute(
            $mainId,
            (string) $body['survivor_session_id'],
            (string) $body['duplicate_session_id'],
            (string) $body['final_company_name'],
            (string) $body['merge_reason'],
            (string) $body['confirmation'],
            (string) $body['idempotency_key'],
            $userId,
            $this->fieldDecisions($body),
            $requestId
        );

        return ['success' => true, 'message' => 'Duplicate request merged', 'merge' => $result];
    }

    public function reject(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->mainId($body, $query);
        if (!$this->repository->reject((int) ($params['id'] ?? 0), $this->userId($body), 'Master User', $mainId)) {
            throw new HttpException(409, 'Request is no longer pending or does not belong to this account.');
        }
        return ['success' => true, 'message' => 'Duplicate request rejected'];
    }

    public function snooze(array $params = [], array $query = [], array $body = []): array
    {
        $hours = (int) ($body['hours'] ?? 24);
        if ($hours < 1 || $hours > 168) {
            throw new HttpException(422, 'Invalid snooze duration (1-168 hours allowed).');
        }
        if (!$this->repository->snooze((int) ($params['id'] ?? 0), $hours, $this->mainId($body, $query))) {
            throw new HttpException(409, 'Request is no longer pending.');
        }
        return ['success' => true, 'message' => "Duplicate request snoozed for {$hours} hours"];
    }

    private function mainId(array $body, array $query): int
    {
        $mainId = (int) ($body['main_id'] ?? $query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(403, 'Invalid account scope.');
        }
        return $mainId;
    }

    private function userId(array $body): int
    {
        $userId = (int) ($body['__auth_claims']['sub'] ?? 0);
        if ($userId <= 0) {
            throw new HttpException(403, 'An authenticated master account is required.');
        }
        return $userId;
    }

    private function requireMergePayload(array $body): void
    {
        foreach (['survivor_session_id', 'duplicate_session_id', 'final_company_name', 'merge_reason', 'confirmation', 'idempotency_key'] as $field) {
            if (trim((string) ($body[$field] ?? '')) === '') {
                throw new HttpException(409, 'Approval requires a completed merge preview and explicit confirmation.');
            }
        }
    }

    /** @return array<string, string> */
    private function fieldDecisions(array $body): array
    {
        $decisions = $body['field_decisions'] ?? [];
        if (!is_array($decisions)) {
            throw new HttpException(422, 'field_decisions must be an object.');
        }
        foreach ($decisions as $field => $source) {
            if (!is_string($field) || !in_array($field, ['vat_type', 'terms', 'price_group', 'sales_person'], true)) {
                throw new HttpException(422, 'An unsupported merge field was supplied.');
            }
            if (!is_string($source) || !in_array($source, ['survivor', 'duplicate'], true)) {
                throw new HttpException(422, 'Each field decision must select survivor or duplicate.');
            }
        }
        $normalized = array_map('strval', $decisions);
        ksort($normalized);
        return $normalized;
    }
}
