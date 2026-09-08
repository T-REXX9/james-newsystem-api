<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/VipStanding.php';

use App\Support\VipStanding;

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

echo "VipStanding\n";

$run('zero spend is regular even with VIP1-style thresholds', static function (): void {
    $level = VipStanding::resolveLevel(0.0, 10000.0, 30000.0);
    if ($level !== 'regular') {
        throw new RuntimeException('got ' . $level);
    }
});

$run('spend at one-time threshold is silver', static function (): void {
    $level = VipStanding::resolveLevel(10000.0, 10000.0, 30000.0);
    if ($level !== 'silver') {
        throw new RuntimeException('got ' . $level);
    }
});

$run('spend below silver stays regular', static function (): void {
    $level = VipStanding::resolveLevel(9999.99, 10000.0, 30000.0);
    if ($level !== 'regular') {
        throw new RuntimeException('got ' . $level);
    }
});

$run('spend at unlimited threshold is gold', static function (): void {
    $level = VipStanding::resolveLevel(30000.0, 10000.0, 30000.0);
    if ($level !== 'gold') {
        throw new RuntimeException('got ' . $level);
    }
});

$run('negative spend is treated as zero / regular', static function (): void {
    $level = VipStanding::resolveLevel(-500.0, 10000.0, 30000.0);
    if ($level !== 'regular') {
        throw new RuntimeException('got ' . $level);
    }
});

echo "\nVipStanding: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
