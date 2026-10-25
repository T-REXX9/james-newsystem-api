<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\DailyCallTargetRepository;
use App\Support\Exceptions\HttpException;

final class DailyCallTargetController
{
    public function __construct(private readonly DailyCallTargetRepository $repo)
    {
    }

    public function index(array $params = [], array $query = [], array $body = []): array
    {
        $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
        $mainId = (int) ($claims['main_userid'] ?? 0);
        if ($mainId <= 0) throw new HttpException(422, 'main_id is required');
        return $this->repo->getTarget($mainId);
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) throw new HttpException(422, 'main_id is required');
        $target = $body['monthly_target'] ?? null;
        if (!is_numeric($target) || (string) (int) $target !== (string) $target || (int) $target < 0 || (int) $target > 2147483647) {
            throw new HttpException(422, 'monthly_target must be a whole peso amount from 0 to 2147483647');
        }
        return $this->repo->setTarget($mainId, (int) $target);
    }
}
