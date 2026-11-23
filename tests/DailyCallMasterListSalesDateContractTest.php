<?php

declare(strict_types=1);

$repository = file_get_contents(__DIR__ . '/../src/Repositories/DailyCallMonitoringRepository.php');
$postedSales = file_get_contents(__DIR__ . '/../src/Support/PostedSalesDocumentSql.php');
$customerSalesStart = strpos($postedSales, 'public static function currentMonthCustomerSalesCtes');
$blankCustomerSalesStart = strpos($postedSales, 'public static function currentMonthBlankCustomerSalesCte');
$customerSalesMethod = $customerSalesStart === false || $blankCustomerSalesStart === false
    ? ''
    : substr($postedSales, $customerSalesStart, $blankCustomerSalesStart - $customerSalesStart);

$checks = [
    'ledger last-purchase summary is limited to qualifying sales' => str_contains(
        $repository,
        "AND LOWER(TRIM(COALESCE(lg.ltype, ''))) = 'debit'\n      AND LOWER(TRIM(COALESCE(lg.lref_name, ''))) IN ('invoice', 'order slip', 'order_slip')"
    ),
    'Priority current-month sales use the shared posted-document rule and invoice sales date' => str_contains($repository, 'PostedSalesDocumentSql::currentMonthCustomerSalesCtes()')
        && str_contains($customerSalesMethod, "AND l.ldate >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")
        && substr_count($customerSalesMethod, 'AND l.ldate < DATE_ADD(CURDATE(), INTERVAL 1 DAY)') === 2
        && !str_contains($customerSalesMethod, "DATE(l.ldatetime) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
    'posted invoices and receipts preserve their timestamp in the shared sales CTE' => str_contains($customerSalesMethod, 'MAX(l.ldate) AS sale_at')
        && str_contains($customerSalesMethod, 'MAX(sale_at) AS last_sale_at'),
    'master-list override expiration compares against the posted-document timestamp fallback' => str_contains($repository, 'THEN sales_report_current_month.last_sale_at')
        && str_contains($repository, '$lastPurchaseAt > $overrideCreatedAt'),
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
