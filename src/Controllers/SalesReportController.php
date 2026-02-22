<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SalesReportRepository;
use App\Support\Exceptions\HttpException;

final class SalesReportController
{
    public function __construct(private readonly SalesReportRepository $repo)
    {
    }

    public function customers(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $limit = (int) ($query['limit'] ?? 300);
        if ($limit <= 0) {
            $limit = 300;
        }

        return [
            'items' => $this->repo->listCustomers(
                $mainId,
                (string) ($query['search'] ?? ''),
                min($limit, 2000)
            ),
        ];
    }

    public function report(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $dateType = (string) ($query['date_type'] ?? 'all');
        $dateFrom = isset($query['date_from']) ? (string) $query['date_from'] : null;
        $dateTo = isset($query['date_to']) ? (string) $query['date_to'] : null;
        $customerId = isset($query['customer_id']) ? (string) $query['customer_id'] : null;
        $limit = (int) ($query['limit'] ?? 1200);
        if ($limit <= 0) {
            $limit = 1200;
        }

        return $this->repo->getSalesReport(
            $mainId,
            $dateType,
            $dateFrom,
            $dateTo,
            $customerId,
            min($limit, 5000)
        );
    }

    public function transactionItems(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $transactionRefno = trim((string) ($params['transactionRefno'] ?? ''));
        if ($transactionRefno === '') {
            throw new HttpException(422, 'transactionRefno is required');
        }

        $type = strtolower(trim((string) ($query['type'] ?? 'invoice')));
        if ($type !== 'invoice' && $type !== 'dr') {
            throw new HttpException(422, 'type must be invoice or dr');
        }

        return [
            'items' => $this->repo->getTransactionItems($mainId, $transactionRefno, $type),
        ];
    }
}
