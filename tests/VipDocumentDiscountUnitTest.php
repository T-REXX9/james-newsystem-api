<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/VipDocumentDiscount.php';

use App\Support\VipDocumentDiscount;

$passed = 0;
$failed = 0;

$run = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo "  PASS {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "  FAIL {$name}: {$error->getMessage()}\n";
    }
};

$run('INQ26-20478 10% VIP Silver reduces 7560 to 6804', static function (): void {
    $result = VipDocumentDiscount::compute(7560.0, 'silver', 10.0, true);
    if ($result['discount_amount'] !== 756.0) {
        throw new RuntimeException('discount was ' . json_encode($result['discount_amount']));
    }
    if ($result['total_to_pay'] !== 6804.0) {
        throw new RuntimeException('total to pay was ' . json_encode($result['total_to_pay']));
    }
});

$run('invoice ledger uses TOTAL AMOUNT DUE after VIP', static function (): void {
    $billed = VipDocumentDiscount::billedAmount(7560.0, 756.0);
    if ($billed !== 6804.0) {
        throw new RuntimeException('billed amount was ' . json_encode($billed));
    }
});

$run('regular customers keep the undiscounted total', static function (): void {
    $result = VipDocumentDiscount::compute(7560.0, 'regular', 10.0, false);
    if ($result['applied'] !== false || $result['total_to_pay'] !== 7560.0) {
        throw new RuntimeException(json_encode($result));
    }
});

echo "\nVipDocumentDiscount: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
