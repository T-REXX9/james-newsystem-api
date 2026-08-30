<?php

declare(strict_types=1);
namespace App\Controllers;

use App\Database;
use App\Repositories\AuthRepository;
use App\Repositories\CustomerRequestRepository;
use App\Repositories\NotificationsRepository;
use App\Repositories\SalesInquiryRepository;
use App\Repositories\SalesReturnRepository;
use App\Support\Exceptions\HttpException;

final class CustomerWorkflowController
{
    public function __construct(private readonly Database $db, private readonly AuthRepository $auth) {}

    private function context(array $query, array $body, bool $customerAccess = true): array
    {
        $claims = $body['__auth_claims'] ?? [];
        $userId = (int) ($claims['sub'] ?? 0);
        $mainId = (int) ($claims['main_userid'] ?? 0);
        $account = $this->auth->findUserById($userId);
        if (!$account || $mainId <= 0) throw new HttpException(401, 'Invalid authenticated account');
        if ($this->auth->resolveMainUserId($account) !== $mainId || (int) ($query['main_id'] ?? $mainId) !== $mainId || (int) ($body['main_id'] ?? $mainId) !== $mainId) throw new HttpException(403, 'Invalid account scope');
        $role = strtolower(trim((string) $this->auth->getRoleName((int) ($account['ltype'] ?? 0))));
        $owner = (string) $account['ltype'] === '1' || in_array($role, ['owner','company owner','developer'], true);
        $rights = $this->auth->getDerivedAccessRights($account);
        if ($customerAccess && !$owner && !array_intersect($rights, ['*','sales-transaction-daily-call-monitoring','sales-database-customer-database','maintenance-customer-customer-data'])) throw new HttpException(403, 'Customer workflow access required');
        return [$mainId, $userId, $owner];
    }

    public function inquiries(array $params, array $query, array $body): array
    {
        [$mainId] = $this->context($query, $body);
        $contactId = rawurldecode($params['contactId']);
        (new CustomerRequestRepository($this->db))->customer($mainId, $contactId);
        return (new SalesInquiryRepository($this->db))->listInquiries($mainId, '', 'all', max(1, (int) ($query['page'] ?? 1)), 100, $contactId);
    }

    public function returns(array $params, array $query, array $body): array
    {
        [$mainId] = $this->context($query, $body);
        $contactId = rawurldecode($params['contactId']);
        (new CustomerRequestRepository($this->db))->customer($mainId, $contactId);
        return (new SalesReturnRepository($this->db))->list($mainId, '', 'all', '', '', max(1, (int) ($query['page'] ?? 1)), 100, $contactId);
    }

    public function requests(array $params, array $query, array $body): array
    {
        [$mainId, $userId, $owner] = $this->context($query, $body);
        return (new CustomerRequestRepository($this->db))->list($mainId, rawurldecode($params['contactId']), $owner ? null : $userId);
    }

    public function allRequests(array $params, array $query, array $body): array
    {
        [$mainId, , $owner] = $this->context($query, $body);
        if (!$owner) throw new HttpException(403, 'Only an owner can view all customer detail update requests');
        return (new CustomerRequestRepository($this->db))->listAll($mainId);
    }

    public function createRequest(array $params, array $query, array $body): array
    {
        [$mainId, $userId] = $this->context($query, $body);
        if (!is_array($body['payload'] ?? null)) throw new HttpException(422, 'Request payload is required');
        $contactId = rawurldecode($params['contactId']);
        $kind = (string) ($body['kind'] ?? '');
        $requests = new CustomerRequestRepository($this->db);
        $result = $requests->create($mainId, $contactId, $userId, $kind, $body['payload']);

        if ($kind === 'customer_update') {
            $customer = $requests->customer($mainId, $contactId);
            $agent = $this->auth->findUserById($userId) ?: [];
            $agentName = trim((string) ($agent['lfname'] ?? '') . ' ' . (string) ($agent['llname'] ?? '')) ?: 'A sales agent';
            $customerName = trim((string) ($customer['company'] ?? '')) ?: 'a customer';
            (new NotificationsRepository($this->db))->create([
                'recipient_id' => (string) $mainId,
                'title' => 'Customer Detail Update Request',
                'message' => sprintf('%s submitted a customer detail update request for %s.', $agentName, $customerName),
                'type' => 'info',
                'category' => 'notification',
                'main_id' => (string) $mainId,
                'metadata' => [
                    'entity_type' => 'customer_detail_update_request',
                    'entity_id' => (string) $result['id'],
                    'contact_id' => $contactId,
                    'action' => 'review',
                    'status' => 'pending',
                    'action_url' => 'sales-database-customer-database',
                    'refno' => 'customer-detail-update-request:' . (string) $result['id'],
                    'idempotency_key' => 'customer-detail-update-request:' . (string) $result['id'] . ':' . $mainId,
                    'category' => 'notification',
                ],
            ]);
        }

        return $result;
    }

    public function reviewRequest(array $params, array $query, array $body): array
    {
        [$mainId, $userId, $owner] = $this->context($query, $body);
        if (!$owner) throw new HttpException(403, 'Only an owner can review customer requests');
        return (new CustomerRequestRepository($this->db))->review($mainId, rawurldecode($params['contactId']), $params['requestId'], $userId, (string) ($body['decision'] ?? ''), trim((string) ($body['note'] ?? '')));
    }
    public function recycleBin(array $params, array $query, array $body): array
    {
        [$mainId, , $owner] = $this->context($query, $body);
        if (!$owner) throw new HttpException(403, 'Only an owner can access recovery records');
        return (new \App\Repositories\LocalRecycleBinRepository($this->db))->list($mainId);
    }

    public function recycleAction(array $params, array $query, array $body): array
    {
        [$mainId, $userId, $owner] = $this->context($query, $body);
        if (!$owner) throw new HttpException(403, 'Only an owner can manage recovery records');
        if (!in_array($body['action'] ?? '', ['restore','discard'], true)) throw new HttpException(422, 'Invalid recovery action');
        return (new \App\Repositories\LocalRecycleBinRepository($this->db))->act($mainId, $userId, $params['id'], $body['action'] === 'restore');
    }

    public function logActivity(array $params, array $query, array $body): array
    {
        [$mainId, $userId] = $this->context($query, $body, false);
        $page = trim((string) ($body['entity_type'] ?? ''));
        $action = trim((string) ($body['action'] ?? ''));
        $ref = trim((string) ($body['entity_id'] ?? ''));
        if ($page === '' || strlen($page) > 100 || !in_array($action, ['CREATE','UPDATE','DELETE','RESTORE','STATUS_CHANGE','LOGIN','LOGOUT','SIGNUP'], true) || strlen($ref) > 128) throw new HttpException(422, 'Invalid activity log');
        $this->db->pdo()->prepare('INSERT INTO tblaudit_trail (lmain_id,luser_id,lpage,laction,lrefno,ldatetime) VALUES (?,?,?,?,?,NOW())')->execute([$mainId, $userId, 'Client: ' . $page, $action, $ref]);
        return ['saved' => true];
    }

}
