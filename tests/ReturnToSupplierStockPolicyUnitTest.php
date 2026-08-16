<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/ReturnToSupplierStockPolicy.php';

use App\Support\ReturnToSupplierStockPolicy;

$failed = 0;
$items = ReturnToSupplierStockPolicy::aggregateByInventoryItem([
    ['inv_refno' => 'item-a', 'item_code' => 'A', 'qty_returned' => 2],
    ['inv_refno' => 'item-a', 'item_code' => 'A', 'qty_returned' => 3],
    ['inv_refno' => 'item-b', 'item_code' => 'B', 'qty_returned' => 1],
]);
if (count($items) === 2 && (float) $items['item-a']['qty_returned'] === 5.0) {
    echo "  PASS duplicate return lines are aggregated before stock deduction\n";
} else {
    echo "  FAIL duplicate return lines are aggregated before stock deduction\n";
    $failed++;
}

try {
    ReturnToSupplierStockPolicy::assertAvailable('ITEM-A', 5, 5);
    echo "  PASS exact available centralized stock can be returned\n";
} catch (Throwable $e) {
    echo "  FAIL exact available centralized stock can be returned: {$e->getMessage()}\n";
    $failed++;
}

try {
    ReturnToSupplierStockPolicy::assertAvailable('ITEM-A', 6, 5);
    echo "  FAIL over-return was accepted\n";
    $failed++;
} catch (RuntimeException $e) {
    if (str_contains($e->getMessage(), 'Insufficient centralized stock')) {
        echo "  PASS return cannot exceed centralized stock\n";
    } else {
        echo "  FAIL wrong over-return error: {$e->getMessage()}\n";
        $failed++;
    }
}

exit($failed === 0 ? 0 : 1);
