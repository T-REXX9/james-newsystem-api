<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

final class DailyCallClaimPolicy
{
    public static function canAcquire(?array $claim, int $agentUserId, DateTimeImmutable $now): bool
    {
        if ($claim === null) {
            return true;
        }

        if (($claim['status'] ?? '') === 'completed') {
            return false;
        }

        if ((int) ($claim['agent_user_id'] ?? 0) === $agentUserId) {
            return true;
        }

        $expiresAt = new DateTimeImmutable((string) ($claim['expires_at'] ?? '1970-01-01 00:00:00'));
        return $expiresAt <= $now;
    }
}
