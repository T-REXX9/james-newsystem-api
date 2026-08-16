<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class ReturnToSupplierStockPolicy
{
    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, array<string, mixed>>
     */
    public static function aggregateByInventoryItem(array $items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $session = trim((string) ($item['inv_refno'] ?? ''));
            $quantity = (float) ($item['qty_returned'] ?? 0);
            if ($session === '' || $quantity <= 0) {
                continue;
            }
            if (!isset($grouped[$session])) {
                $grouped[$session] = $item;
                $grouped[$session]['qty_returned'] = 0.0;
            }
            $grouped[$session]['qty_returned'] = (float) $grouped[$session]['qty_returned'] + $quantity;
        }
        return $grouped;
    }

    public static function assertAvailable(string $itemLabel, float $quantity, float $available): void
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Return quantity must be greater than 0');
        }
        if ($quantity > max(0.0, $available)) {
            throw new RuntimeException(
                'Insufficient centralized stock for ' . ($itemLabel !== '' ? $itemLabel : 'item') .
                '. Available: ' . self::formatQuantity($available) .
                '; requested return: ' . self::formatQuantity($quantity)
            );
        }
    }

    private static function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.');
    }
}
