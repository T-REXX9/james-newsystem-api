<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\FastSlowInventoryReportRepository;
use App\Support\Exceptions\HttpException;

final class FastSlowInventoryReportController
{
    public function __construct(private readonly FastSlowInventoryReportRepository $repo)
    {
    }

    public function report(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $sortBy = trim((string) ($query['sort_by'] ?? 'sales_volume'));
        if (!in_array($sortBy, ['sales_volume', 'part_no'], true)) {
            throw new HttpException(422, 'sort_by must be one of: sales_volume, part_no');
        }

        $sortDirection = strtolower(trim((string) ($query['sort_direction'] ?? 'desc')));
        if (!in_array($sortDirection, ['asc', 'desc'], true)) {
            throw new HttpException(422, 'sort_direction must be one of: asc, desc');
        }

        return $this->repo->report($mainId, $sortBy, $sortDirection);
    }
}

