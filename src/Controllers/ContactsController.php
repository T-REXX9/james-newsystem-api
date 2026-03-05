<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ContactsRepository;
use App\Support\Exceptions\HttpException;

final class ContactsController
{
    public function __construct(private readonly ContactsRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(500, (int) ($query['per_page'] ?? 100)));

        return $this->repo->list($mainId, $page, $perPage);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'id is required');
        }

        $contact = $this->repo->show($mainId, $id);
        if ($contact === null) {
            throw new HttpException(404, 'Contact not found');
        }

        return $contact;
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $company = trim((string) ($body['company'] ?? ''));
        if ($company === '') {
            throw new HttpException(422, 'company is required');
        }

        // Get current user ID (from session/auth)
        $userId = 1; // Default, should be from auth context

        $contact = $this->repo->create($mainId, $userId, $body);
        return $contact;
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'id is required');
        }

        // Verify contact exists
        $existing = $this->repo->show($mainId, $id);
        if ($existing === null) {
            throw new HttpException(404, 'Contact not found');
        }

        $this->repo->update($mainId, $id, $body);

        // Return updated contact
        return $this->repo->show($mainId, $id) ?? $existing;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'id is required');
        }

        // Verify contact exists
        $existing = $this->repo->show($mainId, $id);
        if ($existing === null) {
            throw new HttpException(404, 'Contact not found');
        }

        $success = $this->repo->delete($mainId, $id);
        if (!$success) {
            throw new HttpException(500, 'Failed to delete contact');
        }

        return ['success' => true, 'id' => $id];
    }

    public function bulkUpdate(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $ids = $body['ids'] ?? [];
        if (!is_array($ids) || count($ids) === 0) {
            throw new HttpException(422, 'ids array is required and must not be empty');
        }

        $updates = $body['updates'] ?? [];
        if (!is_array($updates) || count($updates) === 0) {
            throw new HttpException(422, 'updates object is required and must not be empty');
        }

        $this->repo->bulkUpdate($mainId, $ids, $updates);

        return ['success' => true, 'updated_count' => count($ids)];
    }
}
