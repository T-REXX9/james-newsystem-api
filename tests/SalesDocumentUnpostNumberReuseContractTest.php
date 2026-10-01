<?php

declare(strict_types=1);

$salesOrder = file_get_contents(__DIR__ . '/../src/Repositories/SalesOrderRepository.php');
$orderSlip = file_get_contents(__DIR__ . '/../src/Repositories/OrderSlipRepository.php');
$invoice = file_get_contents(__DIR__ . '/../src/Repositories/InvoiceRepository.php');

$checks = [
    'order slip unpost preserves its own visible number' => str_contains($orderSlip, "'last_document_no' => (string) (\$record['order_slip']['slip_no'] ?? '')"),
    'invoice unpost preserves its own visible number' => str_contains($invoice, "'last_document_no' => (string) (\$record['invoice']['invoice_no'] ?? '')"),
    'sales order unpost prefers the number supplied by its source document' => str_contains($salesOrder, "\$payload['last_document_no'] ?? ''"),
    'sales order unpost retains legacy fallback when called directly' => str_contains($salesOrder, "\$existing['order']['order_slip_no'] ?? ''")
        && str_contains($salesOrder, "\$existing['order']['invoice_no'] ?? ''"),
    'regeneration reads the retained number from the sales order' => str_contains($salesOrder, "SELECT COALESCE(llast_refno, \"\")"),
    'order slip regeneration supplies the retained number' => str_contains($salesOrder, "'slip_no' => trim((string) (\$order['last_document_no'] ?? ''))"),
    'invoice regeneration supplies the retained number' => str_contains($salesOrder, "'invoice_no' => trim((string) (\$order['last_document_no'] ?? ''))"),
    'successful regeneration clears the retained number for either document type' => substr_count($salesOrder, 'llast_refno = NULL') >= 2,
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Sales document unpost number reuse contract: ' . count($checks) . '/' . count($checks) . " passed\n";
