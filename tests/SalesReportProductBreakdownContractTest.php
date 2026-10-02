<?php

declare(strict_types=1);

$repository = file_get_contents(dirname(__DIR__) . '/src/Repositories/SalesReportRepository.php');
$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: {$label}");
    }
    echo "PASS: {$label}\n";
};

$assert(
    str_contains($repository, "\$summary['productTotals'] = \$this->buildProductTotals(\$mainId, \$transactions)")
        && str_contains($repository, 'function buildProductTotals(int $mainId, array $transactions)'),
    'Sales Report adds a product breakdown based on the same report transactions'
);
$assert(
    str_contains($repository, 'PostedSalesDocumentSql::invoiceIsPosted(\'d\')')
        && str_contains($repository, 'PostedSalesDocumentSql::deliveryReceiptIsPosted(\'d\')')
        && str_contains($repository, 'array_chunk(array_values(array_unique($refnos)), 500)'),
    'product totals include only posted invoices and delivery receipts with bounded batches'
);
$assert(
    str_contains($repository, '$exclusiveInvoiceRefs, \'invoice\', 1.12, $totals'),
    'product item sales are adjusted consistently for exclusive-VAT invoices'
);

echo "Sales Report product breakdown contract: 3/3 passed\n";
