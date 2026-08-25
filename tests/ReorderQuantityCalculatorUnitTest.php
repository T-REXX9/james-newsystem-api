<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Repositories/ReorderReportRepository.php';

use App\Repositories\ReorderReportRepository;

$cases = [
    'shortage larger than replenishment restores reorder level' => [0.0, 15.0, 10.0, 0.0, 15.0],
    'open PO is deducted from the full shortage' => [0.0, 15.0, 10.0, 10.0, 5.0],
    'larger configured replenishment remains respected' => [14.0, 15.0, 10.0, 0.0, 10.0],
    'zero replenishment uses shortage to reorder level' => [4.0, 15.0, 0.0, 0.0, 11.0],
    'commitments cannot produce a negative suggestion' => [0.0, 15.0, 10.0, 20.0, 0.0],
];

$failed = 0;
foreach ($cases as $message => [$available, $reorder, $replenish, $openPo, $expected]) {
    $actual = ReorderReportRepository::calculateSuggestedReorderQty(
        $available,
        $reorder,
        $replenish,
        $openPo
    );
    if (abs($actual - $expected) < 0.00001) {
        echo "  PASS {$message}\n";
        continue;
    }

    $failed++;
    echo "  FAIL {$message}: expected {$expected}, got {$actual}\n";
}

echo 'Results: ' . (count($cases) - $failed) . ' passed, ' . $failed . " failed\n";
exit($failed === 0 ? 0 : 1);
