<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ReceivingStockRepository;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class ReceivingStockController
{
    public function __construct(private readonly ReceivingStockRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $month = (int) ($query['month'] ?? (int) date('m'));
        if ($month < 1 || $month > 12) {
            throw new HttpException(422, 'month must be between 1 and 12');
        }

        $year = (int) ($query['year'] ?? (int) date('Y'));
        if ($year < 2000 || $year > 2100) {
            throw new HttpException(422, 'year must be between 2000 and 2100');
        }

        $status = trim((string) ($query['status'] ?? 'all'));
        $search = trim((string) ($query['search'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));

        return $this->repo->listReceivingStocks($mainId, $month, $year, $status, $search, $page, $perPage);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $refno = trim((string) ($params['receivingRefno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'receivingRefno is required');
        }

        $record = $this->repo->getReceivingStock($mainId, $refno);
        if ($record === null) {
            throw new HttpException(404, 'Receiving stock record not found');
        }

        return $record;
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        try {
            return $this->repo->createReceivingStock($mainId, $userId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $refno = trim((string) ($params['receivingRefno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'receivingRefno is required');
        }

        try {
            $updated = $this->repo->updateReceivingStock($mainId, $refno, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($updated === null) {
            throw new HttpException(404, 'Receiving stock record not found');
        }

        return $updated;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $refno = trim((string) ($params['receivingRefno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'receivingRefno is required');
        }

        $deleted = $this->repo->deleteReceivingStock($mainId, $refno);
        if (!$deleted) {
            throw new HttpException(404, 'Receiving stock record not found');
        }

        return [
            'deleted' => true,
            'refno' => $refno,
        ];
    }

    public function addItem(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        $refno = trim((string) ($params['receivingRefno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'receivingRefno is required');
        }

        try {
            return $this->repo->addReceivingStockItem($mainId, $userId, $refno, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function updateItem(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $itemId = (int) ($params['itemId'] ?? 0);
        if ($itemId <= 0) {
            throw new HttpException(422, 'itemId is required');
        }

        try {
            $updated = $this->repo->updateReceivingStockItem($mainId, $itemId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($updated === null) {
            throw new HttpException(404, 'Receiving stock item not found');
        }

        return $updated;
    }

    public function deleteItem(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $itemId = (int) ($params['itemId'] ?? 0);
        if ($itemId <= 0) {
            throw new HttpException(422, 'itemId is required');
        }

        $deleted = $this->repo->deleteReceivingStockItem($mainId, $itemId);
        if (!$deleted) {
            throw new HttpException(404, 'Receiving stock item not found');
        }

        return [
            'deleted' => true,
            'item_id' => $itemId,
        ];
    }

    public function finalize(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $refno = trim((string) ($params['receivingRefno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'receivingRefno is required');
        }

        try {
            $record = $this->repo->finalizeReceivingStock(
                $mainId,
                $refno,
                trim((string) ($body['status'] ?? 'Delivered'))
            );
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($record === null) {
            throw new HttpException(404, 'Receiving stock record not found');
        }

        return $record;
    }
}
