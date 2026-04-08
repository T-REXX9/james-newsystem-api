<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class ProfitProtectionRepository
{
    private const PAGE = 'Profit Protection';
    private const ACTION_THRESHOLD_UPDATE = 'THRESHOLD_UPDATE';
    private const ACTION_PROFIT_OVERRIDE = 'PROFIT_OVERRIDE';
    private const ACTION_ADMIN_OVERRIDE = 'ADMIN_OVERRIDE';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{percentage: int, enforce_approval: bool, allow_override: bool}
     */
    public function getThresholdConfig(int $mainId): array
    {
        $percentage = $this->clampPercentage((float) $this->readNumberSetting($this->settingKey($mainId, 'threshold_pct'), 50));
        $enforceApproval = $this->readNumberSetting($this->settingKey($mainId, 'enforce_approval'), 1) > 0;
        $allowOverride = $this->readNumberSetting($this->settingKey($mainId, 'allow_override'), 1) > 0;

        return [
            'percentage' => $percentage,
            'enforce_approval' => $enforceApproval,
            'allow_override' => $allowOverride,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{percentage: int, enforce_approval: bool, allow_override: bool}
     */
    public function setThresholdConfig(int $mainId, int $userId, array $config): array
    {
        $normalized = [
            'percentage' => $this->clampPercentage($this->toFloat($config['percentage'] ?? 50)),
            'enforce_approval' => $this->toBool($config['enforce_approval'] ?? true),
            'allow_override' => $this->toBool($config['allow_override'] ?? true),
        ];

        $this->writeNumberSetting($this->settingKey($mainId, 'threshold_pct'), $normalized['percentage']);
        $this->writeNumberSetting($this->settingKey($mainId, 'enforce_approval'), $normalized['enforce_approval'] ? 1 : 0);
        $this->writeNumberSetting($this->settingKey($mainId, 'allow_override'), $normalized['allow_override'] ? 1 : 0);

        $payload = [
            'k' => 'threshold',
            'p' => $normalized['percentage'],
            'e' => $normalized['enforce_approval'] ? 1 : 0,
            'a' => $normalized['allow_override'] ? 1 : 0,
        ];
        $this->insertAuditTrail(
            $mainId,
            $userId > 0 ? $userId : null,
            self::ACTION_THRESHOLD_UPDATE,
            $this->encodePayload($payload)
        );

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{
     *   has_low_profit_items: bool,
     *   low_profit_items: array<int, array<string, mixed>>,
     *   total_items_count: int,
     *   low_profit_count: int,
     *   requires_approval: bool
     * }
     */
    public function validateItems(int $mainId, array $items): array
    {
        $config = $this->getThresholdConfig($mainId);
        $threshold = (float) $config['percentage'];

        $productIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = trim((string) ($item['product_id'] ?? ''));
            if ($productId !== '') {
                $productIds[] = $productId;
            }
        }

        $productMap = $this->loadProducts($mainId, $productIds);

        $lowProfitItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = trim((string) ($item['product_id'] ?? ''));
            if ($productId === '' || !isset($productMap[$productId])) {
                continue;
            }

            $product = $productMap[$productId];
            $cost = $this->toFloat($product['cost'] ?? 0);
            $sellingPrice = $this->toFloat($item['unit_price'] ?? 0);
            $discount = $this->toFloat($item['discount'] ?? 0);

            $calculation = $this->calculateProfit($sellingPrice, $cost, $discount, $threshold);
            if (!$calculation['is_below_threshold']) {
                continue;
            }

            $lowProfitItems[] = [
                'product_id' => $productId,
                'product_name' => (string) ($product['product_name'] ?? ''),
                'item_code' => (string) ($product['item_code'] ?? ''),
                'cost' => $cost,
                'selling_price' => $sellingPrice,
                'discount' => $discount,
                'net_price' => $calculation['net_price'],
                'profit_amount' => $calculation['profit_amount'],
                'profit_percentage' => $calculation['profit_percentage'],
                'threshold_percentage' => $threshold,
                'below_threshold' => true,
            ];
        }

        return [
            'has_low_profit_items' => count($lowProfitItems) > 0,
            'low_profit_items' => $lowProfitItems,
            'total_items_count' => count($items),
            'low_profit_count' => count($lowProfitItems),
            'requires_approval' => $config['enforce_approval'] && count($lowProfitItems) > 0,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function logProfitOverride(int $mainId, array $payload): array
    {
        $approvedBy = trim((string) ($payload['approved_by'] ?? ''));
        $approvedByUserId = ctype_digit($approvedBy) ? (int) $approvedBy : null;

        $record = [
            'order_id' => $this->trimmedOrNull($payload['order_id'] ?? null),
            'invoice_id' => $this->trimmedOrNull($payload['invoice_id'] ?? null),
            'item_id' => trim((string) ($payload['item_id'] ?? '')),
            'original_price' => $this->toFloat($payload['original_price'] ?? 0),
            'override_price' => $this->toFloat($payload['override_price'] ?? 0),
            'cost' => $this->toFloat($payload['cost'] ?? 0),
            'original_profit_pct' => $this->round2($this->toFloat($payload['original_profit_pct'] ?? 0)),
            'override_profit_pct' => $this->round2($this->toFloat($payload['override_profit_pct'] ?? 0)),
            'reason' => $this->trimmedOrNull($payload['reason'] ?? null),
            'override_type' => trim((string) ($payload['override_type'] ?? 'profit_threshold')) ?: 'profit_threshold',
            'approved_by' => $approvedBy,
        ];

        $encoded = $this->encodePayload([
            'k' => 'po',
            'oid' => $record['order_id'],
            'ivid' => $record['invoice_id'],
            'iid' => $record['item_id'],
            'op' => $record['original_price'],
            'np' => $record['override_price'],
            'c' => $record['cost'],
            'opp' => $record['original_profit_pct'],
            'npp' => $record['override_profit_pct'],
            'r' => $record['reason'],
            'ot' => $record['override_type'],
            'ab' => $record['approved_by'],
        ]);

        $inserted = $this->insertAuditTrail($mainId, $approvedByUserId, self::ACTION_PROFIT_OVERRIDE, $encoded);

        return [
            'id' => (string) $inserted['id'],
            'order_id' => $record['order_id'],
            'invoice_id' => $record['invoice_id'],
            'item_id' => $record['item_id'],
            'original_price' => $record['original_price'],
            'override_price' => $record['override_price'],
            'cost' => $record['cost'],
            'original_profit_pct' => $record['original_profit_pct'],
            'override_profit_pct' => $record['override_profit_pct'],
            'reason' => $record['reason'],
            'override_type' => $record['override_type'],
            'approved_by' => $record['approved_by'],
            'created_at' => $inserted['created_at'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listProfitOverrides(int $mainId, ?string $orderId = null, ?string $itemId = null, int $limit = 100): array
    {
        $limit = min(500, max(1, $limit));
        $fetchLimit = min(2000, max(250, $limit * 10));
        $rows = $this->fetchAuditRows($mainId, self::ACTION_PROFIT_OVERRIDE, null, null, $fetchLimit);

        $items = [];
        foreach ($rows as $row) {
            $mapped = $this->mapProfitOverrideRow($row);
            if ($mapped === null) {
                continue;
            }

            if ($orderId !== null && $orderId !== '' && (string) ($mapped['order_id'] ?? '') !== $orderId) {
                continue;
            }
            if ($itemId !== null && $itemId !== '' && (string) ($mapped['item_id'] ?? '') !== $itemId) {
                continue;
            }

            $items[] = $mapped;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array{
     *   total_overrides: int,
     *   average_original_profit_pct: float,
     *   average_override_profit_pct: float,
     *   top_override_reasons: array<int, array{reason: string, count: int}>
     * }
     */
    public function profitOverrideStats(int $mainId, ?string $startDate = null, ?string $endDate = null): array
    {
        $rows = $this->fetchAuditRows($mainId, self::ACTION_PROFIT_OVERRIDE, $startDate, $endDate, 5000);

        $total = 0;
        $sumOriginal = 0.0;
        $sumOverride = 0.0;
        $reasonCounts = [];

        foreach ($rows as $row) {
            $mapped = $this->mapProfitOverrideRow($row);
            if ($mapped === null) {
                continue;
            }

            $total++;
            $sumOriginal += $this->toFloat($mapped['original_profit_pct'] ?? 0);
            $sumOverride += $this->toFloat($mapped['override_profit_pct'] ?? 0);
            $reason = trim((string) ($mapped['reason'] ?? ''));
            if ($reason === '') {
                $reason = 'No reason provided';
            }
            $reasonCounts[$reason] = (int) ($reasonCounts[$reason] ?? 0) + 1;
        }

        if ($total === 0) {
            return [
                'total_overrides' => 0,
                'average_original_profit_pct' => 0.0,
                'average_override_profit_pct' => 0.0,
                'top_override_reasons' => [],
            ];
        }

        arsort($reasonCounts);
        $topReasons = [];
        foreach (array_slice($reasonCounts, 0, 5, true) as $reason => $count) {
            $topReasons[] = [
                'reason' => $reason,
                'count' => (int) $count,
            ];
        }

        return [
            'total_overrides' => $total,
            'average_original_profit_pct' => $this->round2($sumOriginal / $total),
            'average_override_profit_pct' => $this->round2($sumOverride / $total),
            'top_override_reasons' => $topReasons,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function logAdminOverride(int $mainId, array $payload): array
    {
        $performedBy = trim((string) ($payload['performed_by'] ?? ''));
        $performedByUserId = ctype_digit($performedBy) ? (int) $performedBy : null;

        $record = [
            'override_type' => trim((string) ($payload['override_type'] ?? '')),
            'entity_type' => trim((string) ($payload['entity_type'] ?? '')),
            'entity_id' => trim((string) ($payload['entity_id'] ?? '')),
            'reason' => $this->trimmedOrNull($payload['reason'] ?? null),
            'performed_by' => $performedBy,
            'original_value' => $this->compactJsonValue($payload['original_value'] ?? null),
            'override_value' => $this->compactJsonValue($payload['override_value'] ?? null),
        ];

        $encoded = $this->encodePayload([
            'k' => 'ao',
            'ot' => $record['override_type'],
            'et' => $record['entity_type'],
            'eid' => $record['entity_id'],
            'ov' => $record['original_value'],
            'nv' => $record['override_value'],
            'r' => $record['reason'],
            'pb' => $record['performed_by'],
        ]);

        $inserted = $this->insertAuditTrail($mainId, $performedByUserId, self::ACTION_ADMIN_OVERRIDE, $encoded);

        return [
            'id' => (string) $inserted['id'],
            'override_type' => $record['override_type'],
            'entity_type' => $record['entity_type'],
            'entity_id' => $record['entity_id'],
            'original_value' => $record['original_value'] !== null ? json_decode($record['original_value'], true) : null,
            'override_value' => $record['override_value'] !== null ? json_decode($record['override_value'], true) : null,
            'reason' => $record['reason'],
            'performed_by' => $record['performed_by'],
            'created_at' => $inserted['created_at'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAdminOverrides(
        int $mainId,
        ?string $overrideType = null,
        ?string $entityType = null,
        ?string $entityId = null,
        int $limit = 100
    ): array {
        $limit = min(500, max(1, $limit));
        $fetchLimit = min(2000, max(250, $limit * 10));
        $rows = $this->fetchAuditRows($mainId, self::ACTION_ADMIN_OVERRIDE, null, null, $fetchLimit);

        $items = [];
        foreach ($rows as $row) {
            $mapped = $this->mapAdminOverrideRow($row);
            if ($mapped === null) {
                continue;
            }

            if ($overrideType !== null && $overrideType !== '' && (string) ($mapped['override_type'] ?? '') !== $overrideType) {
                continue;
            }
            if ($entityType !== null && $entityType !== '' && (string) ($mapped['entity_type'] ?? '') !== $entityType) {
                continue;
            }
            if ($entityId !== null && $entityId !== '' && (string) ($mapped['entity_id'] ?? '') !== $entityId) {
                continue;
            }

            $items[] = $mapped;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    private function settingKey(int $mainId, string $key): string
    {
        return sprintf('profit_protection.main_%d.%s', $mainId, $key);
    }

    private function readNumberSetting(string $type, int $default): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lmax_no
             FROM tblnumber_generator
             WHERE ltransaction_type = :type
             ORDER BY lid DESC
             LIMIT 1'
        );
        $stmt->execute(['type' => $type]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return $default;
        }

        return (int) $value;
    }

    private function writeNumberSetting(string $type, int $value): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblnumber_generator (ltransaction_type, lmax_no)
             VALUES (:type, :value)'
        );
        $stmt->execute([
            'type' => $type,
            'value' => $value,
        ]);
    }

    /**
     * @param array<int, string> $productIds
     * @return array<string, array{product_id: string, item_code: string, product_name: string, cost: float}>
     */
    private function loadProducts(int $mainId, array $productIds): array
    {
        $ids = [];
        foreach ($productIds as $id) {
            $trimmed = trim((string) $id);
            if ($trimmed === '') {
                continue;
            }
            $ids[$trimmed] = true;
        }
        $uniqueIds = array_keys($ids);
        if ($uniqueIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($uniqueIds), '?'));
        $sql = <<<SQL
SELECT
    COALESCE(itm.lsession, '') AS product_id,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(NULLIF(TRIM(itm.ldescription), ''), NULLIF(TRIM(itm.ltitile), ''), COALESCE(itm.litemcode, '')) AS product_name,
    CAST(COALESCE(itm.lcost, 0) AS DECIMAL(15,2)) AS cost
FROM tblinventory_item itm
WHERE CAST(itm.lmain_id AS UNSIGNED) = ?
  AND (itm.lsession IN ({$placeholders}) OR itm.litemcode IN ({$placeholders}))
SQL;

        $params = [(int) $mainId];
        foreach ($uniqueIds as $id) {
            $params[] = $id;
        }
        foreach ($uniqueIds as $id) {
            $params[] = $id;
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $session = trim((string) ($row['product_id'] ?? ''));
            $itemCode = trim((string) ($row['item_code'] ?? ''));
            $normalized = [
                'product_id' => $session !== '' ? $session : $itemCode,
                'item_code' => $itemCode,
                'product_name' => trim((string) ($row['product_name'] ?? '')),
                'cost' => $this->toFloat($row['cost'] ?? 0),
            ];

            if ($session !== '') {
                $map[$session] = $normalized;
            }
            if ($itemCode !== '' && !isset($map[$itemCode])) {
                $map[$itemCode] = $normalized;
            }
        }

        return $map;
    }

    /**
     * @return array{
     *   net_price: float,
     *   profit_amount: float,
     *   profit_percentage: float,
     *   is_below_threshold: bool
     * }
     */
    private function calculateProfit(float $sellingPrice, float $cost, float $discount, float $threshold): array
    {
        $netPrice = $sellingPrice - $discount;
        $profitAmount = $netPrice - $cost;
        $profitPercentage = $netPrice > 0 ? ($profitAmount / $netPrice) * 100 : 0.0;

        return [
            'net_price' => $this->round2($netPrice),
            'profit_amount' => $this->round2($profitAmount),
            'profit_percentage' => $this->round2($profitPercentage),
            'is_below_threshold' => $profitPercentage < $threshold,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAuditRows(
        int $mainId,
        string $action,
        ?string $startDate,
        ?string $endDate,
        int $limit
    ): array {
        $limit = min(5000, max(1, $limit));

        $sql = <<<SQL
SELECT
    at.lid,
    at.lmain_id,
    at.luser_id,
    at.ldatetime,
    at.lrefno,
    CAST(acc.lid AS CHAR) AS actor_id,
    COALESCE(acc.lemail, '') AS actor_email,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS actor_name
FROM tblaudit_trail at
LEFT JOIN tblaccount acc ON acc.lid = at.luser_id
WHERE at.lmain_id = :main_id
  AND at.lpage = :page
  AND at.laction = :action
SQL;

        $params = [
            'main_id' => $mainId,
            'page' => self::PAGE,
            'action' => $action,
        ];

        $normalizedStart = $this->normalizeDateBoundary($startDate, false);
        $normalizedEnd = $this->normalizeDateBoundary($endDate, true);

        if ($normalizedStart !== null) {
            $sql .= ' AND at.ldatetime >= :start_date';
            $params['start_date'] = $normalizedStart;
        }
        if ($normalizedEnd !== null) {
            $sql .= ' AND at.ldatetime <= :end_date';
            $params['end_date'] = $normalizedEnd;
        }

        $sql .= ' ORDER BY at.lid DESC LIMIT :limit';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $params['main_id'], PDO::PARAM_INT);
        $stmt->bindValue('page', $params['page'], PDO::PARAM_STR);
        $stmt->bindValue('action', $params['action'], PDO::PARAM_STR);
        if (isset($params['start_date'])) {
            $stmt->bindValue('start_date', $params['start_date'], PDO::PARAM_STR);
        }
        if (isset($params['end_date'])) {
            $stmt->bindValue('end_date', $params['end_date'], PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function mapProfitOverrideRow(array $row): ?array
    {
        $payload = $this->decodePayload((string) ($row['lrefno'] ?? ''));
        if ($payload === null || (string) ($payload['k'] ?? '') !== 'po') {
            return null;
        }

        $approverName = trim((string) ($row['actor_name'] ?? ''));
        $approverEmail = trim((string) ($row['actor_email'] ?? ''));
        $approverId = trim((string) ($row['actor_id'] ?? ''));

        $mapped = [
            'id' => (string) ($row['lid'] ?? ''),
            'order_id' => $this->trimmedOrNull($payload['oid'] ?? null),
            'invoice_id' => $this->trimmedOrNull($payload['ivid'] ?? null),
            'item_id' => trim((string) ($payload['iid'] ?? '')),
            'original_price' => $this->toFloat($payload['op'] ?? 0),
            'override_price' => $this->toFloat($payload['np'] ?? 0),
            'cost' => $this->toFloat($payload['c'] ?? 0),
            'original_profit_pct' => $this->round2($this->toFloat($payload['opp'] ?? 0)),
            'override_profit_pct' => $this->round2($this->toFloat($payload['npp'] ?? 0)),
            'reason' => $this->trimmedOrNull($payload['r'] ?? null),
            'override_type' => trim((string) ($payload['ot'] ?? 'profit_threshold')) ?: 'profit_threshold',
            'approved_by' => trim((string) ($payload['ab'] ?? ($row['luser_id'] ?? ''))),
            'created_at' => (string) ($row['ldatetime'] ?? ''),
        ];

        if ($approverId !== '') {
            $mapped['approver'] = [
                'id' => $approverId,
                'email' => $approverEmail,
                'full_name' => $approverName !== '' ? $approverName : $approverEmail,
            ];
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function mapAdminOverrideRow(array $row): ?array
    {
        $payload = $this->decodePayload((string) ($row['lrefno'] ?? ''));
        if ($payload === null || (string) ($payload['k'] ?? '') !== 'ao') {
            return null;
        }

        $performerName = trim((string) ($row['actor_name'] ?? ''));
        $performerEmail = trim((string) ($row['actor_email'] ?? ''));
        $performerId = trim((string) ($row['actor_id'] ?? ''));

        $originalValue = $this->decodeJsonValue($payload['ov'] ?? null);
        $overrideValue = $this->decodeJsonValue($payload['nv'] ?? null);

        $mapped = [
            'id' => (string) ($row['lid'] ?? ''),
            'override_type' => trim((string) ($payload['ot'] ?? '')),
            'entity_type' => trim((string) ($payload['et'] ?? '')),
            'entity_id' => trim((string) ($payload['eid'] ?? '')),
            'original_value' => $originalValue,
            'override_value' => $overrideValue,
            'reason' => $this->trimmedOrNull($payload['r'] ?? null),
            'performed_by' => trim((string) ($payload['pb'] ?? ($row['luser_id'] ?? ''))),
            'created_at' => (string) ($row['ldatetime'] ?? ''),
        ];

        if ($performerId !== '') {
            $mapped['performer'] = [
                'id' => $performerId,
                'email' => $performerEmail,
                'full_name' => $performerName !== '' ? $performerName : $performerEmail,
            ];
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{id: int, created_at: string}
     */
    private function insertAuditTrail(int $mainId, ?int $userId, string $action, string $refno): array
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblaudit_trail (lmain_id, luser_id, lpage, laction, lrefno, ldatetime)
             VALUES (:main_id, :user_id, :page, :action, :refno, NOW())'
        );
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        if ($userId !== null && $userId > 0) {
            $stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue('user_id', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue('page', self::PAGE, PDO::PARAM_STR);
        $stmt->bindValue('action', $action, PDO::PARAM_STR);
        $stmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $stmt->execute();

        $id = (int) $this->db->pdo()->lastInsertId();
        $createdAt = date('Y-m-d H:i:s');

        if ($id > 0) {
            $fetchStmt = $this->db->pdo()->prepare('SELECT ldatetime FROM tblaudit_trail WHERE lid = :id LIMIT 1');
            $fetchStmt->execute(['id' => $id]);
            $value = $fetchStmt->fetchColumn();
            if ($value !== false && $value !== null) {
                $createdAt = (string) $value;
            }
        }

        return [
            'id' => $id,
            'created_at' => $createdAt,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePayload(array $payload): string
    {
        $compact = [
            'k' => (string) ($payload['k'] ?? ''),
            'oid' => $this->truncateString($payload['oid'] ?? null, 40),
            'ivid' => $this->truncateString($payload['ivid'] ?? null, 40),
            'iid' => $this->truncateString($payload['iid'] ?? null, 40),
            'op' => isset($payload['op']) ? $this->toFloat($payload['op']) : null,
            'np' => isset($payload['np']) ? $this->toFloat($payload['np']) : null,
            'c' => isset($payload['c']) ? $this->toFloat($payload['c']) : null,
            'opp' => isset($payload['opp']) ? $this->round2($this->toFloat($payload['opp'])) : null,
            'npp' => isset($payload['npp']) ? $this->round2($this->toFloat($payload['npp'])) : null,
            'r' => $this->truncateString($payload['r'] ?? null, 80),
            'ot' => $this->truncateString($payload['ot'] ?? null, 24),
            'ab' => $this->truncateString($payload['ab'] ?? null, 16),
            'et' => $this->truncateString($payload['et'] ?? null, 24),
            'eid' => $this->truncateString($payload['eid'] ?? null, 40),
            'ov' => $this->truncateString($payload['ov'] ?? null, 80),
            'nv' => $this->truncateString($payload['nv'] ?? null, 80),
            'pb' => $this->truncateString($payload['pb'] ?? null, 16),
            'p' => isset($payload['p']) ? (int) $payload['p'] : null,
            'e' => isset($payload['e']) ? (int) $payload['e'] : null,
            'a' => isset($payload['a']) ? (int) $payload['a'] : null,
        ];

        $json = json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '{"k":"invalid"}';
        }

        if (strlen($json) <= 255) {
            return $json;
        }

        $minimal = [
            'k' => (string) ($compact['k'] ?? 'x'),
            'iid' => $compact['iid'],
            'opp' => $compact['opp'],
            'npp' => $compact['npp'],
            'r' => $this->truncateString($compact['r'] ?? null, 24),
        ];
        $fallback = json_encode($minimal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($fallback === false) {
            return '{"k":"x"}';
        }

        if (strlen($fallback) <= 255) {
            return $fallback;
        }

        return '{"k":"x","r":"trimmed"}';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private function decodeJsonValue(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function compactJsonValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return null;
        }

        if (strlen($json) <= 120) {
            return $json;
        }

        return null;
    }

    private function normalizeDateBoundary(?string $value, bool $endOfDay): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        $timestamp = strtotime($trimmed);
        if ($timestamp === false) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            return date($endOfDay ? 'Y-m-d 23:59:59' : 'Y-m-d 00:00:00', $timestamp);
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function toFloat(mixed $value): float
    {
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (!is_numeric($value)) {
            return 0.0;
        }
        return (float) $value;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((float) $value) > 0;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function round2(float $value): float
    {
        return round($value, 2);
    }

    private function clampPercentage(float $value): int
    {
        $rounded = (int) round($value);
        return min(90, max(10, $rounded));
    }

    private function truncateString(mixed $value, int $max): ?string
    {
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }
        if (strlen($string) <= $max) {
            return $string;
        }
        return substr($string, 0, $max);
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        $string = trim((string) $value);
        return $string === '' ? null : $string;
    }
}
