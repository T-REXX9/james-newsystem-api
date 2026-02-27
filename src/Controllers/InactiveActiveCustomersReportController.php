<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\InactiveActiveCustomersReportRepository;
use App\Support\Exceptions\HttpException;

final class InactiveActiveCustomersReportController
{
    public function __construct(private readonly InactiveActiveCustomersReportRepository $repo)
    {
    }

    public function report(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $status = strtolower(trim((string) ($query['status'] ?? 'all')));
        if (!in_array($status, ['all', 'active', 'inactive'], true)) {
            throw new HttpException(422, 'status must be one of: all, active, inactive');
        }

        $search = trim((string) ($query['search'] ?? ''));
        $cutoffMonths = (int) ($query['cutoff_months'] ?? 3);
        if ($cutoffMonths <= 0) {
            $cutoffMonths = 3;
        }
        $cutoffMonths = min($cutoffMonths, 24);

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(300, (int) ($query['per_page'] ?? 100)));

        return $this->repo->report($mainId, $status, $search, $cutoffMonths, $page, $perPage);
    }
}

