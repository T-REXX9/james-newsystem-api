<?php

declare(strict_types=1);

$repository = file_get_contents(__DIR__ . '/../src/Repositories/DailyCallMonitoringRepository.php');

$checks = [
    'dashboard sales KPI uses the same total as the monthly sales series' => str_contains(
        $repository,
        "\$totalSalesYtd = array_sum(array_column(\$monthlySales, 'sales'));"
    ) && str_contains(
        $repository,
        "'total_sales_ytd' => \$totalSalesYtd"
    ),
    'dashboard top-customer sales excludes non-sales ledger debits' => str_contains(
        $repository,
        "AND lg.ldatetime < :next_month_start\n  AND LOWER(TRIM(COALESCE(lg.ltype, ''))) = 'debit'\n  AND LOWER(TRIM(COALESCE(lg.lref_name, ''))) IN ('invoice', 'order slip', 'order_slip')\nGROUP BY p.lsessionid, p.lcompany"
    ),
    'dashboard top-salesperson sales excludes non-sales ledger debits' => str_contains(
        $repository,
        "AND LOWER(TRIM(COALESCE(lg.lref_name, ''))) IN ('invoice', 'order slip', 'order_slip')\nGROUP BY p.lsales_person, a.lfname, a.llname"
    ),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Management Dashboard sales scope contract: ' . count($checks) . '/' . count($checks) . " passed\n";
