<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ReturnToSupplierRepository;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class ReturnToSupplierController
{
    public function __construct(private readonly ReturnToSupplierRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        // Always show latest records across all dates for this module.
        $month = null;
        $year = null;

        $status = trim((string) ($query['status'] ?? 'all'));
        $search = trim((string) ($query['search'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));

        return $this->repo->listReturns($mainId, $month, $year, $status, $search, $page, $perPage);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $returnRefno = trim((string) ($params['returnRefno'] ?? ''));
        if ($returnRefno === '') {
            throw new HttpException(422, 'returnRefno is required');
        }

        $record = $this->repo->getReturn($mainId, $returnRefno);
        if ($record === null) {
            throw new HttpException(404, 'Return to supplier not found');
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
            return $this->repo->createReturn($mainId, $userId, $body);
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

        $returnRefno = trim((string) ($params['returnRefno'] ?? ''));
        if ($returnRefno === '') {
            throw new HttpException(422, 'returnRefno is required');
        }

        try {
            $record = $this->repo->updateReturn($mainId, $returnRefno, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        if ($record === null) {
            throw new HttpException(404, 'Return to supplier not found');
        }

        return $record;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $returnRefno = trim((string) ($params['returnRefno'] ?? ''));
        if ($returnRefno === '') {
            throw new HttpException(422, 'returnRefno is required');
        }

        try {
            $deleted = $this->repo->deleteReturn($mainId, $returnRefno);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        if (!$deleted) {
            throw new HttpException(404, 'Return to supplier not found');
        }

        return [
            'deleted' => true,
            'refno' => $returnRefno,
        ];
    }

    public function items(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $returnRefno = trim((string) ($params['returnRefno'] ?? ''));
        if ($returnRefno === '') {
            throw new HttpException(422, 'returnRefno is required');
        }

        try {
            return $this->repo->getReturnItems($mainId, $returnRefno);
        } catch (RuntimeException $e) {
            throw new HttpException(404, $e->getMessage());
        }
    }

    public function addItem(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        $returnRefno = trim((string) ($params['returnRefno'] ?? ''));
        if ($returnRefno === '') {
            throw new HttpException(422, 'returnRefno is required');
        }

        try {
            return $this->repo->addItem($mainId, $userId, $returnRefno, $body);
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
            $updated = $this->repo->updateItem($mainId, $itemId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        if ($updated === null) {
            throw new HttpException(404, 'Return to supplier item not found');
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

        try {
            $deleted = $this->repo->deleteItem($mainId, $itemId);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        if (!$deleted) {
            throw new HttpException(404, 'Return to supplier item not found');
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

        $returnRefno = trim((string) ($params['returnRefno'] ?? ''));
        if ($returnRefno === '') {
            throw new HttpException(422, 'returnRefno is required');
        }

        $action = trim((string) ($params['action'] ?? ''));
        if ($action === '') {
            throw new HttpException(422, 'action is required');
        }

        try {
            $record = $this->repo->applyAction($mainId, $returnRefno, $action, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        if ($record === null) {
            throw new HttpException(404, 'Return to supplier not found');
        }

        return $record;
    }

    public function searchReceivingReports(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $search = trim((string) ($query['search'] ?? $query['q'] ?? ''));
        $limit = max(1, (int) ($query['limit'] ?? 10));

        return $this->repo->searchReceivingReports($mainId, $search, $limit);
    }

    public function receivingReportItems(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $rrRefno = trim((string) ($params['rrRefno'] ?? ''));
        if ($rrRefno === '') {
            throw new HttpException(422, 'rrRefno is required');
        }

        $search = trim((string) ($query['search'] ?? $query['q'] ?? ''));
        $limit = max(1, (int) ($query['limit'] ?? 25));

        try {
            return $this->repo->getReceivingReportItemsForReturn($mainId, $rrRefno, $search, $limit);
        } catch (RuntimeException $e) {
            throw new HttpException(404, $e->getMessage());
        }
    }
}
