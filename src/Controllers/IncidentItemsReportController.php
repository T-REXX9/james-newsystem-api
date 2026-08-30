<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\IncidentItemsReportRepository;
use App\Support\Exceptions\HttpException;

final class IncidentItemsReportController
{
    public function __construct(private readonly IncidentItemsReportRepository $repo)
    {
    }

    public function report(array $params = [], array $query = [], array $body = []): array
    {
        $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
        $claimMainId = (int) ($claims['main_userid'] ?? 0);
        $requestedMainId = (int) ($query['main_id'] ?? 0);
        $mainId = $requestedMainId > 0 ? $requestedMainId : $claimMainId;
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }
        if ($claimMainId > 0 && $mainId !== $claimMainId) {
            throw new HttpException(403, 'main_id does not belong to the authenticated account');
        }

        $matchSource = strtolower(trim((string) ($query['match_source'] ?? 'all')));
        if (!in_array($matchSource, ['all', 'manual', 'related_transaction', 'description_match', 'imported'], true)) {
            throw new HttpException(422, 'match_source must be one of: all, manual, related_transaction, description_match, imported');
        }

        $filters = [
            'search' => trim((string) ($query['search'] ?? '')),
            'supplier' => trim((string) ($query['supplier'] ?? '')),
            'match_source' => $matchSource,
            'date_from' => trim((string) ($query['date_from'] ?? '')),
            'date_to' => trim((string) ($query['date_to'] ?? '')),
            'min_count' => max(1, (int) ($query['min_count'] ?? 1)),
        ];

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(300, (int) ($query['per_page'] ?? 100)));

        return $this->repo->report($mainId, $filters, $page, $perPage);
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
        $claimMainId = (int) ($claims['main_userid'] ?? 0);
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }
        if ($claimMainId > 0 && $mainId !== $claimMainId) {
            throw new HttpException(403, 'main_id does not belong to the authenticated account');
        }

        $incidentReportId = trim((string) ($body['incident_report_id'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $issueType = strtolower(trim((string) ($body['issue_type'] ?? 'other')));
        if ($incidentReportId === '' || $description === '') {
            throw new HttpException(422, 'incident_report_id and description are required');
        }
        if (!in_array($issueType, ['product_quality', 'service_quality', 'delivery', 'lbc_rto', 'other'], true)) {
            throw new HttpException(422, 'issue_type must be one of: product_quality, service_quality, delivery, lbc_rto, other');
        }
        if (strlen($incidentReportId) > 64) {
            throw new HttpException(422, 'incident_report_id is too long');
        }

        $quantity = $body['quantity'] ?? null;
        if ($quantity !== null && (!is_numeric($quantity) || (float) $quantity <= 0)) {
            throw new HttpException(422, 'quantity must be greater than zero');
        }

        $userId = (int) ($claims['sub'] ?? 0);
        return $this->repo->create($mainId, $userId > 0 ? $userId : null, $body);
    }
}
