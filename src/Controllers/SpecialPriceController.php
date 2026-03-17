<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SpecialPriceRepository;
use App\Security\TokenService;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class SpecialPriceController
{
    public function __construct(
        private readonly SpecialPriceRepository $repo,
        private readonly TokenService $tokens
    )
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->resolveMainIdFromToken();

        $search = trim((string) ($query['search'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));

        return $this->repo->listSpecialPrices($mainId, $search, $page, $perPage);
    }

    public function products(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->resolveMainIdFromToken();

        $search = trim((string) ($query['search'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));

        return $this->repo->listProducts($mainId, $search, $page, $perPage);
    }

    public function customers(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->resolveMainIdFromToken();

        $search = trim((string) ($query['search'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));

        return $this->repo->listCustomers($mainId, $search, $page, $perPage);
    }

    public function areas(array $params = [], array $query = [], array $body = []): array
    {
        $this->resolveMainIdFromToken();

        $search = trim((string) ($query['search'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));

        return $this->repo->listAreas($search, $page, $perPage);
    }

    public function categories(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->resolveMainIdFromToken();

        $search = trim((string) ($query['search'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));

        return $this->repo->listCategories($mainId, $search, $page, $perPage);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->resolveMainIdFromToken();

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        $record = $this->repo->getSpecialPrice($mainId, $refno);
        if ($record === null) {
            throw new HttpException(404, 'Special price not found');
        }

        return $record;
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->resolveMainIdFromToken();

        $itemSession = trim((string) ($body['item_session'] ?? ''));
        $type = trim((string) ($body['type'] ?? ''));
        $amount = $body['amount'] ?? null;
        if ($itemSession === '' || $type === '' || $amount === null || $amount === '') {
            throw new HttpException(422, 'item_session, type, and amount are required');
        }

        try {
            return $this->repo->createSpecialPrice($mainId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->resolveMainIdFromToken();

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        try {
            $record = $this->repo->updateSpecialPrice($mainId, $refno, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        if ($record === null) {
            throw new HttpException(404, 'Special price not found');
        }

        return $record;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->resolveMainIdFromToken();

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        try {
            $deleted = $this->repo->deleteSpecialPrice($mainId, $refno);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        if (!$deleted) {
            throw new HttpException(404, 'Special price not found');
        }

        return [
            'deleted' => true,
            'refno' => $refno,
        ];
    }

    public function addCustomer(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->resolveMainIdFromToken();

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        $patientRefno = trim((string) ($body['patient_refno'] ?? ''));
        if ($patientRefno === '') {
            throw new HttpException(422, 'patient_refno is required');
        }

        try {
            return $this->repo->addCustomer($mainId, $refno, $patientRefno);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Special price not found' ? 404 : 422;
            throw new HttpException($status, $e->getMessage());
        }
    }

    public function removeCustomer(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->resolveMainIdFromToken();

        $refno = trim((string) ($params['refno'] ?? ''));
        $patientRefno = trim((string) ($params['patientRefno'] ?? ''));
        if ($refno === '' || $patientRefno === '') {
            throw new HttpException(422, 'refno and patientRefno are required');
        }

        $deleted = $this->repo->removeCustomer($mainId, $refno, $patientRefno);
        if (!$deleted) {
            throw new HttpException(404, 'Customer special price not found');
        }

        return [
            'deleted' => true,
            'refno' => $refno,
            'patient_refno' => $patientRefno,
        ];
    }

    public function addArea(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->resolveMainIdFromToken();

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        $areaCode = trim((string) ($body['area_code'] ?? ''));
        if ($areaCode === '') {
            throw new HttpException(422, 'area_code is required');
        }

        try {
            return $this->repo->addArea($mainId, $refno, $areaCode);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Special price not found' ? 404 : 422;
            throw new HttpException($status, $e->getMessage());
        }
    }

    public function removeArea(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->resolveMainIdFromToken();

        $refno = trim((string) ($params['refno'] ?? ''));
        $areaCode = trim((string) ($params['areaCode'] ?? ''));
        if ($refno === '' || $areaCode === '') {
            throw new HttpException(422, 'refno and areaCode are required');
        }

        $deleted = $this->repo->removeArea($mainId, $refno, $areaCode);
        if (!$deleted) {
            throw new HttpException(404, 'Area special price not found');
        }

        return [
            'deleted' => true,
            'refno' => $refno,
            'area_code' => $areaCode,
        ];
    }

    public function addCategory(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->resolveMainIdFromToken();

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        $categoryId = trim((string) ($body['category_id'] ?? ''));
        if ($categoryId === '') {
            throw new HttpException(422, 'category_id is required');
        }

        try {
            return $this->repo->addCategory($mainId, $refno, $categoryId);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Special price not found' ? 404 : 422;
            throw new HttpException($status, $e->getMessage());
        }
    }

    public function removeCategory(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = $this->resolveMainIdFromToken();

        $refno = trim((string) ($params['refno'] ?? ''));
        $categoryId = trim((string) ($params['categoryId'] ?? ''));
        if ($refno === '' || $categoryId === '') {
            throw new HttpException(422, 'refno and categoryId are required');
        }

        $deleted = $this->repo->removeCategory($mainId, $refno, $categoryId);
        if (!$deleted) {
            throw new HttpException(404, 'Category special price not found');
        }

        return [
            'deleted' => true,
            'refno' => $refno,
            'category_id' => $categoryId,
        ];
    }

    private function resolveMainIdFromToken(): int
    {
        $claims = $this->requireAuthClaims();
        $mainId = (int) ($claims['main_userid'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(401, 'Invalid token tenant scope');
        }

        return $mainId;
    }

    private function requireAuthClaims(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
        if (!is_string($header) || trim($header) === '') {
            throw new HttpException(401, 'Authorization header is required');
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            throw new HttpException(401, 'Bearer token is required');
        }

        return $this->tokens->verify((string) $matches[1]);
    }
}
