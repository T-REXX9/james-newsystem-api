<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SalesDevelopmentReportRepository;
use App\Support\Exceptions\HttpException;

final class SalesDevelopmentReportController
{
    public function __construct(private readonly SalesDevelopmentReportRepository $repo)
    {
    }

    public function report(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $dateFrom = trim((string) ($query['date_from'] ?? ''));
        $dateTo = trim((string) ($query['date_to'] ?? ''));
        if ($dateFrom === '' || $dateTo === '') {
            throw new HttpException(422, 'date_from and date_to are required');
        }

        $category = strtolower(trim((string) ($query['category'] ?? 'not_purchase')));
        if (!in_array($category, ['not_purchase', 'no_stock'], true)) {
            throw new HttpException(422, 'category must be one of: not_purchase, no_stock');
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(300, (int) ($query['per_page'] ?? 100)));

        return $this->repo->report($mainId, $dateFrom, $dateTo, $category, $page, $perPage);
    }

    public function summary(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $dateFrom = trim((string) ($query['date_from'] ?? ''));
        $dateTo = trim((string) ($query['date_to'] ?? ''));
        if ($dateFrom === '' || $dateTo === '') {
            throw new HttpException(422, 'date_from and date_to are required');
        }

        $category = strtolower(trim((string) ($query['category'] ?? 'not_purchase')));
        if (!in_array($category, ['not_purchase', 'no_stock'], true)) {
            throw new HttpException(422, 'category must be one of: not_purchase, no_stock');
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(300, (int) ($query['per_page'] ?? 100)));

        return $this->repo->summary($mainId, $dateFrom, $dateTo, $category, $page, $perPage);
    }
}
