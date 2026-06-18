<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\IncidentItemsReportRepository;
use App\Support\Exceptions\HttpException;

final class IncidentItemsReportController
{
    public function __construct(private readonly IncidentItemsReportRepository $repo)
    {
    }

    public function report(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $matchSource = strtolower(trim((string) ($query['match_source'] ?? 'all')));
        if (!in_array($matchSource, ['all', 'manual', 'related_transaction', 'description_match', 'imported'], true)) {
            throw new HttpException(422, 'match_source must be one of: all, manual, related_transaction, description_match, imported');
        }

        $filters = [
            'search' => trim((string) ($query['search'] ?? '')),
            'supplier' => trim((string) ($query['supplier'] ?? '')),
            'match_source' => $matchSource,
            'date_from' => trim((string) ($query['date_from'] ?? '')),
            'date_to' => trim((string) ($query['date_to'] ?? '')),
            'min_count' => max(1, (int) ($query['min_count'] ?? 1)),
        ];

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(300, (int) ($query['per_page'] ?? 100)));

        return $this->repo->report($mainId, $filters, $page, $perPage);
    }
}
