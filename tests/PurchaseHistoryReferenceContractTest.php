<?php

declare(strict_types=1);

$controller = file_get_contents(__DIR__ . '/../src/Controllers/CustomerController.php');
$repository = file_get_contents(__DIR__ . '/../src/Repositories/CustomerRepository.php');

$checks = [
    'customer identity is returned' => str_contains($controller, "'customer' => ["),
    'old customer name is returned' => str_contains($repository, "AS old_name"),
    'creating agent name is returned' => str_contains($repository, "AS agent_name"),
    'current month sales is calculated' => str_contains($controller, "'current_month_sales' =>"),
    'current month sales includes posted documents only' => str_contains($controller, "source_status") && str_contains($controller, "=== 'posted'"),
    'outstanding balance and credit limit are returned' => str_contains($controller, "'outstanding_balance'") && str_contains($controller, "'credit_limit'"),
    'returns are linked to their source transaction' => str_contains($repository, 'ret.source_refno = src.source_refno'),
    'only finalized returns affect totals' => str_contains($repository, "IN ('Posted', 'Approved')"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Purchase History reference contract: ' . count($checks) . '/' . count($checks) . " passed\n";
