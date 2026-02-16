<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SalesOrderRepository;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class SalesOrderController
{
    public function __construct(private readonly SalesOrderRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $month = null;
        if (isset($query['month']) && trim((string) $query['month']) !== '') {
            $monthVal = (int) $query['month'];
            if ($monthVal < 1 || $monthVal > 12) {
                throw new HttpException(422, 'month must be between 1 and 12');
            }
            $month = $monthVal;
        }

        $year = null;
        if (isset($query['year']) && trim((string) $query['year']) !== '') {
            $yearVal = (int) $query['year'];
            if ($yearVal < 2000 || $yearVal > 2100) {
                throw new HttpException(422, 'year must be between 2000 and 2100');
            }
            $year = $yearVal;
        }

        $status = trim((string) ($query['status'] ?? 'all'));
        $search = trim((string) ($query['search'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));
        $viewerUserId = max(0, (int) ($query['viewer_user_id'] ?? 0));

        return $this->repo->listSalesOrders($mainId, $month, $year, $status, $search, $page, $perPage, $viewerUserId);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $salesRefno = trim((string) ($params['salesRefno'] ?? ''));
        if ($salesRefno === '') {
            throw new HttpException(422, 'salesRefno is required');
        }

        $viewerUserId = max(0, (int) ($query['viewer_user_id'] ?? 0));
        $record = $this->repo->getSalesOrder($mainId, $salesRefno, $viewerUserId);
        if ($record === null) {
            throw new HttpException(404, 'Sales order not found');
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
            return $this->repo->createSalesOrder($mainId, $userId, $body);
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

        $salesRefno = trim((string) ($params['salesRefno'] ?? ''));
        if ($salesRefno === '') {
            throw new HttpException(422, 'salesRefno is required');
        }

        try {
            $record = $this->repo->updateSalesOrder($mainId, $salesRefno, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($record === null) {
            throw new HttpException(404, 'Sales order not found');
        }

        return $record;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $salesRefno = trim((string) ($params['salesRefno'] ?? ''));
        if ($salesRefno === '') {
            throw new HttpException(422, 'salesRefno is required');
        }

        $deleted = $this->repo->cancelSalesOrder($mainId, $salesRefno, (string) ($query['reason'] ?? ''));
        if (!$deleted) {
            throw new HttpException(404, 'Sales order not found');
        }

        return [
            'deleted' => true,
            'mode' => 'soft_cancel',
            'sales_refno' => $salesRefno,
        ];
    }

    public function addItem(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        $salesRefno = trim((string) ($params['salesRefno'] ?? ''));
        if ($salesRefno === '') {
            throw new HttpException(422, 'salesRefno is required');
        }

        try {
            return $this->repo->addItem($mainId, $userId, $salesRefno, $body);
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
            $item = $this->repo->updateItem($mainId, $itemId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($item === null) {
            throw new HttpException(404, 'Sales order item not found');
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

        $deleted = $this->repo->deleteItem($mainId, $itemId);
        if (!$deleted) {
            throw new HttpException(404, 'Sales order item not found');
        }

        return [
            'deleted' => true,
            'item_id' => $itemId,
        ];
    }

    public function action(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $salesRefno = trim((string) ($params['salesRefno'] ?? ''));
        if ($salesRefno === '') {
            throw new HttpException(422, 'salesRefno is required');
        }

        $action = trim((string) ($params['action'] ?? ''));
        if ($action === '') {
            throw new HttpException(422, 'action is required');
        }

        try {
            $record = $this->repo->applyAction($mainId, $salesRefno, $action, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($record === null) {
            throw new HttpException(404, 'Sales order not found');
        }

        return $record;
    }

    public function convertDocument(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        $salesRefno = trim((string) ($params['salesRefno'] ?? ''));
        if ($salesRefno === '') {
            throw new HttpException(422, 'salesRefno is required');
        }

        $documentType = trim((string) ($params['documentType'] ?? ''));
        if ($documentType === '') {
            throw new HttpException(422, 'documentType is required');
        }

        try {
            return $this->repo->convertToDocument($mainId, $userId, $salesRefno, $documentType, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }
}
