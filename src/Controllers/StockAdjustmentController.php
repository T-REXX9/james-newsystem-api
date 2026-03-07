<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\StockAdjustmentRepository;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class StockAdjustmentController
{
    public function __construct(private readonly StockAdjustmentRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return $this->repo->list($mainId);
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

        $item = $this->repo->getByRefno($mainId, $refno);
        if ($item === null) {
            throw new HttpException(404, 'Stock adjustment not found');
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

        try {
            return $this->repo->create($mainId, $userId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function finalize(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $userId = trim((string) ($body['user_id'] ?? ''));
        if ($userId === '') {
            throw new HttpException(422, 'user_id is required');
        }

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        try {
            $item = $this->repo->finalize($mainId, $userId, $refno);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($item === null) {
            throw new HttpException(404, 'Stock adjustment not found');
        }

        return $item;
    }
}
