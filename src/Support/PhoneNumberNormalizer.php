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
        $leftCandidates = self::candidates($left);
        $rightCandidates = self::candidates($right);
        if ($leftCandidates === [] || $rightCandidates === []) {
            return false;
        }

        return array_intersect($leftCandidates, $rightCandidates) !== [];
    }

    /**
     * Customer records sometimes keep multiple phone numbers in one field,
     * separated by a slash, comma, semicolon, pipe, or line break. Treat each
     * entry as a separate number instead of concatenating all of their digits.
     *
     * @return list<string>
     */
    public static function candidates(string $value): array
    {
        $parts = preg_split('/\s*(?:\/|,|;|\||\R)\s*/', trim($value)) ?: [];
        $numbers = [];
        foreach ($parts as $part) {
            $normalized = self::normalize($part);
            if ($normalized !== '') {
                $numbers[] = $normalized;
            }
        }

        return array_values(array_unique($numbers));
    }
}
