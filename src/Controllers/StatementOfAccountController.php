<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\StatementOfAccountRepository;
use App\Support\Exceptions\HttpException;

final class StatementOfAccountController
{
    public function __construct(private readonly StatementOfAccountRepository $repo)
    {
    }

    public function customers(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $limit = (int) ($query['limit'] ?? 100);
        if ($limit <= 0) {
            $limit = 100;
        }
        $limit = min($limit, 500);

        return [
            'items' => $this->repo->listCustomers(
                $mainId,
                (string) ($query['search'] ?? ''),
                $limit
            ),
        ];
    }

    public function report(array $params = [], array $query = [], array $body = []): array
    {
        $customerId = trim((string) ($query['customer_id'] ?? ''));
        if ($customerId === '') {
            throw new HttpException(422, 'customer_id is required');
        }

        $reportType = (string) ($query['report_type'] ?? 'detailed');
        $dateType = (string) ($query['date_type'] ?? 'all');
        $dateFrom = isset($query['date_from']) ? (string) $query['date_from'] : null;
        $dateTo = isset($query['date_to']) ? (string) $query['date_to'] : null;

        return $this->repo->getStatementOfAccount($customerId, $reportType, $dateType, $dateFrom, $dateTo);
    }
}
