<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\LoyaltyDiscountRepository;
use App\Support\Exceptions\HttpException;

final class LoyaltyDiscountController
{
    public function __construct(private readonly LoyaltyDiscountRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $includeInactive = filter_var($query['include_inactive'] ?? '1', FILTER_VALIDATE_BOOL);

        return $this->repo->listRules($includeInactive);
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new HttpException(422, 'name is required');
        }

        $discountType = trim((string) ($body['discount_type'] ?? 'purchase_threshold'));
        if (!in_array($discountType, ['purchase_threshold', 'customer_specific', 'date_range'], true)) {
            throw new HttpException(422, 'discount_type must be purchase_threshold, customer_specific, or date_range');
        }

        $minPurchaseAmount = (float) ($body['min_purchase_amount'] ?? 0);
        if ($discountType === 'purchase_threshold' && $minPurchaseAmount <= 0) {
            throw new HttpException(422, 'min_purchase_amount must be greater than 0 for purchase threshold rules');
        }

        $discountPercentage = (float) ($body['discount_percentage'] ?? 0);
        if ($discountPercentage <= 0 || $discountPercentage > 100) {
            throw new HttpException(422, 'discount_percentage must be between 0 and 100');
        }

        $evaluationPeriod = trim((string) ($body['evaluation_period'] ?? 'calendar_month'));
        if (!in_array($evaluationPeriod, ['calendar_month', 'rolling_30_days'], true)) {
            throw new HttpException(422, 'evaluation_period must be calendar_month or rolling_30_days');
        }

        if ($discountType === 'customer_specific') {
            $targetIds = $body['target_customer_ids'] ?? [];
            if (!is_array($targetIds) || count($targetIds) === 0) {
                throw new HttpException(422, 'At least one target customer is required for customer-specific rules');
            }
        }

        if ($discountType === 'date_range') {
            $startDate = trim((string) ($body['start_date'] ?? ''));
            $endDate = trim((string) ($body['end_date'] ?? ''));
            if ($startDate === '' || $endDate === '') {
                throw new HttpException(422, 'start_date and end_date are required for date range rules');
            }
            if ($endDate < $startDate) {
                throw new HttpException(422, 'end_date must be on or after start_date');
            }
        }

        return $this->repo->createRule([
            'name' => $name,
            'description' => trim((string) ($body['description'] ?? '')),
            'discount_type' => $discountType,
            'min_purchase_amount' => $minPurchaseAmount,
            'discount_percentage' => $discountPercentage,
            'evaluation_period' => $evaluationPeriod,
            'priority' => (int) ($body['priority'] ?? 0),
            'target_customer_ids' => $body['target_customer_ids'] ?? [],
            'target_customer_names' => $body['target_customer_names'] ?? [],
            'start_date' => ($body['start_date'] ?? null) ?: null,
            'end_date' => ($body['end_date'] ?? null) ?: null,
            'created_by' => trim((string) ($body['created_by'] ?? '')),
        ]);
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $id = trim((string) ($params['ruleId'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'ruleId is required');
        }

        $updates = [];

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                throw new HttpException(422, 'name cannot be empty');
            }
            $updates['name'] = $name;
        }

        if (array_key_exists('description', $body)) {
            $updates['description'] = trim((string) $body['description']);
        }

        if (array_key_exists('min_purchase_amount', $body)) {
            $val = (float) $body['min_purchase_amount'];
            if ($val < 0) {
                throw new HttpException(422, 'min_purchase_amount cannot be negative');
            }
            $updates['min_purchase_amount'] = $val;
        }

        if (array_key_exists('discount_percentage', $body)) {
            $val = (float) $body['discount_percentage'];
            if ($val <= 0 || $val > 100) {
                throw new HttpException(422, 'discount_percentage must be between 0 and 100');
            }
            $updates['discount_percentage'] = $val;
        }

        if (array_key_exists('evaluation_period', $body)) {
            $val = trim((string) $body['evaluation_period']);
            if (!in_array($val, ['calendar_month', 'rolling_30_days'], true)) {
                throw new HttpException(422, 'evaluation_period must be calendar_month or rolling_30_days');
            }
            $updates['evaluation_period'] = $val;
        }

        if (array_key_exists('priority', $body)) {
            $updates['priority'] = (int) $body['priority'];
        }

        if (array_key_exists('is_active', $body)) {
            $updates['is_active'] = filter_var($body['is_active'], FILTER_VALIDATE_BOOL);
        }

        if (array_key_exists('discount_type', $body)) {
            $val = trim((string) $body['discount_type']);
            if (!in_array($val, ['purchase_threshold', 'customer_specific', 'date_range'], true)) {
                throw new HttpException(422, 'discount_type must be purchase_threshold, customer_specific, or date_range');
            }
            $updates['discount_type'] = $val;
        }

        if (array_key_exists('target_customer_ids', $body)) {
            $updates['target_customer_ids'] = is_array($body['target_customer_ids']) ? array_values($body['target_customer_ids']) : [];
        }

        if (array_key_exists('target_customer_names', $body)) {
            $updates['target_customer_names'] = is_array($body['target_customer_names']) ? array_values($body['target_customer_names']) : [];
        }

        if (array_key_exists('start_date', $body)) {
            $updates['start_date'] = ($body['start_date'] ?? null) ?: null;
        }

        if (array_key_exists('end_date', $body)) {
            $updates['end_date'] = ($body['end_date'] ?? null) ?: null;
        }

        if ($updates === []) {
            throw new HttpException(422, 'No fields to update');
        }

        $existing = $this->repo->getRuleById($id);
        if ($existing === null) {
            throw new HttpException(404, 'Loyalty discount rule not found');
        }

        $candidate = array_merge($existing, $updates);
        $discountType = trim((string) ($candidate['discount_type'] ?? 'purchase_threshold'));

        if ($discountType === 'purchase_threshold' && (float) ($candidate['min_purchase_amount'] ?? 0) <= 0) {
            throw new HttpException(422, 'min_purchase_amount must be greater than 0 for purchase threshold rules');
        }

        if ($discountType === 'customer_specific') {
            $targetIds = $candidate['target_customer_ids'] ?? [];
            if (!is_array($targetIds) || count($targetIds) === 0) {
                throw new HttpException(422, 'At least one target customer is required for customer-specific rules');
            }
        }

        if ($discountType === 'date_range') {
            $startDate = trim((string) ($candidate['start_date'] ?? ''));
            $endDate = trim((string) ($candidate['end_date'] ?? ''));
            if ($startDate === '' || $endDate === '') {
                throw new HttpException(422, 'start_date and end_date are required for date range rules');
            }
            if ($endDate < $startDate) {
                throw new HttpException(422, 'end_date must be on or after start_date');
            }
        }

        $updated = $this->repo->updateRule($id, $updates);

        return $updated;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $id = trim((string) ($params['ruleId'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'ruleId is required');
        }

        $ok = $this->repo->deleteRule($id);
        if (!$ok) {
            throw new HttpException(404, 'Loyalty discount rule not found');
        }

        return [
            'deleted' => true,
            'rule_id' => $id,
        ];
    }

    public function updateStatus(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $id = trim((string) ($params['ruleId'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'ruleId is required');
        }

        if (!array_key_exists('is_active', $body)) {
            throw new HttpException(422, 'is_active is required');
        }

        $isActive = filter_var($body['is_active'], FILTER_VALIDATE_BOOL);
        $updated = $this->repo->updateStatus($id, $isActive);
        if ($updated === null) {
            throw new HttpException(404, 'Loyalty discount rule not found');
        }

        return $updated;
    }

    public function stats(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return $this->repo->getStats($mainId);
    }

    public function customerActiveDiscount(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $customerId = trim((string) ($params['customerId'] ?? ''));
        if ($customerId === '') {
            throw new HttpException(422, 'customerId is required');
        }

        return $this->repo->getCustomerActiveDiscount($mainId, $customerId);
    }
}
