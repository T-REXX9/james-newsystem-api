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
                // Cannot verify against Product Database — treat as an override.
                return true;
            }
            $submitted = round((float) $item['unit_price'], 2);
            if ($submitted !== round($listPrice, 2)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when payload catalog lines change unit_price vs existing inquiry lines,
     * or introduce new catalog lines that diverge from list price.
     *
     * @param array<int, mixed> $items
     * @param array<int, mixed> $existingItems
     * @param callable(array<string, mixed>): ?float $listPriceForItem
     */
    public static function itemsChangeCatalogUnitPrices(
        array $items,
        array $existingItems,
        callable $listPriceForItem
    ): bool {
        $existingByKey = [];
        foreach ($existingItems as $existing) {
            if (!is_array($existing)) {
                continue;
            }
            foreach (self::itemMatchKeys($existing) as $key) {
                $existingByKey[$key] = $existing;
            }
        }

        foreach ($items as $item) {
            if (!is_array($item) || self::isNotListed($item) || !array_key_exists('unit_price', $item)) {
                continue;
            }

            $matched = null;
            foreach (self::itemMatchKeys($item) as $key) {
                if (isset($existingByKey[$key])) {
                    $matched = $existingByKey[$key];
                    break;
                }
            }

            if ($matched !== null) {
                $submitted = round((float) $item['unit_price'], 2);
                $current = round((float) ($matched['unit_price'] ?? 0), 2);
                if ($submitted !== $current) {
                    return true;
                }
                continue;
            }

            // New catalog line: require permission when price differs from list (or list unknown).
            $listPrice = $listPriceForItem($item);
            if ($listPrice === null) {
                return true;
            }
            if (round((float) $item['unit_price'], 2) !== round($listPrice, 2)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<int, string>
     */
    private static function itemMatchKeys(array $item): array
    {
        $keys = [];
        foreach (['id', 'item_refno', 'item_id'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '') {
                $keys[] = $field . ':' . $value;
            }
        }
        return $keys;
    }
}
