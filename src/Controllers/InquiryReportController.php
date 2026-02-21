<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\InquiryReportRepository;
use App\Support\Exceptions\HttpException;

final class InquiryReportController
{
    public function __construct(private readonly InquiryReportRepository $repo)
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
        $limit = min($limit, 2000);

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
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $mode = (string) ($query['mode'] ?? 'summary');
        $dateType = (string) ($query['date_type'] ?? 'month');
        $dateFrom = isset($query['date_from']) ? (string) $query['date_from'] : null;
        $dateTo = isset($query['date_to']) ? (string) $query['date_to'] : null;
        $customerId = isset($query['customer_id']) ? trim((string) $query['customer_id']) : null;
        $limit = (int) ($query['limit'] ?? 500);
        if ($limit <= 0) {
            $limit = 500;
        }

        return $this->repo->getInquiryReport(
            $mainId,
            $mode,
            $dateType,
            $dateFrom,
            $dateTo,
            $customerId,
            min($limit, 5000)
        );
    }
}
