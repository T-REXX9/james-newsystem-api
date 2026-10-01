<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CustomerRepository;
use App\Support\CustomerMergeRedirectResolver;
use App\Support\Exceptions\HttpException;

final class CustomerController
{
    public function __construct(
        private readonly CustomerRepository $repo,
        private readonly ?CustomerMergeRedirectResolver $redirects = null
    )
    {
    }

    public function show(array $params, array $query = [], array $body = []): array
    {
        [$sessionId, $redirect] = $this->resolveSession($params, $query, $body);
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        $customer = $this->repo->findCustomerBySession($sessionId, $this->mainId($query, $body));
        if ($customer === null) {
            throw new HttpException(404, 'Customer not found');
        }

        $priceGroup = $customer['price_group'] ?? '';
        $customerSince = $customer['customer_since'] ?? '';
        $normalized = $this->repo->getNormalizedPriceGroup($priceGroup);
        $customer['pricing_tier'] = $normalized;
        $customer['price_code'] = (string) ($customer['price_code'] ?? $customer['price_group'] ?? '');
        $customer['discount_code'] = $this->repo->normalizeDiscountCode(
            (string) ($customer['discount_code'] ?? ''),
            (string) $priceGroup,
            (string) $customerSince
        );

        if ($redirect['redirected']) {
            $customer['requested_session_id'] = (string) ($params['sessionId'] ?? '');
            $customer['customer_session_redirected'] = true;
            $customer['merge_redirect'] = $redirect['redirect'];
        }
        return $customer;
    }

    public function purchaseHistory(array $params, array $query = [], array $body = []): array
    {
        [$sessionId, $redirect] = $this->resolveSession($params, $query, $body);
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        $dateFrom = $query['date_from'] ?? null;
        $dateTo = $query['date_to'] ?? null;
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($query['per_page'] ?? 50)));

        $customer = $this->repo->findCustomerBySession($sessionId, $this->mainId($query, $body));
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

        $mainId = (int) ($customer['lmain_id'] ?? 0);
        $salesTotals = $this->repo->getCustomerSalesTotals($sessionId);
        $vipStatus = strtoupper(
            $this->repo->resolveVipStandingLevel($mainId, (float) ($salesTotals['last_month_sales'] ?? 0))
        );

        $historyRows = $this->repo->getPurchaseHistory(
            $sessionId,
            $dateFrom,
            $dateTo,
            $perPage + 1,
            ($page - 1) * $perPage
        );
        $hasMore = count($historyRows) > $perPage;
        if ($hasMore) {
            $historyRows = array_slice($historyRows, 0, $perPage);
        }

        return [
            'customer_session' => $sessionId,
            'requested_customer_session' => (string) ($params['sessionId'] ?? ''),
            'customer_session_redirected' => $redirect['redirected'],
            'merge_redirect' => $redirect['redirect'],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'generated_at' => date('Y-m-d H:i:s'),
            'customer' => [
                'company' => (string) ($customer['lcompany'] ?? ''),
                'old_name' => (string) ($customer['old_name'] ?? ''),
                'customer_since' => (string) ($customer['customer_since'] ?? ''),
                'vip_status' => $vipStatus,
                'price_code' => (string) ($customer['price_group'] ?? ''),
                'discount_code' => $this->repo->normalizeDiscountCode(
                    (string) ($customer['discount_code'] ?? ''),
                    (string) ($customer['price_group'] ?? ''),
                    (string) ($customer['customer_since'] ?? '')
                ),
                'current_month_sales' => $currentMonthSales,
                'outstanding_balance' => (float) ($customer['latest_balance'] ?? 0),
                'terms' => (string) ($customer['latest_terms'] ?? $customer['lterms'] ?? ''),
                'credit_limit' => (float) ($customer['lcredit'] ?? 0),
                'agent_name' => (string) ($customer['agent_name'] ?? ''),
            ],
            'items' => $historyRows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => $hasMore,
            ],
        ];
    }

    public function purchasedItems(array $params, array $query = [], array $body = []): array
    {
        [$sessionId, $redirect] = $this->resolveSession($params, $query, $body);
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        $customer = $this->repo->findCustomerBySession($sessionId, $this->mainId($query, $body));
        if ($customer === null) {
            throw new HttpException(404, 'Customer not found');
        }

        return [
            'customer_session' => $sessionId,
            'requested_customer_session' => (string) ($params['sessionId'] ?? ''),
            'customer_session_redirected' => $redirect['redirected'],
            'merge_redirect' => $redirect['redirect'],
            'items' => $this->repo->searchPurchasedItems(
                $sessionId,
                trim((string) ($query['search'] ?? '')),
                max(1, min(200, (int) ($query['limit'] ?? 50)))
            ),
        ];
    }

    public function inquiryHistory(array $params, array $query = [], array $body = []): array
    {
        [$sessionId, $redirect] = $this->resolveSession($params, $query, $body);
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        $dateFrom = $query['date_from'] ?? null;
        $dateTo = $query['date_to'] ?? null;

        return [
            'customer_session' => $sessionId,
            'requested_customer_session' => (string) ($params['sessionId'] ?? ''),
            'customer_session_redirected' => $redirect['redirected'],
            'merge_redirect' => $redirect['redirect'],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'items' => $this->repo->getInquiryHistory($sessionId, $dateFrom, $dateTo),
        ];
    }

    public function ledger(array $params, array $query = [], array $body = []): array
    {
        [$sessionId, $redirect] = $this->resolveSession($params, $query, $body);
        if ($sessionId === '') {
            throw new HttpException(422, 'sessionId is required');
        }

        $reportType = (string) ($query['report_type'] ?? 'detailed');
        $dateType = (string) ($query['date_type'] ?? 'all');
        $dateFrom = isset($query['date_from']) ? (string) $query['date_from'] : null;
        $dateTo = isset($query['date_to']) ? (string) $query['date_to'] : null;

        $result = $this->repo->getCustomerLedger($sessionId, $reportType, $dateType, $dateFrom, $dateTo);
        $result['customer_session'] = $sessionId;
        $result['requested_customer_session'] = (string) ($params['sessionId'] ?? '');
        $result['customer_session_redirected'] = $redirect['redirected'];
        $result['merge_redirect'] = $redirect['redirect'];
        return $result;
    }

    /** @return array{0: string, 1: array{session_id: string, redirected: bool, redirect: array<string, mixed>|null}} */
    private function resolveSession(array $params, array $query, array $body): array
    {
        $requested = trim((string) ($params['sessionId'] ?? ''));
        if ($requested === '') {
            throw new HttpException(422, 'sessionId is required');
        }
        $mainId = $this->mainId($query, $body);
        $redirect = $this->redirects?->resolve($mainId, $requested) ?? [
            'session_id' => $requested,
            'redirected' => false,
            'redirect' => null,
        ];
        return [$redirect['session_id'], $redirect];
    }

    private function mainId(array $query, array $body): int
    {
        return (int) ($body['main_id'] ?? $query['main_id'] ?? 0);
    }
}
