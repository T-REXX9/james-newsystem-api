<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Spend-based VIP standing for the benefit month (driven by last calendar month's sales).
 * Matches the frontend resolveVipDiscountLevel contract.
 */
final class VipStanding
{
    /**
     * @return 'regular'|'silver'|'gold'
     */
    public static function resolveLevel(
        float $lastMonthSpend,
        float $oneTimeDiscountThreshold,
        float $unlimitedDiscountThreshold
    ): string {
        $spend = max(0.0, $lastMonthSpend);
        if ($spend >= max(0.0, $unlimitedDiscountThreshold)) {
            return 'gold';
        }
        if ($spend >= max(0.0, $oneTimeDiscountThreshold)) {
            return 'silver';
        }

        return 'regular';
    }
}
