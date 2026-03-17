<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SupplierRepository;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class SupplierController
{
    public function __construct(private readonly SupplierRepository $repo)
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

        return $this->repo->listSuppliers($mainId, $search, $page, $perPage);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $supplierId = (int) ($params['supplierId'] ?? 0);
        if ($supplierId <= 0) {
            throw new HttpException(422, 'supplierId is required');
        }

        $record = $this->repo->getSupplier($mainId, $supplierId);
        if ($record === null) {
            throw new HttpException(404, 'Supplier not found');
        }

        return $record;
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        try {
            return $this->repo->createSupplier($mainId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $supplierId = (int) ($params['supplierId'] ?? 0);
        if ($supplierId <= 0) {
            throw new HttpException(422, 'supplierId is required');
        }

        try {
            $record = $this->repo->updateSupplier($mainId, $supplierId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        if ($record === null) {
            throw new HttpException(404, 'Supplier not found');
        }

        return $record;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $supplierId = (int) ($params['supplierId'] ?? 0);
        if ($supplierId <= 0) {
            throw new HttpException(422, 'supplierId is required');
        }

        try {
            $deleted = $this->repo->deleteSupplier($mainId, $supplierId);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        if (!$deleted) {
            throw new HttpException(404, 'Supplier not found');
        }

        return [
            'deleted' => true,
            'supplier_id' => $supplierId,
        ];
    }
}
