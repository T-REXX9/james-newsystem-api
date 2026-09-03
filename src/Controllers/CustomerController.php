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

        $priceGroup = $customer['price_group'] ?? '';
        $customerSince = $customer['customer_since'] ?? '';
        $eligible = $this->repo->resolvePlatinumEligibility($priceGroup, $customerSince);
        $normalized = $this->repo->getNormalizedPriceGroup($priceGroup);
        $customer['pricing_tier'] = $eligible ? 'platinum' : $normalized;

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

        $customer = $this->repo->findCustomerBySession($sessionId);
        if ($customer === null) {
            throw new HttpException(404, 'Customer not found');
        }

        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        $monthRows = $this->repo->getPurchaseHistory($sessionId, $monthStart, $monthEnd);
        $postedMonthRows = array_filter(
            $monthRows,
            static fn (array $row): bool => strtolower(trim((string) ($row['source_status'] ?? ''))) === 'posted'
        );
        $currentMonthSales = array_reduce(
            $postedMonthRows,
            static fn (float $sum, array $row): float => $sum
                + (((float) ($row['lqty'] ?? 0) - (float) ($row['return_qty'] ?? 0)) * (float) ($row['lprice'] ?? 0)),
            0.0
        );

        $priceGroup = (string) ($customer['price_group'] ?? '');
        $customerSince = (string) ($customer['customer_since'] ?? '');
        $vipStatus = $this->repo->resolvePlatinumEligibility($priceGroup, $customerSince)
            ? 'PLATINUM'
            : strtoupper($this->repo->getNormalizedPriceGroup($priceGroup));

        return [
            'customer_session' => $sessionId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'generated_at' => date('Y-m-d H:i:s'),
            'customer' => [
                'company' => (string) ($customer['lcompany'] ?? ''),
                'old_name' => (string) ($customer['old_name'] ?? ''),
                'customer_since' => (string) ($customer['customer_since'] ?? ''),
                'vip_status' => $vipStatus,
                'price_code' => (string) ($customer['price_group'] ?? ''),
                'current_month_sales' => $currentMonthSales,
                'outstanding_balance' => (float) ($customer['latest_balance'] ?? 0),
                'terms' => (string) ($customer['latest_terms'] ?? $customer['lterms'] ?? ''),
                'credit_limit' => (float) ($customer['lcredit'] ?? 0),
                'agent_name' => (string) ($customer['agent_name'] ?? ''),
            ],
            'items' => $this->repo->getPurchaseHistory($sessionId, $dateFrom, $dateTo),
        ];
    }

    public function purchasedItems(array $params, array $query = [], array $body = []): array
    {
        $sessionId = $params['sessionId'] ?? '';
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        $customer = $this->repo->findCustomerBySession($sessionId);
        if ($customer === null) {
            throw new HttpException(404, 'Customer not found');
        }

        return [
            'customer_session' => $sessionId,
            'items' => $this->repo->searchPurchasedItems(
                $sessionId,
                trim((string) ($query['search'] ?? '')),
                max(1, min(200, (int) ($query['limit'] ?? 50)))
            ),
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
