<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class VipTierSettingsRepository
{
    private const PAGE = 'VIP Tier Settings';
    private const ACTION_CONFIG_UPDATE = 'VIP_CONFIG_UPDATE';
    private const DEFAULT_SILVER_ENTRY_THRESHOLD = 10000;
    private const DEFAULT_GOLD_ENTRY_THRESHOLD = 30000;
    private const DEFAULT_SILVER_MAINTENANCE_THRESHOLD = 5000;
    private const DEFAULT_GOLD_MAINTENANCE_THRESHOLD = 10000;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{
     *   silver_entry_threshold: int,
     *   gold_entry_threshold: int,
     *   silver_maintenance_threshold: int,
     *   gold_maintenance_threshold: int
     * }
     */
    public function getConfig(int $mainId): array
    {
        return $this->normalizeConfig([
            'silver_entry_threshold' => $this->readNumberSetting(
                $this->settingKey($mainId, 'silver_entry_threshold'),
                self::DEFAULT_SILVER_ENTRY_THRESHOLD
            ),
            'gold_entry_threshold' => $this->readNumberSetting(
                $this->settingKey($mainId, 'gold_entry_threshold'),
                self::DEFAULT_GOLD_ENTRY_THRESHOLD
            ),
            'silver_maintenance_threshold' => $this->readNumberSetting(
                $this->settingKey($mainId, 'silver_maintenance_threshold'),
                self::DEFAULT_SILVER_MAINTENANCE_THRESHOLD
            ),
            'gold_maintenance_threshold' => $this->readNumberSetting(
                $this->settingKey($mainId, 'gold_maintenance_threshold'),
                self::DEFAULT_GOLD_MAINTENANCE_THRESHOLD
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *   silver_entry_threshold: int,
     *   gold_entry_threshold: int,
     *   silver_maintenance_threshold: int,
     *   gold_maintenance_threshold: int
     * }
     */
    public function setConfig(int $mainId, int $userId, array $config): array
    {
        $normalized = $this->normalizeConfig($config);

        $this->writeNumberSetting($this->settingKey($mainId, 'silver_entry_threshold'), $normalized['silver_entry_threshold']);
        $this->writeNumberSetting($this->settingKey($mainId, 'gold_entry_threshold'), $normalized['gold_entry_threshold']);
        $this->writeNumberSetting($this->settingKey($mainId, 'silver_maintenance_threshold'), $normalized['silver_maintenance_threshold']);
        $this->writeNumberSetting($this->settingKey($mainId, 'gold_maintenance_threshold'), $normalized['gold_maintenance_threshold']);

        $payload = json_encode([
            'silver_entry_threshold' => $normalized['silver_entry_threshold'],
            'gold_entry_threshold' => $normalized['gold_entry_threshold'],
            'silver_maintenance_threshold' => $normalized['silver_maintenance_threshold'],
            'gold_maintenance_threshold' => $normalized['gold_maintenance_threshold'],
        ], JSON_UNESCAPED_SLASHES);

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
     *   silver_entry_threshold: int,
     *   gold_entry_threshold: int,
     *   silver_maintenance_threshold: int,
     *   gold_maintenance_threshold: int
     * }
     */
    private function normalizeConfig(array $config): array
    {
        $silverEntryThreshold = $this->normalizeMoney($config['silver_entry_threshold'] ?? self::DEFAULT_SILVER_ENTRY_THRESHOLD);
        $goldEntryThreshold = max(
            $silverEntryThreshold,
            $this->normalizeMoney($config['gold_entry_threshold'] ?? self::DEFAULT_GOLD_ENTRY_THRESHOLD)
        );

        $silverMaintenanceThreshold = $this->normalizeMoney(
            $config['silver_maintenance_threshold'] ?? self::DEFAULT_SILVER_MAINTENANCE_THRESHOLD
        );
        $goldMaintenanceThreshold = max(
            $silverMaintenanceThreshold,
            $this->normalizeMoney($config['gold_maintenance_threshold'] ?? self::DEFAULT_GOLD_MAINTENANCE_THRESHOLD)
        );

        return [
            'silver_entry_threshold' => $silverEntryThreshold,
            'gold_entry_threshold' => $goldEntryThreshold,
            'silver_maintenance_threshold' => $silverMaintenanceThreshold,
            'gold_maintenance_threshold' => $goldMaintenanceThreshold,
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
