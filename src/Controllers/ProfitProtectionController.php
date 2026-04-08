<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ProfitProtectionRepository;
use App\Support\Exceptions\HttpException;

final class ProfitProtectionController
{
    public function __construct(private readonly ProfitProtectionRepository $repo)
    {
    }

    public function threshold(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return $this->repo->getThresholdConfig($mainId);
    }

    public function updateThreshold(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $current = $this->repo->getThresholdConfig($mainId);
        $config = [
            'percentage' => $body['percentage'] ?? $current['percentage'],
            'enforce_approval' => $body['enforce_approval'] ?? $current['enforce_approval'],
            'allow_override' => $body['allow_override'] ?? $current['allow_override'],
        ];

        $userId = (int) ($body['user_id'] ?? 0);
        return $this->repo->setThresholdConfig($mainId, $userId, $config);
    }

    public function validateItems(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $items = $body['items'] ?? null;
        if (!is_array($items)) {
            throw new HttpException(422, 'items must be an array');
        }

        return $this->repo->validateItems($mainId, $items);
    }

    public function createOverride(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $itemId = trim((string) ($body['item_id'] ?? ''));
        if ($itemId === '') {
            throw new HttpException(422, 'item_id is required');
        }

        $approvedBy = trim((string) ($body['approved_by'] ?? ''));
        if ($approvedBy === '' && isset($body['user_id'])) {
            $approvedBy = trim((string) $body['user_id']);
        }
        if ($approvedBy === '') {
            throw new HttpException(422, 'approved_by is required');
        }

        $body['approved_by'] = $approvedBy;
        return $this->repo->logProfitOverride($mainId, $body);
    }

    public function listOverrides(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $orderId = trim((string) ($query['order_id'] ?? ''));
        $itemId = trim((string) ($query['item_id'] ?? ''));
        $limit = min(500, max(1, (int) ($query['limit'] ?? 100)));

        return $this->repo->listProfitOverrides(
            $mainId,
            $orderId !== '' ? $orderId : null,
            $itemId !== '' ? $itemId : null,
            $limit
        );
    }

    public function overrideStats(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $startDate = trim((string) ($query['start_date'] ?? ''));
        $endDate = trim((string) ($query['end_date'] ?? ''));

        return $this->repo->profitOverrideStats(
            $mainId,
            $startDate !== '' ? $startDate : null,
            $endDate !== '' ? $endDate : null
        );
    }

    public function createAdminOverride(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $overrideType = trim((string) ($body['override_type'] ?? ''));
        $entityType = trim((string) ($body['entity_type'] ?? ''));
        $entityId = trim((string) ($body['entity_id'] ?? ''));
        if ($overrideType === '' || $entityType === '' || $entityId === '') {
            throw new HttpException(422, 'override_type, entity_type, and entity_id are required');
        }

        $performedBy = trim((string) ($body['performed_by'] ?? ''));
        if ($performedBy === '' && isset($body['user_id'])) {
            $performedBy = trim((string) $body['user_id']);
        }
        if ($performedBy === '') {
            throw new HttpException(422, 'performed_by is required');
        }

        $body['performed_by'] = $performedBy;
        return $this->repo->logAdminOverride($mainId, $body);
    }

    public function listAdminOverrides(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $overrideType = trim((string) ($query['override_type'] ?? ''));
        $entityType = trim((string) ($query['entity_type'] ?? ''));
        $entityId = trim((string) ($query['entity_id'] ?? ''));
        $limit = min(500, max(1, (int) ($query['limit'] ?? 100)));

        return $this->repo->listAdminOverrides(
            $mainId,
            $overrideType !== '' ? $overrideType : null,
            $entityType !== '' ? $entityType : null,
            $entityId !== '' ? $entityId : null,
            $limit
        );
    }
}
