<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\StockMovementRepository;
use App\Support\Exceptions\HttpException;

final class StockMovementController
{
    public function __construct(private readonly StockMovementRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $itemId = trim((string) ($query['item_id'] ?? ''));
        if ($itemId === '') {
            throw new HttpException(422, 'item_id is required');
        }

        $warehouse = trim((string) ($query['warehouse_id'] ?? ''));
        $transactionType = trim((string) ($query['transaction_type'] ?? ''));
        $dateFrom = trim((string) ($query['date_from'] ?? ''));
        $dateTo = trim((string) ($query['date_to'] ?? ''));
        $search = trim((string) ($query['search'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 200));

        return $this->repo->listLogs(
            $mainId,
            $itemId,
            $warehouse,
            $transactionType,
            $dateFrom,
            $dateTo,
            $search,
            $page,
            $perPage
        );
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $logId = (int) ($params['logId'] ?? 0);
        if ($logId <= 0) {
            throw new HttpException(422, 'logId is required');
        }

        $item = $this->repo->getLog($mainId, $logId);
        if ($item === null) {
            throw new HttpException(404, 'Stock movement log not found');
        }

        return $item;
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $userId = trim((string) ($body['user_id'] ?? ''));
        if ($userId === '') {
            throw new HttpException(422, 'user_id is required');
        }

        $itemId = trim((string) ($body['item_id'] ?? ''));
        if ($itemId === '') {
            throw new HttpException(422, 'item_id is required');
        }

        $statusIndicator = trim((string) ($body['status_indicator'] ?? ''));
        if (!in_array($statusIndicator, ['+', '-'], true)) {
            throw new HttpException(422, 'status_indicator must be + or -');
        }

        return $this->repo->createLog($mainId, $userId, $body);
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $logId = (int) ($params['logId'] ?? 0);
        if ($logId <= 0) {
            throw new HttpException(422, 'logId is required');
        }

        if (isset($body['status_indicator']) && !in_array((string) $body['status_indicator'], ['+', '-'], true)) {
            throw new HttpException(422, 'status_indicator must be + or -');
        }

        $updated = $this->repo->updateLog($mainId, $logId, $body);
        if ($updated === null) {
            throw new HttpException(404, 'Stock movement log not found');
        }

        return $updated;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $logId = (int) ($params['logId'] ?? 0);
        if ($logId <= 0) {
            throw new HttpException(422, 'logId is required');
        }

        $ok = $this->repo->deleteLog($mainId, $logId);
        if (!$ok) {
            throw new HttpException(404, 'Stock movement log not found');
        }

        return [
            'deleted' => true,
            'id' => $logId,
        ];
    }
}

