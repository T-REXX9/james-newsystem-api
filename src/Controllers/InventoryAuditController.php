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

    public function listStockAdjustments(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->requireMainId($query);
        $month = (int) ($query['month'] ?? date('n'));
        $year = (int) ($query['year'] ?? date('Y'));
        if ($month < 1 || $month > 12) {
            throw new HttpException(422, 'month must be between 1 and 12');
        }
        if ($year < 2000 || $year > 2100) {
            throw new HttpException(422, 'year must be between 2000 and 2100');
        }

        return $this->repo->listStockAdjustments($mainId, $month, $year);
    }

    public function showStockAdjustment(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->requireMainId($query);
        $refno = $this->requireRefno($params);
        $partNo = trim((string) ($query['part_no'] ?? ''));
        $itemCode = trim((string) ($query['item_code'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(250, max(1, (int) ($query['per_page'] ?? 100)));

        $record = $this->repo->getStockAdjustment($mainId, $refno, $partNo, $itemCode, $page, $perPage);
        if ($record === null) {
            throw new HttpException(404, 'Stock adjustment not found');
        }
        return $record;
    }

    public function createStockAdjustment(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->requireMainId($body);
        $userId = trim((string) ($body['user_id'] ?? ''));
        if ($userId === '') {
            throw new HttpException(422, 'user_id is required');
        }

        try {
            return $this->repo->createStockAdjustment($mainId, $userId);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function saveStockAdjustmentCounts(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->requireMainId($body);
        $refno = $this->requireRefno($params);
        $entries = $body['entries'] ?? null;
        if (!is_array($entries) || $entries === []) {
            throw new HttpException(422, 'entries are required');
        }

        try {
            return $this->repo->saveStockAdjustmentCounts($mainId, $refno, $entries);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function postStockAdjustment(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->requireMainId($body);
        $refno = $this->requireRefno($params);
        try {
            $record = $this->repo->postStockAdjustment($mainId, $refno);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($record === null) {
            throw new HttpException(404, 'Stock adjustment not found');
        }
        return $record;
    }

    public function updateStockAdjustmentDate(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->requireMainId($body);
        $refno = $this->requireRefno($params);
        $date = trim((string) ($body['date'] ?? ''));
        if ($date === '') {
            throw new HttpException(422, 'date is required');
        }
        try {
            $record = $this->repo->updateStockAdjustmentDate($mainId, $refno, $date);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($record === null) {
            throw new HttpException(404, 'Stock adjustment not found');
        }
        return $record;
    }

    public function deleteStockAdjustmentItem(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->requireMainId($query);
        $refno = $this->requireRefno($params);
        $itemSession = trim((string) ($params['itemSession'] ?? ''));
        if ($itemSession === '') {
            throw new HttpException(422, 'itemSession is required');
        }
        try {
            $deleted = $this->repo->deleteStockAdjustmentItem($mainId, $refno, $itemSession);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        return ['deleted' => $deleted, 'item_session' => $itemSession];
    }

    public function deleteStockAdjustment(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->requireMainId($query);
        $refno = $this->requireRefno($params);
        try {
            $deleted = $this->repo->deleteStockAdjustment($mainId, $refno);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if (!$deleted) {
            throw new HttpException(404, 'Stock adjustment not found');
        }
        return ['deleted' => true, 'refno' => $refno];
    }

    /** @param array<string, mixed> $source */
    private function requireMainId(array $source): int
    {
        $mainId = (int) ($source['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }
        return $mainId;
    }

    /** @param array<string, mixed> $params */
    private function requireRefno(array $params): string
    {
        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }
        return $refno;
    }
}
