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
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return $this->repo->getConfig($mainId);
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $current = $this->repo->getConfig($mainId);
        $config = [
            'silver_entry_threshold' => $body['silver_entry_threshold'] ?? $current['silver_entry_threshold'],
            'gold_entry_threshold' => $body['gold_entry_threshold'] ?? $current['gold_entry_threshold'],
            'silver_maintenance_threshold' => $body['silver_maintenance_threshold'] ?? $current['silver_maintenance_threshold'],
            'gold_maintenance_threshold' => $body['gold_maintenance_threshold'] ?? $current['gold_maintenance_threshold'],
        ];

        $userId = (int) ($body['user_id'] ?? 0);
        return $this->repo->setConfig($mainId, $userId, $config);
    }
}
