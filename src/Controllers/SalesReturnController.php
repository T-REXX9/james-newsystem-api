<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SalesReturnRepository;
use App\Support\Exceptions\HttpException;

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
}

