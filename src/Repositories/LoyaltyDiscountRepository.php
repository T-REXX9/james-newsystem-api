<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class LoyaltyDiscountRepository
{
    private string $storePath;

    public function __construct(private readonly Database $db, ?string $storePath = null)
    {
        $this->storePath = $storePath ?? dirname(__DIR__, 2) . '/data/loyalty_discount_rules.json';
    }

    // ========================================================================
    // Rule persistence (JSON file)
    // ========================================================================

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRules(): array
    {
        if (!file_exists($this->storePath)) {
            return [];
        }

        $json = file_get_contents($this->storePath);
        if ($json === false || $json === '') {
            return [];
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function saveRules(array $rules): void
    {
        $dir = dirname($this->storePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode(array_values($rules), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode loyalty discount rules as JSON');
        }

        $tmp = $this->storePath . '.tmp';
        if (file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Failed to write loyalty discount rules');
        }
        rename($tmp, $this->storePath);
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    // ========================================================================
    // CRUD
    // ========================================================================

    /**
     * @return array{items: array<int, array<string, mixed>>}
     */
    public function listRules(bool $includeInactive = true): array
    {
        $rules = $this->loadRules();

        $visible = array_values(array_filter($rules, static function (array $r): bool {
            return empty($r['is_deleted']);
        }));

        if (!$includeInactive) {
            $visible = array_values(array_filter($visible, static function (array $r): bool {
                return !empty($r['is_active']);
            }));
        }

        usort($visible, static function (array $a, array $b): int {
            return ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0));
        });

        return ['items' => $visible];
    }

    public function getRuleById(string $id): ?array
    {
        $rules = $this->loadRules();
        foreach ($rules as $rule) {
            if (($rule['id'] ?? '') === $id && empty($rule['is_deleted'])) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createRule(array $data): array
    {
        $rules = $this->loadRules();
        $now = date('c');

        $discountType = (string) ($data['discount_type'] ?? 'purchase_threshold');
        if (!in_array($discountType, ['purchase_threshold', 'customer_specific', 'date_range'], true)) {
            $discountType = 'purchase_threshold';
        }

        $rule = [
            'id' => $this->generateId(),
            'name' => (string) ($data['name'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'discount_type' => $discountType,
            'min_purchase_amount' => (float) ($data['min_purchase_amount'] ?? 0),
            'discount_percentage' => (float) ($data['discount_percentage'] ?? 0),
            'evaluation_period' => (string) ($data['evaluation_period'] ?? 'calendar_month'),
            'priority' => (int) ($data['priority'] ?? 0),
            'target_customer_ids' => is_array($data['target_customer_ids'] ?? null) ? array_values($data['target_customer_ids']) : [],
            'target_customer_names' => is_array($data['target_customer_names'] ?? null) ? array_values($data['target_customer_names']) : [],
            'start_date' => ($data['start_date'] ?? null) !== null ? (string) $data['start_date'] : null,
            'end_date' => ($data['end_date'] ?? null) !== null ? (string) $data['end_date'] : null,
            'is_active' => true,
            'created_by' => (string) ($data['created_by'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
            'is_deleted' => false,
            'deleted_at' => null,
        ];

        $rules[] = $rule;
        $this->saveRules($rules);

        return $rule;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateRule(string $id, array $data): ?array
    {
        $rules = $this->loadRules();
        $found = false;

        $editable = ['name', 'description', 'discount_type', 'min_purchase_amount', 'discount_percentage', 'evaluation_period', 'priority', 'is_active', 'target_customer_ids', 'target_customer_names', 'start_date', 'end_date'];

        foreach ($rules as &$rule) {
            if (($rule['id'] ?? '') === $id && empty($rule['is_deleted'])) {
                foreach ($editable as $field) {
                    if (array_key_exists($field, $data)) {
                        $rule[$field] = $data[$field];
                    }
                }
                $rule['updated_at'] = date('c');
                $found = true;
                $updated = $rule;
                break;
            }
        }
        unset($rule);

        if (!$found) {
            return null;
        }

        $this->saveRules($rules);
        return $updated;
    }

    public function deleteRule(string $id): bool
    {
        $rules = $this->loadRules();
        $found = false;

        foreach ($rules as &$rule) {
            if (($rule['id'] ?? '') === $id && empty($rule['is_deleted'])) {
                $rule['is_deleted'] = true;
                $rule['deleted_at'] = date('c');
                $rule['is_active'] = false;
                $rule['updated_at'] = date('c');
                $found = true;
                break;
            }
        }
        unset($rule);

        if (!$found) {
            return false;
        }

        $this->saveRules($rules);
        return true;
    }

    public function updateStatus(string $id, bool $isActive): ?array
    {
        return $this->updateRule($id, ['is_active' => $isActive]);
    }

    // ========================================================================
    // Stats — derived from existing transaction data
    // ========================================================================

    /**
     * @return array<string, mixed>
     */
    public function getStats(int $mainId): array
    {
        $ruleResult = $this->listRules(false);
        $activeRules = $ruleResult['items'];
        $totalActiveRules = count($activeRules);

        if ($totalActiveRules === 0) {
            return [
                'total_active_rules' => 0,
                'clients_eligible_this_month' => 0,
                'total_discount_given_this_month' => 0,
                'top_qualifying_clients' => [],
                'stats_note' => 'No active rules configured.',
            ];
        }

        $customerTotals = $this->getCustomerMonthlyTotals($mainId, date('Y-m-01'), date('Y-m-t'));
        $customerTotalMap = [];
        foreach ($customerTotals as $ct) {
            $customerTotalMap[(string) $ct['customer_id']] = $ct;
        }

        $eligibleCustomers = [];

        foreach ($customerTotals as $ct) {
            $customerId = (string) $ct['customer_id'];
            $amount = (float) $ct['total_amount'];
            $bestRule = $this->findBestRuleForCustomer($activeRules, $customerId, $amount);
            if ($bestRule !== null) {
                $eligibleCustomers[$customerId] = [
                    'client_id' => $customerId,
                    'client_name' => (string) $ct['customer_name'],
                    'qualifying_amount' => $amount,
                    'discount_percentage' => (float) $bestRule['discount_percentage'],
                ];
            }
        }

        foreach ($activeRules as $rule) {
            if (($rule['discount_type'] ?? 'purchase_threshold') !== 'customer_specific') {
                continue;
            }

            $targetIds = is_array($rule['target_customer_ids'] ?? null) ? array_values($rule['target_customer_ids']) : [];
            $targetNames = is_array($rule['target_customer_names'] ?? null) ? array_values($rule['target_customer_names']) : [];

            foreach ($targetIds as $index => $customerId) {
                $customerId = (string) $customerId;
                if ($customerId === '') {
                    continue;
                }
                $derived = $customerTotalMap[$customerId] ?? null;
                $eligibleCustomers[$customerId] = [
                    'client_id' => $customerId,
                    'client_name' => (string) ($derived['customer_name'] ?? ($targetNames[$index] ?? $customerId)),
                    'qualifying_amount' => (float) ($derived['total_amount'] ?? 0),
                    'discount_percentage' => (float) ($rule['discount_percentage'] ?? 0),
                ];
            }
        }

        $topQualifiers = array_values($eligibleCustomers);
        usort($topQualifiers, static fn(array $a, array $b): int =>
            (($b['qualifying_amount'] <=> $a['qualifying_amount']) ?: ($b['discount_percentage'] <=> $a['discount_percentage']))
        );
        $topQualifiers = array_slice($topQualifiers, 0, 5);

        return [
            'total_active_rules' => $totalActiveRules,
            'clients_eligible_this_month' => count($eligibleCustomers),
            'total_discount_given_this_month' => 0,
            'top_qualifying_clients' => $topQualifiers,
            'stats_note' => 'Eligibility is computed from current month invoices. Discount usage history is not available without a dedicated log.',
        ];
    }

    /**
     * Find the best matching rule for a given spending amount (highest priority first).
     *
     * @param array<int, array<string, mixed>> $activeRules already sorted by priority desc
     * @return array<string, mixed>|null
     */
    private function findBestMatchingRule(array $activeRules, float $amount): ?array
    {
        foreach ($activeRules as $rule) {
            if ($amount >= (float) ($rule['min_purchase_amount'] ?? 0)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $activeRules
     * @return array<string, mixed>|null
     */
    private function findBestRuleForCustomer(array $activeRules, string $customerId, float $amount): ?array
    {
        foreach ($activeRules as $rule) {
            if ($this->ruleAppliesToCustomer($rule, $customerId, $amount)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function ruleAppliesToCustomer(array $rule, string $customerId, float $amount, ?string $date = null): bool
    {
        if (empty($rule['is_active']) || !empty($rule['is_deleted'])) {
            return false;
        }

        $discountType = (string) ($rule['discount_type'] ?? 'purchase_threshold');
        $effectiveDate = $date ?? date('Y-m-d');

        if ($discountType === 'date_range') {
            $startDate = trim((string) ($rule['start_date'] ?? ''));
            $endDate = trim((string) ($rule['end_date'] ?? ''));
            if ($startDate === '' || $endDate === '' || $effectiveDate < $startDate || $effectiveDate > $endDate) {
                return false;
            }

            return $amount >= (float) ($rule['min_purchase_amount'] ?? 0);
        }

        if ($discountType === 'customer_specific') {
            $targetIds = is_array($rule['target_customer_ids'] ?? null) ? $rule['target_customer_ids'] : [];
            return in_array($customerId, array_map('strval', $targetIds), true);
        }

        return $amount >= (float) ($rule['min_purchase_amount'] ?? 0);
    }

    // ========================================================================
    // Customer purchase data — derived from invoices
    // ========================================================================

    /**
     * Get aggregated customer spending for a date range from posted invoices.
     *
     * @return array<int, array{customer_id: string, customer_name: string, total_amount: float, invoice_count: int}>
     */
    public function getCustomerMonthlyTotals(int $mainId, string $dateFrom, string $dateTo): array
    {
        $sql = <<<SQL
SELECT
    COALESCE(d.lcustomerid, '') AS customer_id,
    TRIM(COALESCE(d.lcustomer_name, '')) AS customer_name,
    SUM(COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0)) AS total_amount,
    COUNT(DISTINCT d.lrefno) AS invoice_count
FROM tblinvoice_list d
INNER JOIN tblinvoice_itemrec i ON i.linvoice_refno = d.lrefno
WHERE d.lmain_id = :main_id
  AND d.ldate >= :date_from
  AND d.ldate <= :date_to
  AND COALESCE(d.lcancel, '') = ''
  AND COALESCE(d.lcustomerid, '') != ''
GROUP BY d.lcustomerid, d.lcustomer_name
ORDER BY total_amount DESC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('date_from', $dateFrom, PDO::PARAM_STR);
        $stmt->bindValue('date_to', $dateTo, PDO::PARAM_STR);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === false) {
            return [];
        }

        return array_map(static fn(array $row): array => [
            'customer_id' => (string) $row['customer_id'],
            'customer_name' => (string) $row['customer_name'],
            'total_amount' => (float) $row['total_amount'],
            'invoice_count' => (int) $row['invoice_count'],
        ], $rows);
    }

    /**
     * Check if a specific customer currently qualifies for any active loyalty discount.
     *
     * @return array{qualifies: bool, rule: ?array<string, mixed>, current_spending: float}
     */
    public function getCustomerActiveDiscount(int $mainId, string $customerId): array
    {
        $ruleResult = $this->listRules(false);
        $activeRules = $ruleResult['items'];

        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-t');

        $sql = <<<SQL
SELECT
    SUM(COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0)) AS total_amount
FROM tblinvoice_list d
INNER JOIN tblinvoice_itemrec i ON i.linvoice_refno = d.lrefno
WHERE d.lmain_id = :main_id
  AND d.lcustomerid = :customer_id
  AND d.ldate >= :date_from
  AND d.ldate <= :date_to
  AND COALESCE(d.lcancel, '') = ''
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('customer_id', $customerId, PDO::PARAM_STR);
        $stmt->bindValue('date_from', $dateFrom, PDO::PARAM_STR);
        $stmt->bindValue('date_to', $dateTo, PDO::PARAM_STR);
        $stmt->execute();

        $spending = (float) ($stmt->fetchColumn() ?: 0);
        $bestRule = $this->findBestRuleForCustomer($activeRules, $customerId, $spending);

        return [
            'qualifies' => $bestRule !== null,
            'rule' => $bestRule,
            'current_spending' => $spending,
        ];
    }
}
