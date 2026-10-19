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
    'monthly chart sales use the legacy transaction calculation including cancel-flagged rows' => str_contains(
        $repository,
        "MONTH(t.ldate) AS month"
    ) && str_contains(
        $repository,
        "FROM tbltransaction t\n    INNER JOIN tbltransaction_item item ON item.lrefno = t.lrefno"
    ) && str_contains(
        $repository,
        "AND t.ldate >= :sales_year_start\n      AND t.ldate < :sales_next_year_start"
    ) && str_contains(
        $repository,
        "CASE WHEN t.ltax_type = 'Exclusive' THEN 1.12 ELSE 1 END"
    ) && str_contains(
        $repository,
        "AND LOWER(TRIM(COALESCE(lg.lref_name, ''))) IN ('dcr', 'collection', 'collections')"
    ) && !str_contains(
        substr($repository, strpos($repository, 'FROM tbltransaction t'), 1000),
        'limported'
    ),
    'monthly sales response includes the month key consumed by the dashboard' => str_contains(
        $repository,
        "'month' => \$monthNumber"
    ),
    'dashboard top customers follow the legacy imported non-cancelled monthly transaction selection' => str_contains(
        $repository,
        "SELECT lmain_id, lcustomerid, MIN(lid) AS first_transaction_id\n    FROM tbltransaction\n    WHERE lmain_id = :main_id\n      AND limported = 1\n      AND lcancel = 0\n      AND ldate >= :month_start\n      AND ldate < :next_month_start\n    GROUP BY lmain_id, lcustomerid"
    ) && str_contains(
        $repository,
        "SUM(COALESCE(item.lqty, 0) * COALESCE(item.lprice, 0))\n        * CASE WHEN t.ltax_type = 'Exclusive' THEN 1.12 ELSE 1 END"
    ) && str_contains(
        $repository,
        "monthly_purchasers ON monthly_purchasers.first_transaction_id = t.lid"
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
