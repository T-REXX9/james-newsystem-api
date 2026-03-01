<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ActivityLogRepository;
use App\Support\Exceptions\HttpException;

final class ActivityLogController
{
    public function __construct(private readonly ActivityLogRepository $repo)
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
            trim((string) ($query['user_id'] ?? '')),
            trim((string) ($query['date_from'] ?? '')),
            trim((string) ($query['date_to'] ?? '')),
            max(1, (int) ($query['page'] ?? 1)),
            max(1, (int) ($query['per_page'] ?? 100)),
            filter_var($query['include_total'] ?? false, FILTER_VALIDATE_BOOL)
        );
    }

    public function users(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return [
            'items' => $this->repo->users($mainId),
        ];
    }
}
