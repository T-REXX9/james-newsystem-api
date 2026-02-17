<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\TransferStockRepository;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class TransferStockController
{
    public function __construct(private readonly TransferStockRepository $repo)
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
        $dateFrom = trim((string) ($query['date_from'] ?? ''));
        $dateTo = trim((string) ($query['date_to'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));

        return $this->repo->listTransfers($mainId, $month, $year, $status, $search, $dateFrom, $dateTo, $page, $perPage);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $transferRefno = trim((string) ($params['transferRefno'] ?? ''));
        if ($transferRefno === '') {
            throw new HttpException(422, 'transferRefno is required');
        }

        $record = $this->repo->getTransfer($mainId, $transferRefno);
        if ($record === null) {
            throw new HttpException(404, 'Transfer stock not found');
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
            return $this->repo->createTransfer($mainId, $userId, $body);
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

        $transferRefno = trim((string) ($params['transferRefno'] ?? ''));
        if ($transferRefno === '') {
            throw new HttpException(422, 'transferRefno is required');
        }

        try {
            $record = $this->repo->updateTransfer($mainId, $transferRefno, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($record === null) {
            throw new HttpException(404, 'Transfer stock not found');
        }

        return $record;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $transferRefno = trim((string) ($params['transferRefno'] ?? ''));
        if ($transferRefno === '') {
            throw new HttpException(422, 'transferRefno is required');
        }

        $deleted = $this->repo->deleteTransfer($mainId, $transferRefno);
        if (!$deleted) {
            throw new HttpException(404, 'Transfer stock not found');
        }

        return [
            'deleted' => true,
            'transfer_refno' => $transferRefno,
        ];
    }

    public function addItem(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $transferRefno = trim((string) ($params['transferRefno'] ?? ''));
        if ($transferRefno === '') {
            throw new HttpException(422, 'transferRefno is required');
        }

        try {
            return $this->repo->addItem($mainId, $transferRefno, $body);
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
            throw new HttpException(404, 'Transfer stock item not found');
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
            throw new HttpException(404, 'Transfer stock item not found');
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

        $transferRefno = trim((string) ($params['transferRefno'] ?? ''));
        if ($transferRefno === '') {
            throw new HttpException(422, 'transferRefno is required');
        }

        $action = trim((string) ($params['action'] ?? ''));
        if ($action === '') {
            throw new HttpException(422, 'action is required');
        }

        try {
            $record = $this->repo->applyAction($mainId, $userId, $transferRefno, $action, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($record === null) {
            throw new HttpException(404, 'Transfer stock not found');
        }

        return $record;
    }
}

