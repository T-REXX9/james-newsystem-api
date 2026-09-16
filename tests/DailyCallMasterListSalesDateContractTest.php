<?php

declare(strict_types=1);

$repository = file_get_contents(__DIR__ . '/../src/Repositories/DailyCallMonitoringRepository.php');

$checks = [
    'ledger last-purchase summary is limited to qualifying sales' => str_contains(
        $repository,
        "AND LOWER(TRIM(COALESCE(lg.ltype, ''))) = 'debit'\n      AND LOWER(TRIM(COALESCE(lg.lref_name, ''))) IN ('invoice', 'order slip', 'order_slip')"
    ),
    'linked sales orders are excluded because their posted invoice or delivery receipt is already in the ledger' => str_contains(
        $repository,
        "AND COALESCE(tr.invoice_refno, '') = ''\n      AND COALESCE(tr.ldr_refno, '') = ''"
    ),
    'current-month sales uses the Sales Report document sources instead of summing mirrored ledger and transaction entries' => str_contains(
        $repository,
        'sales_report_current_month AS ('
    ) && str_contains(
        $repository,
        'COALESCE(sales_report_current_month.current_month_sales, 0) AS current_month_sales'
    ),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Daily Call Master List sales date contract: ' . count($checks) . '/' . count($checks) . " passed\n";
