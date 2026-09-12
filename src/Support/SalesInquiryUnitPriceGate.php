<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Detects when a Sales Inquiry payload changes catalog (non-NotListed) unit prices.
 */
final class SalesInquiryUnitPriceGate
{
    public static function isNotListed(array $item): bool
    {
        return strcasecmp(trim((string) ($item['remark'] ?? '')), 'NotListed') === 0;
    }

    /**
     * True when a single-item update changes unit_price on a catalog line.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existingItem
     */
    public static function itemUpdateChangesCatalogUnitPrice(array $payload, ?array $existingItem): bool
    {
        if ($existingItem === null || !array_key_exists('unit_price', $payload)) {
            return false;
        }
        if (self::isNotListed($existingItem)) {
            return false;
        }

        $submitted = round((float) $payload['unit_price'], 2);
        $current = round((float) ($existingItem['unit_price'] ?? 0), 2);
        return $submitted !== $current;
    }

    /**
     * True when any catalog line's unit_price differs from the Product Database list price.
     *
     * @param array<int, mixed> $items
     * @param callable(array<string, mixed>): ?float $listPriceForItem
     */
    public static function itemsOverrideCatalogListPrices(array $items, callable $listPriceForItem): bool
    {
        foreach ($items as $item) {
            if (!is_array($item) || self::isNotListed($item) || !array_key_exists('unit_price', $item)) {
                continue;
            }
            $listPrice = $listPriceForItem($item);
            if ($listPrice === null) {
                continue;
            }
            $submitted = round((float) $item['unit_price'], 2);
            if ($submitted !== round($listPrice, 2)) {
                return true;
            }
        }

        return false;
    }
}
