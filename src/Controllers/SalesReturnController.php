<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SalesReturnRepository;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class SalesReturnController
{
    public function __construct(private readonly SalesReturnRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return $this->repo->list(
            $mainId,
            trim((string) ($query['search'] ?? '')),
            trim((string) ($query['status'] ?? '')),
            trim((string) ($query['month'] ?? '')),
            trim((string) ($query['year'] ?? '')),
            max(1, (int) ($query['page'] ?? 1)),
            max(1, (int) ($query['per_page'] ?? 50))
        );
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        $record = $this->repo->show($mainId, $refno);
        if ($record === null) {
            throw new HttpException(404, 'Sales return record not found');
        }

        return $record;
    }

    public function items(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        return $this->repo->items($mainId, $refno);
    }

    // -----------------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------------

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        try {
            return $this->repo->create($mainId, $userId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------------

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        try {
            return $this->repo->update($mainId, $refno, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Source Items (available items from linked Invoice/OR)
    // -----------------------------------------------------------------------

    public function sourceItems(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        try {
            return $this->repo->sourceItems($mainId, $refno);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Add Item
    // -----------------------------------------------------------------------

    public function addItem(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        try {
            return $this->repo->addItem($mainId, $refno, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Delete Item
    // -----------------------------------------------------------------------

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
            $this->repo->deleteItem($mainId, $itemId);
            return ['deleted' => true, 'item_id' => $itemId];
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Post
    // -----------------------------------------------------------------------

    public function postAction(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        try {
            return $this->repo->post($mainId, $userId, $refno);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Unpost
    // -----------------------------------------------------------------------

    public function unpostAction(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        try {
            return $this->repo->unpost($mainId, $userId, $refno);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }
}

