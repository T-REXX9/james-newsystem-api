<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CourierRepository;
use App\Support\Exceptions\HttpException;

final class CourierController
{
    public function __construct(private readonly CourierRepository $repo)
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

        return $this->repo->listCouriers($search, $page, $perPage);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $courierId = (int) ($params['courierId'] ?? 0);
        if ($courierId <= 0) {
            throw new HttpException(422, 'courierId is required');
        }

        $record = $this->repo->getCourierById($courierId);
        if ($record === null) {
            throw new HttpException(404, 'Courier not found');
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

        return $this->repo->createCourier($name);
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $courierId = (int) ($params['courierId'] ?? 0);
        if ($courierId <= 0) {
            throw new HttpException(422, 'courierId is required');
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new HttpException(422, 'name is required');
        }

        $updated = $this->repo->updateCourier($courierId, $name);
        if ($updated === null) {
            throw new HttpException(404, 'Courier not found');
        }

        return $updated;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $courierId = (int) ($params['courierId'] ?? 0);
        if ($courierId <= 0) {
            throw new HttpException(422, 'courierId is required');
        }

        $ok = $this->repo->deleteCourier($courierId);
        if (!$ok) {
            throw new HttpException(404, 'Courier not found');
        }

        return [
            'deleted' => true,
            'courier_id' => $courierId,
        ];
    }
}
