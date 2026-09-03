<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class VipTierSettingsRepository
{
    private const PAGE = 'VIP Tier Settings';
    private const ACTION_CONFIG_UPDATE = 'VIP_CONFIG_UPDATE';
    private const DEFAULT_ONE_TIME_DISCOUNT_THRESHOLD = 10000;
    private const DEFAULT_UNLIMITED_DISCOUNT_THRESHOLD = 30000;
    private const DEFAULT_DISCOUNT_PERCENTAGE = 10;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{
     *   one_time_discount_threshold: int,
     *   unlimited_discount_threshold: int,
     *   discount_percentage: int
     * }
     */
    public function getConfig(int $mainId): array
    {
        return $this->normalizeConfig([
            'one_time_discount_threshold' => $this->readNumberSetting(
                $this->settingKey($mainId, 'one_time_discount_threshold'),
                self::DEFAULT_ONE_TIME_DISCOUNT_THRESHOLD
            ),
            'unlimited_discount_threshold' => $this->readNumberSetting(
                $this->settingKey($mainId, 'unlimited_discount_threshold'),
                self::DEFAULT_UNLIMITED_DISCOUNT_THRESHOLD
            ),
            'discount_percentage' => $this->readNumberSetting(
                $this->settingKey($mainId, 'discount_percentage'),
                self::DEFAULT_DISCOUNT_PERCENTAGE
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *   one_time_discount_threshold: int,
     *   unlimited_discount_threshold: int,
     *   discount_percentage: int
     * }
     */
    public function setConfig(int $mainId, int $userId, array $config): array
    {
        $normalized = $this->normalizeConfig($config);

        foreach ($normalized as $key => $value) {
            $this->writeNumberSetting($this->settingKey($mainId, $key), $value);
        }

        $payload = json_encode($normalized, JSON_UNESCAPED_SLASHES);

        $this->insertAuditTrail(
            $mainId,
            $userId > 0 ? $userId : null,
            self::ACTION_CONFIG_UPDATE,
            $payload !== false ? $payload : '{}'
        );

        return $normalized;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *   one_time_discount_threshold: int,
     *   unlimited_discount_threshold: int,
     *   discount_percentage: int
     * }
     */
    private function normalizeConfig(array $config): array
    {
        $oneTime = $this->normalizeMoney($config['one_time_discount_threshold'] ?? self::DEFAULT_ONE_TIME_DISCOUNT_THRESHOLD);
        $unlimited = max($oneTime, $this->normalizeMoney($config['unlimited_discount_threshold'] ?? self::DEFAULT_UNLIMITED_DISCOUNT_THRESHOLD));
        $percentage = min(100, $this->normalizeMoney($config['discount_percentage'] ?? self::DEFAULT_DISCOUNT_PERCENTAGE));

        return [
            'one_time_discount_threshold' => $oneTime,
            'unlimited_discount_threshold' => $unlimited,
            'discount_percentage' => $percentage,
        ];
    }

    private function normalizeMoney(mixed $value): int
    {
        $amount = (int) round((float) $value);
        return max(0, $amount);
    }

    private function settingKey(int $mainId, string $key): string
    {
        return sprintf('vip_tier.main_%d.%s', $mainId, $key);
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

    private function insertAuditTrail(int $mainId, ?int $userId, string $action, string $refno): void
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
    }
}
