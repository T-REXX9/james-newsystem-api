<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\VipTierSettingsRepository;
use App\Support\Exceptions\HttpException;

final class VipTierSettingsController
{
    public function __construct(private readonly VipTierSettingsRepository $repo)
    {
    }

    public function index(array $params = [], array $query = [], array $body = []): array
    {
        $this->assertOwner($body);
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return $this->repo->getConfig($mainId);
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $this->assertOwner($body);
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $current = $this->repo->getConfig($mainId);
        $config = [
            'one_time_discount_threshold' => $body['one_time_discount_threshold'] ?? $current['one_time_discount_threshold'],
            'unlimited_discount_threshold' => $body['unlimited_discount_threshold'] ?? $current['unlimited_discount_threshold'],
            'discount_percentage' => $body['discount_percentage'] ?? $current['discount_percentage'],
        ];

        $userId = (int) ($body['user_id'] ?? 0);
        return $this->repo->setConfig($mainId, $userId, $config);
    }

    private function assertOwner(array $body): void
    {
        $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
        if ((string) ($claims['user_type'] ?? '') !== '1') {
            throw new HttpException(403, 'Only account status role 1 can manage VIP discount settings.');
        }
    }
}
