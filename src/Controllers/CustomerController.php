<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CustomerRepository;
use App\Support\Exceptions\HttpException;

final class CustomerController
{
    public function __construct(private readonly CustomerRepository $repo)
    {
    }

    public function show(array $params, array $query = [], array $body = []): array
    {
        $sessionId = $params['sessionId'] ?? '';
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        $customer = $this->repo->findCustomerBySession($sessionId);
        if ($customer === null) {
            throw new HttpException(404, 'Customer not found');
        }

        return $customer;
    }

    public function purchaseHistory(array $params, array $query = [], array $body = []): array
    {
        $sessionId = $params['sessionId'] ?? '';
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        $dateFrom = $query['date_from'] ?? null;
        $dateTo = $query['date_to'] ?? null;

        return [
            'customer_session' => $sessionId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'items' => $this->repo->getPurchaseHistory($sessionId, $dateFrom, $dateTo),
        ];
    }

    public function inquiryHistory(array $params, array $query = [], array $body = []): array
    {
        $sessionId = $params['sessionId'] ?? '';
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        $dateFrom = $query['date_from'] ?? null;
        $dateTo = $query['date_to'] ?? null;

        return [
            'customer_session' => $sessionId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'items' => $this->repo->getInquiryHistory($sessionId, $dateFrom, $dateTo),
        ];
    }

    public function ledger(array $params, array $query = [], array $body = []): array
    {
        $sessionId = $params['sessionId'] ?? '';
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        $reportType = (string) ($query['report_type'] ?? 'detailed');
        $dateType = (string) ($query['date_type'] ?? 'all');
        $dateFrom = isset($query['date_from']) ? (string) $query['date_from'] : null;
        $dateTo = isset($query['date_to']) ? (string) $query['date_to'] : null;

        return $this->repo->getCustomerLedger($sessionId, $reportType, $dateType, $dateFrom, $dateTo);
    }
}
