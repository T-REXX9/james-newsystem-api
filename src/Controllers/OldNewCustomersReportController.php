<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\OldNewCustomersReportRepository;
use App\Support\Exceptions\HttpException;

final class OldNewCustomersReportController
{
    public function __construct(private readonly OldNewCustomersReportRepository $repo)
    {
    }

    public function report(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $status = strtolower(trim((string) ($query['status'] ?? 'all')));
        if (!in_array($status, ['all', 'old', 'new'], true)) {
            throw new HttpException(422, 'status must be one of: all, old, new');
        }

        $search = trim((string) ($query['search'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(300, (int) ($query['per_page'] ?? 100)));

        return $this->repo->report($mainId, $status, $search, $page, $perPage);
    }
}
