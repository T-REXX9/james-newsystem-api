<?php

declare(strict_types=1);

$repository = file_get_contents(__DIR__ . '/../src/Repositories/DailyCallMonitoringRepository.php');
$postedSales = file_get_contents(__DIR__ . '/../src/Support/PostedSalesDocumentSql.php');

$checks = [
    'ledger last-purchase summary is limited to qualifying sales' => str_contains(
        $repository,
        "AND LOWER(TRIM(COALESCE(lg.ltype, ''))) = 'debit'\n      AND LOWER(TRIM(COALESCE(lg.lref_name, ''))) IN ('invoice', 'order slip', 'order_slip')"
    ),
    'Priority current-month sales use the shared posted-document rule and invoice sales date' => str_contains($repository, 'PostedSalesDocumentSql::currentMonthCustomerSalesCtes()')
        && str_contains($postedSales, "AND l.ldate >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")
        && substr_count($postedSales, 'AND l.ldate < DATE_ADD(CURDATE(), INTERVAL 1 DAY)') === 2
        && !str_contains($postedSales, "DATE(l.ldatetime) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
    'a current posted invoice or delivery receipt keeps the customer in the Priority List even when its ledger mirror is missing' => str_contains(
        $repository,
        "WHEN COALESCE(sales_report_current_month.document_count, 0) > 0 THEN GREATEST(\n            1,\n            COALESCE(ledger_summary.priority_transaction_count, 0) + COALESCE(txn_summary.priority_transaction_count, 0)"
    ),
    'final master-list SQL interpolates its dynamic filters before preparing the query' => str_contains($repository, '$sql .= <<<SQL')
        && str_contains($repository, 'WHERE {$whereSql}')
        && !str_contains($repository, "\$sql .= <<<'SQL'\ncustomer_universe AS"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Daily Call Master List sales date contract: ' . count($checks) . '/' . count($checks) . " passed\n";
