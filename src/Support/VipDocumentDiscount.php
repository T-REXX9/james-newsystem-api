<?php

declare(strict_types=1);

namespace App\Support;

final class VipDocumentDiscount
{
    /**
     * @return array{applied:bool,tier:string,percentage:float,discount_amount:float,total_to_pay:float}
     */
    public static function compute(float $grandTotal, string $standing, float $percentage, bool $apply): array
    {
        $grandTotal = self::roundMoney($grandTotal);
        $percentage = min(100.0, max(0.0, $percentage));
        $normalizedStanding = strtolower(trim($standing));
        $shouldApply = $apply && $normalizedStanding !== 'regular' && $percentage > 0;

        if (!$shouldApply) {
            return [
                'applied' => false,
                'tier' => $normalizedStanding !== '' ? $normalizedStanding : 'regular',
                'percentage' => $percentage,
                'discount_amount' => 0.0,
                'total_to_pay' => $grandTotal,
            ];
        }

        $discountAmount = self::roundMoney($grandTotal * ($percentage / 100));
        return [
            'applied' => true,
            'tier' => $normalizedStanding,
            'percentage' => $percentage,
            'discount_amount' => $discountAmount,
            'total_to_pay' => self::roundMoney($grandTotal - $discountAmount),
        ];
    }

    public static function billedAmount(float $preDiscountTotal, float $discountAmount): float
    {
        return self::roundMoney(max(0.0, $preDiscountTotal - $discountAmount));
    }

    private static function roundMoney(float $value): float
    {
        return round($value, 2);
    }
}
