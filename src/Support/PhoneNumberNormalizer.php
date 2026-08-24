<?php

declare(strict_types=1);

namespace App\Support;

final class PhoneNumberNormalizer
{
    public static function normalize(string $number): string
    {
        $digits = preg_replace('/\D+/', '', trim($number)) ?? '';
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '0' . substr($digits, 2);
        }

        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '0' . $digits;
        }

        return $digits;
    }

    public static function equivalent(string $left, string $right): bool
    {
        $leftNormalized = self::normalize($left);
        $rightNormalized = self::normalize($right);
        if ($leftNormalized === '' || $rightNormalized === '') {
            return false;
        }

        return $leftNormalized === $rightNormalized;
    }
}
