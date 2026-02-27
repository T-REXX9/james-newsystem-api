<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ReorderReportRepository;
use App\Support\Exceptions\HttpException;

final class ReorderReportController
{
    public function __construct(private readonly ReorderReportRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $warehouseType = strtolower(trim((string) ($query['warehouse_type'] ?? 'total')));
        if (!in_array($warehouseType, ['total', 'wh1'], true)) {
            throw new HttpException(422, 'warehouse_type must be one of: total, wh1');
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(500, (int) ($query['per_page'] ?? 100)));
        $search = trim((string) ($query['search'] ?? ''));
        $hideZeroReorder = $this->toBool($query['hide_zero_reorder'] ?? false);
        $hideZeroReplenish = $this->toBool($query['hide_zero_replenish'] ?? false);

        return $this->repo->listReport(
            $mainId,
            $warehouseType,
            $search,
            $page,
            $perPage,
            $hideZeroReorder,
            $hideZeroReplenish
        );
    }

    public function hideItems(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $itemIds = is_array($body['item_ids'] ?? null) ? $body['item_ids'] : [];
        if (count($itemIds) === 0) {
            throw new HttpException(422, 'item_ids is required');
        }

        $normalized = [];
        foreach ($itemIds as $id) {
            $value = (int) $id;
            if ($value > 0) {
                $normalized[] = $value;
            }
        }
        $normalized = array_values(array_unique($normalized));
        if (count($normalized) === 0) {
            throw new HttpException(422, 'item_ids must contain valid IDs');
        }

        $hidden = $this->repo->hideItems($mainId, $normalized);
        return [
            'hidden' => $hidden,
            'requested' => count($normalized),
        ];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $text = strtolower(trim((string) $value));
        return in_array($text, ['1', 'true', 'yes', 'on'], true);
    }
}
