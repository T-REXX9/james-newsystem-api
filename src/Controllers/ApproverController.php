<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ApproverRepository;
use App\Support\Exceptions\HttpException;

final class ApproverController
{
    public function __construct(private readonly ApproverRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $search = trim((string) ($query['search'] ?? ''));
        $module = trim((string) ($query['module'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));

        return $this->repo->listApprovers($mainId, $search, $module, $page, $perPage);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $approverId = (int) ($params['approverId'] ?? 0);
        if ($approverId <= 0) {
            throw new HttpException(422, 'approverId is required');
        }

        $approver = $this->repo->getApproverById($mainId, $approverId);
        if ($approver === null) {
            throw new HttpException(404, 'Approver not found');
        }

        return $approver;
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        // Accept both user_id and staff_id for flexibility
        $staffId = (int) ($body['user_id'] ?? $body['staff_id'] ?? 0);
        if ($staffId <= 0) {
            throw new HttpException(422, 'user_id (or staff_id) is required');
        }

        $module = trim((string) ($body['module'] ?? ''));
        if ($module === '') {
            throw new HttpException(422, 'module is required');
        }

        $level = (int) ($body['level'] ?? 1);
        if ($level < 1 || $level > 10) {
            throw new HttpException(422, 'level must be between 1 and 10');
        }

        return $this->repo->createApprover($mainId, $staffId, $module, $level);
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $approverId = (int) ($params['approverId'] ?? 0);
        if ($approverId <= 0) {
            throw new HttpException(422, 'approverId is required');
        }

        // Extract updatable fields from body
        $allowedFields = ['user_id', 'staff_id', 'module', 'level'];
        $data = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = $body[$field];
            }
        }

        // Validate level if provided
        if (isset($data['level'])) {
            $level = (int) $data['level'];
            if ($level < 1 || $level > 10) {
                throw new HttpException(422, 'level must be between 1 and 10');
            }
        }

        if (empty($data)) {
            throw new HttpException(422, 'No valid fields to update');
        }

        $updated = $this->repo->updateApprover($mainId, $approverId, $data);
        if ($updated === null) {
            throw new HttpException(404, 'Approver not found');
        }

        return $updated;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $approverId = (int) ($params['approverId'] ?? 0);
        if ($approverId <= 0) {
            throw new HttpException(422, 'approverId is required');
        }

        $ok = $this->repo->deleteApprover($mainId, $approverId);
        if (!$ok) {
            throw new HttpException(404, 'Approver not found');
        }

        return [
            'deleted' => true,
            'approver_id' => $approverId,
        ];
    }

    /**
     * Get available staff for approver selection
     */
    public function staff(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return $this->repo->getAvailableStaff($mainId);
    }
}
