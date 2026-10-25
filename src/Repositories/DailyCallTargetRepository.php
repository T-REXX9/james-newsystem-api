<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class DailyCallTargetRepository
{
    private const DEFAULT_TARGET = 3000000;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{monthly_target: int} */
    public function getTarget(int $mainId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lmax_no FROM tblnumber_generator WHERE ltransaction_type = :type ORDER BY lid DESC LIMIT 1'
        );
        $stmt->execute(['type' => $this->settingKey($mainId)]);
        $value = $stmt->fetchColumn();

        return ['monthly_target' => $value === false || $value === null ? self::DEFAULT_TARGET : max(0, (int) $value)];
    }

    /** @return array{monthly_target: int} */
    public function setTarget(int $mainId, int $target): array
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblnumber_generator (ltransaction_type, lmax_no) VALUES (:type, :value)'
        );
        $stmt->execute(['type' => $this->settingKey($mainId), 'value' => $target]);

        return ['monthly_target' => $target];
    }

    private function settingKey(int $mainId): string
    {
        return sprintf('daily_call.main_%d.monthly_target', $mainId);
    }
}
