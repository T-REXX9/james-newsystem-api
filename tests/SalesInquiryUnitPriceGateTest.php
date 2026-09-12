<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/SalesInquiryUnitPriceGate.php';

use App\Support\SalesInquiryUnitPriceGate;

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: {$label}");
    }
    echo "PASS: {$label}\n";
};

$assert(SalesInquiryUnitPriceGate::isNotListed(['remark' => 'NotListed']) === true, 'detects NotListed remark');
$assert(SalesInquiryUnitPriceGate::isNotListed(['remark' => 'OnStock']) === false, 'detects catalog remark');

$assert(SalesInquiryUnitPriceGate::itemUpdateChangesCatalogUnitPrice(
    ['unit_price' => 90],
    ['remark' => 'OnStock', 'unit_price' => 100]
) === true, 'flags catalog unit price change on item update');

$assert(SalesInquiryUnitPriceGate::itemUpdateChangesCatalogUnitPrice(
    ['unit_price' => 100],
    ['remark' => 'OnStock', 'unit_price' => 100]
) === false, 'ignores unchanged catalog unit price');

$assert(SalesInquiryUnitPriceGate::itemUpdateChangesCatalogUnitPrice(
    ['unit_price' => 50],
    ['remark' => 'NotListed', 'unit_price' => 0]
) === false, 'allows NotListed unit price changes without the permission gate');

$assert(SalesInquiryUnitPriceGate::itemsOverrideCatalogListPrices(
    [
        ['remark' => 'OnStock', 'unit_price' => 80, 'item_refno' => 'p1'],
        ['remark' => 'NotListed', 'unit_price' => 12],
    ],
    static fn (array $item): ?float => ($item['item_refno'] ?? '') === 'p1' ? 100.0 : null
) === true, 'flags catalog override vs list price');

$assert(SalesInquiryUnitPriceGate::itemsOverrideCatalogListPrices(
    [
        ['remark' => 'OnStock', 'unit_price' => 100, 'item_refno' => 'p1'],
    ],
    static fn (array $item): ?float => 100.0
) === false, 'allows catalog lines at list price');
