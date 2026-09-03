<?php

declare(strict_types=1);

namespace App\Support;

final class PurchasedItemMatcher
{
    /**
     * @return array<int, string>
     */
    public static function tokens(string $value): array
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $value));
        if ($normalized === '') {
            return [];
        }

        $tokens = [$normalized];
        if (str_starts_with($normalized, 'P') && strlen($normalized) >= 7) {
            $tokens[] = substr($normalized, 1);
        }

        return array_values(array_unique($tokens));
    }

    public static function valuesMatch(string $left, string $right): bool
    {
        $leftTokens = self::tokens($left);
        $rightTokens = self::tokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        foreach ($leftTokens as $leftToken) {
            foreach ($rightTokens as $rightToken) {
                if ($leftToken === $rightToken) {
                    return true;
                }
                if (strlen($leftToken) >= 6 && strlen($rightToken) >= 6 && (
                    str_contains($leftToken, $rightToken) || str_contains($rightToken, $leftToken)
                )) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function identifiersMatch(
        string $itemCode,
        string $partNo,
        string $candidateItemCode,
        string $candidatePartNo
    ): bool {
        return ($itemCode !== '' && self::valuesMatch($itemCode, $candidateItemCode))
            || ($partNo !== '' && self::valuesMatch($partNo, $candidatePartNo))
            || ($itemCode !== '' && self::valuesMatch($itemCode, $candidatePartNo))
            || ($partNo !== '' && self::valuesMatch($partNo, $candidateItemCode));
    }

    public static function notPurchasedMessage(string $itemLabel = ''): string
    {
        $label = trim($itemLabel);
        if ($label !== '') {
            return 'This item (' . $label . ') is not in the purchase history, so it cannot be used here.';
        }

        return 'This item is not in the purchase history, so it cannot be used here.';
    }
}
