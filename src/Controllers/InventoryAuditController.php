<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\InventoryAuditRepository;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class InventoryAuditController
{
    public function __construct(private readonly InventoryAuditRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $timePeriod = trim((string) ($query['time_period'] ?? 'all'));
        $allowedPeriods = ['all', 'today', 'week', 'month', 'year', 'custom'];
        if (!in_array(strtolower($timePeriod), $allowedPeriods, true)) {
            throw new HttpException(422, 'time_period must be one of: all, today, week, month, year, custom');
        }

        $dateFrom = trim((string) ($query['date_from'] ?? ''));
        $dateTo = trim((string) ($query['date_to'] ?? ''));
        if (strtolower($timePeriod) === 'custom' && ($dateFrom === '' || $dateTo === '')) {
            throw new HttpException(422, 'date_from and date_to are required when time_period is custom');
        }

        $partNo = trim((string) ($query['part_no'] ?? 'All'));
        $itemCode = trim((string) ($query['item_code'] ?? 'All'));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 50));

        try {
            return $this->repo->report(
                $mainId,
                $timePeriod,
                $dateFrom,
                $dateTo,
                $partNo,
                $itemCode,
                $page,
                $perPage
            );
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function filters(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return $this->repo->filterOptions($mainId);
    }

    public function showAdjustment(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $adjustmentId = (int) ($params['adjustmentId'] ?? 0);
        if ($adjustmentId <= 0) {
            throw new HttpException(422, 'adjustmentId is required');
        }

        $item = $this->repo->getAdjustment($mainId, $adjustmentId);
        if ($item === null) {
            throw new HttpException(404, 'Inventory audit adjustment not found');
        }
        return $item;
    }

    public function createAdjustment(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $userId = trim((string) ($body['user_id'] ?? ''));
        if ($userId === '') {
            throw new HttpException(422, 'user_id is required');
        }

        $itemSession = trim((string) ($body['item_session'] ?? $body['item_id'] ?? ''));
        if ($itemSession === '') {
            throw new HttpException(422, 'item_session is required');
        }

        try {
            return $this->repo->createAdjustment($mainId, $userId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function updateAdjustment(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $adjustmentId = (int) ($params['adjustmentId'] ?? 0);
        if ($adjustmentId <= 0) {
            throw new HttpException(422, 'adjustmentId is required');
        }

        try {
            $item = $this->repo->updateAdjustment($mainId, $adjustmentId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($item === null) {
            throw new HttpException(404, 'Inventory audit adjustment not found');
        }

        return $item;
    }

    public function deleteAdjustment(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $adjustmentId = (int) ($params['adjustmentId'] ?? 0);
        if ($adjustmentId <= 0) {
            throw new HttpException(422, 'adjustmentId is required');
        }

        $ok = $this->repo->deleteAdjustment($mainId, $adjustmentId);
        if (!$ok) {
            throw new HttpException(404, 'Inventory audit adjustment not found');
        }

        return [
            'deleted' => true,
            'id' => $adjustmentId,
        ];
    }
}
