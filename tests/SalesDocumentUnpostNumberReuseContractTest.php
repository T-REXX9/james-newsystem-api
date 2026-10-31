<?php

declare(strict_types=1);

$salesOrder = file_get_contents(__DIR__ . '/../src/Repositories/SalesOrderRepository.php');
$orderSlip = file_get_contents(__DIR__ . '/../src/Repositories/OrderSlipRepository.php');
$invoice = file_get_contents(__DIR__ . '/../src/Repositories/InvoiceRepository.php');

$checks = [
    'order slip unpost preserves its number and type' => str_contains($orderSlip, "'last_document_no' => (string) (\$record['order_slip']['slip_no'] ?? '')")
        && str_contains($orderSlip, "'last_document_type' => 'Order Slip'"),
    'invoice unpost preserves its number and type' => str_contains($invoice, "'last_document_no' => (string) (\$record['invoice']['invoice_no'] ?? '')")
        && str_contains($invoice, "'last_document_type' => 'Invoice'"),
    'sales order unpost prefers the number supplied by its source document' => str_contains($salesOrder, "\$payload['last_document_no'] ?? ''"),
    'sales order unpost retains legacy fallback when called directly' => str_contains($salesOrder, "\$existing['order']['order_slip_no'] ?? ''")
        && str_contains($salesOrder, "\$existing['order']['invoice_no'] ?? ''"),
    'regeneration reads the retained number from the sales order' => str_contains($salesOrder, "SELECT COALESCE(llast_refno, \"\")"),
    'order slip regeneration accepts only a retained order slip number' => str_contains($salesOrder, "SalesDocumentNumberReuse::forTarget(")
        && str_contains($salesOrder, "'Order Slip'"),
    'invoice regeneration accepts only a retained invoice number' => str_contains($salesOrder, "SalesDocumentNumberReuse::forTarget(")
        && str_contains($salesOrder, "'Invoice'"),
    'successful regeneration clears the retained number for either document type' => substr_count($salesOrder, 'llast_refno = NULL') >= 2,
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Sales document unpost number reuse contract: ' . count($checks) . '/' . count($checks) . " passed\n";
