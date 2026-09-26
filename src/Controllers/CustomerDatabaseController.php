<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Repositories\CustomerDatabaseRepository;
use App\Repositories\CustomerRequestRepository;
use App\Repositories\NotificationsRepository;
use App\Support\Exceptions\HttpException;
use RuntimeException;
use InvalidArgumentException;

final class CustomerDatabaseController
{
    public function __construct(
        private readonly CustomerDatabaseRepository $repo,
        private readonly Database $db,
    )
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
        $mode = trim((string) ($query['mode'] ?? 'full'));
        $perPage = (int) ($query['per_page'] ?? 100);
        if ($perPage < 0) {
            $perPage = 100;
        }

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

    public function nameCheck(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return [
            'items' => $this->repo->findSimilarCustomers($mainId, $query, (string) ($query['exclude_session_id'] ?? '')),
        ];
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        $isProspect = (int) ($body['status'] ?? 1) === 3
            || str_contains(strtolower((string) ($body['profile_type'] ?? '')), 'prospect');
        $matches = $this->repo->findSimilarCustomers($mainId, $body);
        $duplicateReason = trim((string) ($body['duplicate_override_reason'] ?? ''));
        if ($matches !== [] && $duplicateReason === '') {
            throw new HttpException(422, 'A reason is required before adding a duplicate customer or prospect');
        }

        $account = $this->authAccount($userId);
        $isMasterUser = (string) ($account['ltype'] ?? '') === '1';
        if ($matches !== [] && $isProspect && !$isMasterUser) {
            $requestPayload = $body;
            unset($requestPayload['main_id'], $requestPayload['user_id'], $requestPayload['__auth_claims']);
            $request = (new CustomerRequestRepository($this->db))->createDuplicateProspect($mainId, $userId, $requestPayload);
            $prospectName = trim((string) ($body['company'] ?? '')) ?: 'an unnamed prospect';
            $actorName = $this->repo->getAccountDisplayName($userId) ?: 'A sales agent';
            try {
                (new NotificationsRepository($this->db))->create([
                    'recipient_id' => (string) $mainId,
                    'title' => 'Duplicate Prospect Approval Required',
                    'message' => sprintf('%s submitted duplicate prospect %s for approval.', $actorName, $prospectName),
                    'type' => 'info',
                    'category' => 'notification',
                    'main_id' => (string) $mainId,
                    'action_url' => 'sales-database-customer-database',
                    'metadata' => [
                        'entity_type' => 'duplicate_prospect_request',
                        'entity_id' => (string) $request['id'],
                        'contact_id' => (string) $request['contact_id'],
                        'action' => 'review',
                        'status' => 'pending',
                        'action_url' => 'sales-database-customer-database',
                        'refno' => 'duplicate-prospect-request:' . (string) $request['id'],
                        'idempotency_key' => 'duplicate-prospect-request:' . (string) $request['id'],
                        'category' => 'notification',
                    ],
                ]);
            } catch (\Throwable) {
                // A notification outage must not discard a persisted approval request.
            }
            return [
                'pending_approval' => true,
                'request_id' => $request['id'],
                'contact_id' => $request['contact_id'],
            ];
        }

        try {
            $customer = $this->repo->createCustomer($mainId, $userId, $body);
            $comment = trim((string) ($body['notes'] ?? ''));
            $isProspect = (int) ($body['status'] ?? 1) === 3
                || str_contains(strtolower((string) ($body['profile_type'] ?? '')), 'prospect');
            $isUnverifiedProspect = $isProspect
                && strtolower(trim((string) ($customer['verification'] ?? $body['verification'] ?? ''))) !== 'verified';
            if ($isUnverifiedProspect && $userId !== $mainId) {
                $contactId = (string) ($customer['session_id'] ?? $customer['lsessionid'] ?? '');
                $actorName = $this->repo->getAccountDisplayName($userId);
                $prospectName = trim((string) ($customer['company'] ?? '')) ?: 'an unnamed prospect';
                $message = sprintf(
                    '%s submitted prospective customer %s for verification.',
                    $actorName !== '' ? $actorName : 'A sales agent',
                    $prospectName
                );
                if ($comment !== '') {
                    $message .= ' Note: ' . $comment;
                }
                (new NotificationsRepository($this->db))->create([
                    'recipient_id' => (string) $mainId,
                    'title' => sprintf('Prospective Customer for Verification - %s', $prospectName),
                    'message' => $message,
                    'type' => 'info',
                    'category' => 'notification',
                    'main_id' => (string) $mainId,
                    'action_url' => 'home',
                    'metadata' => [
                        'entity_type' => 'prospect_customer_comment',
                        'entity_id' => $contactId,
                        'contact_id' => $contactId,
                        'prospect_name' => $prospectName,
                        'actor_id' => (string) $userId,
                        'actor_name' => $actorName,
                        'actor_role' => 'Sales Agent',
                        'action' => 'verification_submitted',
                        'status' => 'unread',
                        'action_url' => 'home',
                        'refno' => 'prospect-customer-comment:' . $contactId,
                        'idempotency_key' => 'prospect-customer-comment:' . $contactId,
                        'category' => 'notification',
                    ],
                ]);
            }
            return $customer;
        } catch (RuntimeException|InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function authAccount(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT lid, ltype FROM tblaccount WHERE lid = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
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
        } catch (RuntimeException|InvalidArgumentException $e) {
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
