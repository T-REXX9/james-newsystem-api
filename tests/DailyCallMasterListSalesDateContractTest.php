<?php

declare(strict_types=1);

$repository = file_get_contents(__DIR__ . '/../src/Repositories/DailyCallMonitoringRepository.php');

$checks = [
    'ledger last-purchase summary is limited to qualifying sales' => str_contains(
        $repository,
        "AND LOWER(TRIM(COALESCE(lg.ltype, ''))) = 'debit'\n      AND LOWER(TRIM(COALESCE(lg.lref_name, ''))) IN ('invoice', 'order slip', 'order_slip')"
    ),
    'legacy current-month sales keeps the old imported and non-cancelled transaction filters' => str_contains(
        $repository,
        "AND COALESCE(t.lcancel, 0) = 0\n  AND COALESCE(t.limported, 0) = 1"
    ),
    'agent snapshot exposes the old Home current-month sales calculation' => str_contains(
        $repository,
        "'legacy_current_month_sales' => \$this->getLegacyCurrentMonthSales(\$mainId, \$viewerUserId)"
    ) && str_contains(
        $repository,
        "AND t.lsales_person_id = :salesperson_id"
    ) && str_contains(
        $repository,
        "AND t.ldate <= LAST_DAY(CURDATE())"
    ),
    'Priority current-month invoice sales use the invoice sales date' => str_contains(
        $repository,
        "AND l.lcancel IS NULL\n          AND l.ldate >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    ) && !str_contains(
        $repository,
        "AND DATE(l.ldatetime) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    ),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Daily Call Master List sales date contract: ' . count($checks) . '/' . count($checks) . " passed\n";
