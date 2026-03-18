<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CategoryRepository;
use App\Support\Exceptions\HttpException;

final class CategoryController
{
    public function __construct(private readonly CategoryRepository $repo)
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

        return $this->repo->listCategories($search, $page, $perPage);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $categoryId = (int) ($params['categoryId'] ?? 0);
        if ($categoryId <= 0) {
            throw new HttpException(422, 'categoryId is required');
        }

        $record = $this->repo->getCategoryById($categoryId);
        if ($record === null) {
            throw new HttpException(404, 'Category not found');
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

        return $this->repo->createCategory($name);
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $categoryId = (int) ($params['categoryId'] ?? 0);
        if ($categoryId <= 0) {
            throw new HttpException(422, 'categoryId is required');
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new HttpException(422, 'name is required');
        }

        $updated = $this->repo->updateCategory($categoryId, $name);
        if ($updated === null) {
            throw new HttpException(404, 'Category not found');
        }

        return $updated;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $categoryId = (int) ($params['categoryId'] ?? 0);
        if ($categoryId <= 0) {
            throw new HttpException(422, 'categoryId is required');
        }

        $ok = $this->repo->deleteCategory($categoryId);
        if (!$ok) {
            throw new HttpException(404, 'Category not found');
        }

        return [
            'deleted' => true,
            'category_id' => $categoryId,
        ];
    }
}
