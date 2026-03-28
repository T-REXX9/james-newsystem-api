<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CustomerDatabaseRepository;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class CustomerDatabaseController
{
    public function __construct(private readonly CustomerDatabaseRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $search = trim((string) ($query['search'] ?? ''));
        $status = trim((string) ($query['status'] ?? 'all'));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));
        $mode = trim((string) ($query['mode'] ?? 'full'));

        return $this->repo->listCustomers($mainId, $search, $status, $page, $perPage, $mode);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $sessionId = trim((string) ($params['sessionId'] ?? ''));
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        $record = $this->repo->getCustomer($mainId, $sessionId);
        if ($record === null) {
            throw new HttpException(404, 'Customer not found');
        }

        return $record;
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        try {
            return $this->repo->createCustomer($mainId, $userId, $body);
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

        $sessionId = trim((string) ($params['sessionId'] ?? ''));
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        try {
            $record = $this->repo->updateCustomer($mainId, $sessionId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($record === null) {
            throw new HttpException(404, 'Customer not found');
        }

        return $record;
    }

    public function bulkUpdate(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $sessionIds = is_array($body['session_ids'] ?? null) ? $body['session_ids'] : [];
        if ($sessionIds === []) {
            throw new HttpException(422, 'session_ids is required');
        }

        $updates = is_array($body['updates'] ?? null) ? $body['updates'] : [];
        if ($updates === []) {
            throw new HttpException(422, 'updates is required');
        }

        try {
            return $this->repo->bulkUpdateCustomers($mainId, $sessionIds, $updates);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $sessionId = trim((string) ($params['sessionId'] ?? ''));
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        $deleted = $this->repo->deleteCustomer($mainId, $sessionId);
        if (!$deleted) {
            throw new HttpException(404, 'Customer not found');
        }

        return [
            'deleted' => true,
            'session_id' => $sessionId,
        ];
    }

    public function addContact(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $sessionId = trim((string) ($params['sessionId'] ?? ''));
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        try {
            return $this->repo->addContact($mainId, $sessionId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function updateContact(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $contactId = (int) ($params['contactId'] ?? 0);
        if ($contactId <= 0) {
            throw new HttpException(422, 'contactId is required');
        }

        try {
            $record = $this->repo->updateContact($mainId, $contactId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($record === null) {
            throw new HttpException(404, 'Contact not found');
        }

        return $record;
    }

    public function deleteContact(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $contactId = (int) ($params['contactId'] ?? 0);
        if ($contactId <= 0) {
            throw new HttpException(422, 'contactId is required');
        }

        $deleted = $this->repo->deleteContact($mainId, $contactId);
        if (!$deleted) {
            throw new HttpException(404, 'Contact not found');
        }

        return [
            'deleted' => true,
            'contact_id' => $contactId,
        ];
    }

    public function addTerm(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $sessionId = trim((string) ($params['sessionId'] ?? ''));
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        try {
            return $this->repo->addTerm($mainId, $sessionId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function listTerms(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $sessionId = trim((string) ($params['sessionId'] ?? ''));
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        try {
            return $this->repo->getTermsHistory($mainId, $sessionId);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'Customer not found') {
                throw new HttpException(404, $e->getMessage());
            }
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function updateTerm(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $termId = (int) ($params['termId'] ?? 0);
        if ($termId <= 0) {
            throw new HttpException(422, 'termId is required');
        }

        try {
            $record = $this->repo->updateTerm($mainId, $termId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($record === null) {
            throw new HttpException(404, 'Term not found');
        }

        return $record;
    }

    public function deleteTerm(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $termId = (int) ($params['termId'] ?? 0);
        if ($termId <= 0) {
            throw new HttpException(422, 'termId is required');
        }

        $deleted = $this->repo->deleteTerm($mainId, $termId);
        if (!$deleted) {
            throw new HttpException(404, 'Term not found');
        }

        return [
            'deleted' => true,
            'term_id' => $termId,
        ];
    }

    /**
     * GET /api/v1/customer-database/province-summary
     * Returns customer counts grouped by province for the Sales Map.
     */
    public function provinceSummary(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return [
            'data' => $this->repo->getCustomerCountsByProvince($mainId),
        ];
    }
}
