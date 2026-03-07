<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\DailyCallMonitoringRepository;
use App\Support\Exceptions\HttpException;

final class DailyCallMonitoringController
{
    public function __construct(private readonly DailyCallMonitoringRepository $repo)
    {
    }

    public function excelRows(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $status = (string) ($query['status'] ?? 'all');
        $search = (string) ($query['search'] ?? '');
        $viewerUserId = isset($query['viewer_user_id']) ? (int) $query['viewer_user_id'] : null;

        return $this->repo->getExcelRows($mainId, $status, $search, $viewerUserId);
    }

    public function ownerSnapshot(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return $this->repo->getOwnerSnapshot($mainId);
    }

    public function agentSnapshot(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $viewerUserId = (int) ($query['viewer_user_id'] ?? 0);
        if ($viewerUserId <= 0) {
            throw new HttpException(422, 'viewer_user_id is required');
        }

        return $this->repo->getAgentSnapshot($mainId, $viewerUserId);
    }

    public function customerPurchaseHistory(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $contactId = trim((string) ($params['contactId'] ?? ''));
        if ($contactId === '') {
            throw new HttpException(422, 'contactId is required');
        }

        return $this->repo->getCustomerPurchaseHistory($mainId, $contactId);
    }

    public function customerSalesReports(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $contactId = trim((string) ($params['contactId'] ?? ''));
        if ($contactId === '') {
            throw new HttpException(422, 'contactId is required');
        }

        return $this->repo->getCustomerSalesReports($mainId, $contactId);
    }

    public function customerIncidentReports(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $contactId = trim((string) ($params['contactId'] ?? ''));
        if ($contactId === '') {
            throw new HttpException(422, 'contactId is required');
        }

        return $this->repo->getCustomerIncidentReports($mainId, $contactId);
    }

    /**
     * Get call logs for a contact
     * Replaces Supabase call_logs queries
     */
    public function callLogs(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $contactId = trim((string) ($params['contactId'] ?? ''));
        if ($contactId === '') {
            throw new HttpException(422, 'contactId is required');
        }

        $fromDate = isset($query['from_date']) ? trim((string) $query['from_date']) : null;
        $toDate = isset($query['to_date']) ? trim((string) $query['to_date']) : null;

        return $this->repo->getCallLogs($mainId, $contactId, $fromDate, $toDate);
    }

    /**
     * Get return records (LBC RTO) for a contact
     * Replaces Supabase lbc_rto_records and sales_returns queries
     */
    public function returnRecords(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $contactId = trim((string) ($params['contactId'] ?? ''));
        if ($contactId === '') {
            throw new HttpException(422, 'contactId is required');
        }

        return $this->repo->getReturnRecords($mainId, $contactId);
    }

    public function createCallLog(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $contactId = trim((string) ($body['contact_id'] ?? ''));
        if ($contactId === '') {
            throw new HttpException(422, 'contact_id is required');
        }

        $notes = trim((string) ($body['notes'] ?? ''));
        if ($notes === '') {
            throw new HttpException(422, 'notes is required');
        }

        return $this->repo->createCallLog($mainId, [
            'contact_id' => $contactId,
            'user_id' => $body['user_id'] ?? null,
            'agent_name' => $body['agent_name'] ?? null,
            'channel' => $body['channel'] ?? 'call',
            'outcome' => $body['outcome'] ?? 'logged',
            'notes' => $notes,
            'occurred_at' => $body['occurred_at'] ?? null,
        ]);
    }
}
