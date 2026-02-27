<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SalesReturnReportRepository;
use App\Support\Exceptions\HttpException;

final class SalesReturnReportController
{
    public function __construct(private readonly SalesReturnReportRepository $repo)
    {
    }

    public function options(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return $this->repo->options($mainId);
    }

    public function report(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 100);
        if ($page <= 0) {
            $page = 1;
        }
        if ($perPage <= 0) {
            $perPage = 100;
        }
        $perPage = min($perPage, 200);

        $filters = [
            'date_from' => trim((string) ($query['date_from'] ?? '')),
            'date_to' => trim((string) ($query['date_to'] ?? '')),
            'status' => trim((string) ($query['status'] ?? '')),
            'search' => trim((string) ($query['search'] ?? '')),
        ];

        return $this->repo->report($mainId, $filters, $page, $perPage);
    }
}
