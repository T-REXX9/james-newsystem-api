<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class PurchaseReceivingPolicy
{
    /** @param array<string, mixed> $purchaseOrder */
    public static function assertEligiblePurchaseOrder(array $purchaseOrder): void
    {
        $status = strtolower(trim((string) ($purchaseOrder['status'] ?? '')));
        if (!in_array($status, ['posted', 'approved'], true)) {
            throw new RuntimeException('Receiving requires a posted purchase order');
        }

        if (trim((string) ($purchaseOrder['pr_refno'] ?? '')) === '' && trim((string) ($purchaseOrder['pr_number'] ?? '')) === '') {
            throw new RuntimeException('Receiving requires a purchase order created from a purchase request');
        }
    }

    /** @param array<string, mixed> $purchaseOrderItem */
    public static function remainingQuantity(array $purchaseOrderItem): int
    {
        return max(0, (int) ($purchaseOrderItem['qty'] ?? 0) - (int) ($purchaseOrderItem['receiving_qty'] ?? 0));
    }

    /** @param array<string, mixed> $purchaseOrderItem */
    public static function assertReceivableLine(array $purchaseOrderItem, int $quantity): void
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Receiving quantity must be greater than 0');
        }

        $remaining = self::remainingQuantity($purchaseOrderItem);
        if ($remaining <= 0) {
            throw new RuntimeException('Purchase order item is already fully received');
        }
        if ($quantity > $remaining) {
            throw new RuntimeException("Receiving quantity cannot exceed the remaining purchase order quantity ({$remaining})");
        }
    }
}
