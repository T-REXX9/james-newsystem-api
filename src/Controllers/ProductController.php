<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ProductRepository;
use App\Support\Exceptions\HttpException;

final class ProductController
{
    public function __construct(private readonly ProductRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $search = trim((string) ($query['search'] ?? ''));
        $status = strtolower(trim((string) ($query['status'] ?? 'all')));
        if (!in_array($status, ['all', 'active', 'inactive'], true)) {
            throw new HttpException(422, 'status must be one of: all, active, inactive');
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));

        return $this->repo->listProducts($mainId, $search, $status, $page, $perPage);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $session = trim((string) ($params['productSession'] ?? ''));
        if ($session === '') {
            throw new HttpException(422, 'productSession is required');
        }

        $item = $this->repo->getProductBySession($mainId, $session);
        if ($item === null) {
            throw new HttpException(404, 'Product not found');
        }

        return $item;
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }
        $userId = (int) ($body['user_id'] ?? 0);

        return $this->repo->createProduct($mainId, $userId, $body);
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }
        $session = trim((string) ($params['productSession'] ?? ''));
        if ($session === '') {
            throw new HttpException(422, 'productSession is required');
        }

        $updated = $this->repo->updateProduct($mainId, $session, $body);
        if ($updated === null) {
            throw new HttpException(404, 'Product not found');
        }
        return $updated;
    }

    public function bulkUpdate(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }
        $ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];
        if (count($ids) === 0) {
            throw new HttpException(422, 'ids is required');
        }
        $updates = is_array($body['updates'] ?? null) ? $body['updates'] : [];
        if (count($updates) === 0) {
            throw new HttpException(422, 'updates is required');
        }

        return $this->repo->bulkUpdateProducts($mainId, $ids, $updates);
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }
        $session = trim((string) ($params['productSession'] ?? ''));
        if ($session === '') {
            throw new HttpException(422, 'productSession is required');
        }

        $ok = $this->repo->deleteProduct($mainId, $session);
        if (!$ok) {
            throw new HttpException(404, 'Product not found');
        }

        return [
            'deleted' => true,
            'product_session' => $session,
        ];
    }
}
