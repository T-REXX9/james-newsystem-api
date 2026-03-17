<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\RemarkTemplateRepository;
use App\Support\Exceptions\HttpException;

final class RemarkTemplateController
{
    public function __construct(private readonly RemarkTemplateRepository $repo)
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

        return $this->repo->listRemarkTemplates($search, $page, $perPage);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $remarkTemplateId = (int) ($params['remarkTemplateId'] ?? 0);
        if ($remarkTemplateId <= 0) {
            throw new HttpException(422, 'remarkTemplateId is required');
        }

        $record = $this->repo->getRemarkTemplateById($remarkTemplateId);
        if ($record === null) {
            throw new HttpException(404, 'Remark template not found');
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

        return $this->repo->createRemarkTemplate($name);
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $remarkTemplateId = (int) ($params['remarkTemplateId'] ?? 0);
        if ($remarkTemplateId <= 0) {
            throw new HttpException(422, 'remarkTemplateId is required');
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new HttpException(422, 'name is required');
        }

        $updated = $this->repo->updateRemarkTemplate($remarkTemplateId, $name);
        if ($updated === null) {
            throw new HttpException(404, 'Remark template not found');
        }

        return $updated;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $remarkTemplateId = (int) ($params['remarkTemplateId'] ?? 0);
        if ($remarkTemplateId <= 0) {
            throw new HttpException(422, 'remarkTemplateId is required');
        }

        $ok = $this->repo->deleteRemarkTemplate($remarkTemplateId);
        if (!$ok) {
            throw new HttpException(404, 'Remark template not found');
        }

        return [
            'deleted' => true,
            'remark_template_id' => $remarkTemplateId,
        ];
    }
}
