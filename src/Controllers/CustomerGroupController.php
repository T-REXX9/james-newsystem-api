<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CustomerGroupRepository;
use App\Support\Exceptions\HttpException;

final class CustomerGroupController
{
    public function __construct(private readonly CustomerGroupRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $search = trim((string) ($query['search'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));

        return $this->repo->listGroups($mainId, $search, $page, $perPage);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $groupId = (int) ($params['groupId'] ?? 0);
        if ($groupId <= 0) {
            throw new HttpException(422, 'groupId is required');
        }

        $record = $this->repo->getGroupById($mainId, $groupId);
        if ($record === null) {
            throw new HttpException(404, 'Customer group not found');
        }

        return $record;
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new HttpException(422, 'name is required');
        }

        return $this->repo->createGroup($mainId, $name);
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $groupId = (int) ($params['groupId'] ?? 0);
        if ($groupId <= 0) {
            throw new HttpException(422, 'groupId is required');
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new HttpException(422, 'name is required');
        }

        $updated = $this->repo->updateGroup($mainId, $groupId, $name);
        if ($updated === null) {
            throw new HttpException(404, 'Customer group not found');
        }

        return $updated;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $groupId = (int) ($params['groupId'] ?? 0);
        if ($groupId <= 0) {
            throw new HttpException(422, 'groupId is required');
        }

        $ok = $this->repo->deleteGroup($mainId, $groupId);
        if (!$ok) {
            throw new HttpException(404, 'Customer group not found');
        }

        return [
            'deleted' => true,
            'group_id' => $groupId,
        ];
    }
}
