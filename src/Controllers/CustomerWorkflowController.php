<?php

declare(strict_types=1);
namespace App\Controllers;

use App\Database;
use App\Repositories\AuthRepository;
use App\Repositories\CustomerRequestRepository;
use App\Repositories\NotificationsRepository;
use App\Repositories\RolePermissionRepository;
use App\Repositories\SalesInquiryRepository;
use App\Repositories\SalesReturnRepository;
use App\Support\ActionPermissionPolicy;
use App\Support\Exceptions\HttpException;

final class CustomerWorkflowController
{
    public function __construct(private readonly Database $db, private readonly AuthRepository $auth) {}

    /**
     * Notifications are a secondary effect of a customer workflow.  The request
     * itself has already been durably saved before this is called, so a failure
     * to persist a notification must not make the caller believe it failed.
     */
    private function notifyBestEffort(array $notification): void
    {
        try {
            (new NotificationsRepository($this->db))->create($notification);
        } catch (\Throwable) {
            // Notification creation is idempotent, but must never undo or mask
            // a completed customer-request submission or decision.
        }
    }

    /**
     * @return array{0: int, 1: int, 2: bool} main id, user id, and whether the
     *         viewer reaches every record instead of only their own
     */
    private function context(
        array $query,
        array $body,
        bool $customerAccess = true,
        string $page = 'Customer Data'
    ): array
    {
        $claims = $body['__auth_claims'] ?? [];
        $userId = (int) ($claims['sub'] ?? 0);
        $mainId = (int) ($claims['main_userid'] ?? 0);
        $account = $this->auth->findUserById($userId);
        if (!$account || $mainId <= 0) throw new HttpException(401, 'Invalid authenticated account');
        if ($this->auth->resolveMainUserId($account) !== $mainId || (int) ($query['main_id'] ?? $mainId) !== $mainId || (int) ($body['main_id'] ?? $mainId) !== $mainId) throw new HttpException(403, 'Invalid account scope');
        $isMasterUser = (string) $account['ltype'] === '1';
        // Reach across other people's records is the "See all records" Page
        // Action Permission, set on System Access, not a role name kept here.
        $permissions = (new RolePermissionRepository($this->db))
            ->getActionPermissionsForAccount($mainId, $userId, (int) ($account['ltype'] ?? 0));
        $seesAllRecords = ActionPermissionPolicy::allows($permissions, 'view_all_records', $isMasterUser, $page);
        $rights = $this->auth->getDerivedAccessRights($account);
        if ($customerAccess && !$isMasterUser && !array_intersect($rights, ['*','home','sales-transaction-daily-call-monitoring','sales-database-customer-database','maintenance-customer-customer-data'])) throw new HttpException(403, 'Customer workflow access required');
        return [$mainId, $userId, $seesAllRecords];
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
        [$mainId, $userId, $seesAllRecords] = $this->context($query, $body);
        return (new CustomerRequestRepository($this->db))->list($mainId, rawurldecode($params['contactId']), $seesAllRecords ? null : $userId);
    }

    public function allRequests(array $params, array $query, array $body): array
    {
        [$mainId, , $seesAllRecords] = $this->context($query, $body);
        if (!$seesAllRecords) throw new HttpException(403, 'You do not have permission to see all customer detail update requests');
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
            $isBlacklistRequest = strtolower(trim((string) ($body['payload']['debt_type'] ?? ''))) === 'bad';
            $this->notifyBestEffort([
                'recipient_id' => (string) $mainId,
                'title' => $isBlacklistRequest ? 'Reject / Blacklist Request' : 'Customer Detail Update Request',
                'message' => $isBlacklistRequest ? sprintf('%s requested rejection or blacklisting for %s.', $agentName, $customerName) : sprintf('%s submitted a customer detail update request for %s.', $agentName, $customerName),
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
        [$mainId, $userId] = $this->context($query, $body);
        $contactId = rawurldecode($params['contactId']);
        $requestId = (string) $params['requestId'];
        $requests = new CustomerRequestRepository($this->db);
        $request = $requests->request($mainId, $contactId, $requestId);
        if ((int) ($request['submitted_by'] ?? 0) === $userId) {
            throw new HttpException(403, 'You cannot review your own customer request');
        }
        $decision = (string) ($body['decision'] ?? '');
        $result = $requests->review($mainId, $contactId, $requestId, $userId, $decision, trim((string) ($body['note'] ?? '')));
        if ($request['kind'] === 'customer_update' && (int) ($request['submitted_by'] ?? 0) > 0) {
            $customer = $requests->customer($mainId, $contactId);
            $customerName = trim((string) ($customer['company'] ?? '')) ?: 'a customer';
            $isBlacklistRequest = strtolower(trim((string) (($request['payload'] ?? [])['debt_type'] ?? ''))) === 'bad';
            $this->notifyBestEffort([
                'recipient_id' => (string) $request['submitted_by'],
                'title' => $isBlacklistRequest ? 'Reject / Blacklist Request ' . ucfirst($decision) : 'Customer Request ' . ucfirst($decision),
                'message' => sprintf('Your request for %s was %s.', $customerName, $decision),
                'type' => $decision === 'approved' ? 'success' : 'info',
                'category' => 'notification',
                'main_id' => (string) $mainId,
                'metadata' => ['entity_type' => 'customer_request_decision', 'entity_id' => $requestId, 'contact_id' => $contactId, 'conversation_type' => 'agent_sales_report', 'action_url' => 'sales-transaction-daily-call-monitoring', 'refno' => 'customer-request-decision:' . $requestId . ':' . $decision, 'idempotency_key' => 'customer-request-decision:' . $requestId . ':' . $decision . ':' . $request['submitted_by'], 'category' => 'notification'],
            ]);
        }
        return $result;
    }
    public function recycleBin(array $params, array $query, array $body): array
    {
        [$mainId, , $seesAllRecords] = $this->context($query, $body, true, 'Recycle Bin');
        if (!$seesAllRecords) throw new HttpException(403, 'You do not have permission to see deleted records');
        return (new \App\Repositories\LocalRecycleBinRepository($this->db))->list($mainId);
    }

    public function restoreRecycleBinItem(array $params, array $query, array $body): array
    {
        [$mainId, , $seesAllRecords] = $this->context($query, $body, true, 'Recycle Bin');
        if (!$seesAllRecords) throw new HttpException(403, 'You do not have permission to restore deleted records');
        $type = rawurldecode((string) ($params['type'] ?? ''));
        $itemId = rawurldecode((string) ($params['itemId'] ?? ''));
        $restored = (new \App\Repositories\LocalRecycleBinRepository($this->db))->restore($mainId, $type, $itemId);
        if (!$restored) throw new HttpException(404, 'Deleted record was not found or is already restored');
        return ['restored' => true, 'item_type' => $type, 'item_id' => $itemId];
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
