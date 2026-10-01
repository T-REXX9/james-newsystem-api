<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CustomerMergeService;
use App\Support\Exceptions\HttpException;

final class CustomerMergeController
{
    public function __construct(private readonly CustomerMergeService $service)
    {
    }

    public function preview(array $params = [], array $query = [], array $body = []): array
    {
        [$mainId, $userId] = $this->authContext($body);
        return $this->service->preview(
            $mainId,
            (string) ($body['survivor_session_id'] ?? ''),
            (string) ($body['duplicate_session_id'] ?? ''),
            (string) ($body['final_company_name'] ?? ''),
            (string) ($body['merge_reason'] ?? ''),
            $userId,
            (string) ($body['idempotency_key'] ?? ''),
            $this->fieldDecisions($body)
        );
    }

    public function execute(array $params = [], array $query = [], array $body = []): array
    {
        [$mainId, $userId] = $this->authContext($body);
        return $this->service->execute(
            $mainId,
            (string) ($body['survivor_session_id'] ?? ''),
            (string) ($body['duplicate_session_id'] ?? ''),
            (string) ($body['final_company_name'] ?? ''),
            (string) ($body['merge_reason'] ?? ''),
            (string) ($body['confirmation'] ?? ''),
            (string) ($body['idempotency_key'] ?? ''),
            $userId,
            $this->fieldDecisions($body)
        );
    }

    /** @return array{0: int, 1: int} */
    private function authContext(array $body): array
    {
        $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
        if ((string) ($claims['user_type'] ?? '') !== '1') {
            throw new HttpException(403, 'Only the Master User can perform this action');
        }
        $mainId = (int) ($claims['main_userid'] ?? 0);
        $userId = (int) ($claims['sub'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'An authenticated master account is required.');
        }

        return [$mainId, $userId];
    }

    /** @return array<string, string> */
    private function fieldDecisions(array $body): array
    {
        $decisions = $body['field_decisions'] ?? [];
        if (!is_array($decisions)) {
            throw new HttpException(422, 'field_decisions must be an object.');
        }

        $normalized = [];
        foreach ($decisions as $field => $source) {
            if (!is_string($field) || !is_string($source)) {
                throw new HttpException(422, 'field_decisions must contain string values.');
            }
            if (!in_array($field, ['vat_type', 'terms', 'price_group', 'sales_person'], true)) {
                throw new HttpException(422, 'An unsupported merge field was supplied.');
            }
            if (!in_array($source, ['survivor', 'duplicate'], true)) {
                throw new HttpException(422, 'Each field decision must select survivor or duplicate.');
            }
            $normalized[$field] = $source;
        }

        ksort($normalized);
        return $normalized;
    }
}
