<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PurchaseRequestRepository;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class PurchaseRequestController
{
    public function __construct(private readonly PurchaseRequestRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $status = trim((string) ($query['status'] ?? 'all'));
        $search = trim((string) ($query['search'] ?? ''));
        $month = isset($query['month']) ? (int) $query['month'] : null;
        $year = isset($query['year']) ? (int) $query['year'] : null;
        if ($month !== null && ($month < 1 || $month > 12)) {
            throw new HttpException(422, 'month must be between 1 and 12');
        }
        if ($year !== null && ($year < 2000 || $year > 2100)) {
            throw new HttpException(422, 'year must be between 2000 and 2100');
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));

        return $this->repo->listPurchaseRequests(
            $mainId,
            $status,
            $search,
            $page,
            $perPage,
            $month,
            $year
        );
    }

    public function nextNumber(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return [
            'pr_number' => $this->repo->nextPurchaseRequestNumber(),
        ];
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $prRefno = trim((string) ($params['prRefno'] ?? ''));
        if ($prRefno === '') {
            throw new HttpException(422, 'prRefno is required');
        }

        $record = $this->repo->getPurchaseRequest($mainId, $prRefno);
        if ($record === null) {
            throw new HttpException(404, 'Purchase request not found');
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
            return $this->repo->createPurchaseRequest($mainId, $userId, $body);
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

        $prRefno = trim((string) ($params['prRefno'] ?? ''));
        if ($prRefno === '') {
            throw new HttpException(422, 'prRefno is required');
        }

        try {
            $record = $this->repo->updatePurchaseRequest($mainId, $prRefno, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($record === null) {
            throw new HttpException(404, 'Purchase request not found');
        }

        return $record;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $prRefno = trim((string) ($params['prRefno'] ?? ''));
        if ($prRefno === '') {
            throw new HttpException(422, 'prRefno is required');
        }

        $deleted = $this->repo->deletePurchaseRequest($mainId, $prRefno);
        if (!$deleted) {
            throw new HttpException(404, 'Purchase request not found');
        }

        return [
            'deleted' => true,
            'refno' => $prRefno,
        ];
    }

    public function addItem(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        $prRefno = trim((string) ($params['prRefno'] ?? ''));
        if ($prRefno === '') {
            throw new HttpException(422, 'prRefno is required');
        }

        try {
            return $this->repo->addPurchaseRequestItem($mainId, $userId, $prRefno, $body);
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
            $item = $this->repo->updatePurchaseRequestItem($mainId, $itemId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($item === null) {
            throw new HttpException(404, 'Purchase request item not found');
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

        $deleted = $this->repo->deletePurchaseRequestItem($mainId, $itemId);
        if (!$deleted) {
            throw new HttpException(404, 'Purchase request item not found');
        }

        return [
            'deleted' => true,
            'item_id' => $itemId,
        ];
    }

    public function action(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        $prRefno = trim((string) ($params['prRefno'] ?? ''));
        if ($prRefno === '') {
            throw new HttpException(422, 'prRefno is required');
        }

        $action = strtolower(trim((string) ($params['action'] ?? '')));
        try {
            return $this->repo->applyAction($mainId, $userId, $prRefno, $action, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }
}

