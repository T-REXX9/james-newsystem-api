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
        $cutoffMonths = 3;

        $yearFrom = isset($query['year_from']) && trim((string) $query['year_from']) !== ''
            ? (int) $query['year_from']
            : null;
        $yearTo = isset($query['year_to']) && trim((string) $query['year_to']) !== ''
            ? (int) $query['year_to']
            : null;
        $currentYear = (int) date('Y');
        foreach ([$yearFrom, $yearTo] as $year) {
            if ($year !== null && ($year < 2000 || $year > $currentYear)) {
                throw new HttpException(422, 'year range must be between 2000 and the current year');
            }
        }
        if ($yearFrom !== null && $yearTo !== null && $yearFrom > $yearTo) {
            throw new HttpException(422, 'year_from must be less than or equal to year_to');
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(300, (int) ($query['per_page'] ?? 100)));

        return $this->repo->report($mainId, $status, $search, $cutoffMonths, $page, $perPage, $yearFrom, $yearTo);
    }
}
