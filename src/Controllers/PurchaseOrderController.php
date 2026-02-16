<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PurchaseOrderRepository;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class PurchaseOrderController
{
    public function __construct(private readonly PurchaseOrderRepository $repo)
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

        return $this->repo->listPurchaseOrders(
            $mainId,
            $month,
            $year,
            $status,
            $search,
            $page,
            $perPage
        );
    }

    public function suppliers(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return $this->repo->listSuppliers($mainId);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $purchaseRefno = trim((string) ($params['purchaseRefno'] ?? ''));
        if ($purchaseRefno === '') {
            throw new HttpException(422, 'purchaseRefno is required');
        }

        $record = $this->repo->getPurchaseOrder($mainId, $purchaseRefno);
        if ($record === null) {
            throw new HttpException(404, 'Purchase order not found');
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
            return $this->repo->createPurchaseOrder($mainId, $userId, $body);
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

        $purchaseRefno = trim((string) ($params['purchaseRefno'] ?? ''));
        if ($purchaseRefno === '') {
            throw new HttpException(422, 'purchaseRefno is required');
        }

        try {
            $record = $this->repo->updatePurchaseOrder($mainId, $purchaseRefno, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($record === null) {
            throw new HttpException(404, 'Purchase order not found');
        }

        return $record;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $purchaseRefno = trim((string) ($params['purchaseRefno'] ?? ''));
        if ($purchaseRefno === '') {
            throw new HttpException(422, 'purchaseRefno is required');
        }

        $deleted = $this->repo->deletePurchaseOrder($mainId, $purchaseRefno);
        if (!$deleted) {
            throw new HttpException(404, 'Purchase order not found');
        }

        return [
            'deleted' => true,
            'refno' => $purchaseRefno,
        ];
    }

    public function addItem(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        $purchaseRefno = trim((string) ($params['purchaseRefno'] ?? ''));
        if ($purchaseRefno === '') {
            throw new HttpException(422, 'purchaseRefno is required');
        }

        try {
            return $this->repo->addPurchaseOrderItem($mainId, $userId, $purchaseRefno, $body);
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
            $item = $this->repo->updatePurchaseOrderItem($mainId, $itemId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($item === null) {
            throw new HttpException(404, 'Purchase order item not found');
        }

        return $item;
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

        $deleted = $this->repo->deletePurchaseOrderItem($mainId, $itemId);
        if (!$deleted) {
            throw new HttpException(404, 'Purchase order item not found');
        }

        return [
            'deleted' => true,
            'item_id' => $itemId,
        ];
    }
}
