<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\InventoryReportRepository;
use App\Support\Exceptions\HttpException;

final class InventoryReportController
{
    public function __construct(private readonly InventoryReportRepository $repo)
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

        $filters = [
            'category' => trim((string) ($query['category'] ?? '')),
            'part_number' => trim((string) ($query['part_number'] ?? '')),
            'item_code' => trim((string) ($query['item_code'] ?? '')),
            'stock_status' => strtolower(trim((string) ($query['stock_status'] ?? 'all'))),
            'report_type' => strtolower(trim((string) ($query['report_type'] ?? 'inventory'))),
            'date_from' => trim((string) ($query['date_from'] ?? '')),
            'date_to' => trim((string) ($query['date_to'] ?? '')),
        ];

        if (!in_array($filters['stock_status'], ['all', 'with_stock', 'without_stock'], true)) {
            throw new HttpException(422, 'stock_status must be one of: all, with_stock, without_stock');
        }
        if (!in_array($filters['report_type'], ['inventory', 'product'], true)) {
            throw new HttpException(422, 'report_type must be one of: inventory, product');
        }

        return $this->repo->report($mainId, $filters);
    }
}

