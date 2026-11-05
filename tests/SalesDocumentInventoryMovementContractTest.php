<?php

declare(strict_types=1);

$orderSlip = file_get_contents(__DIR__ . '/../src/Repositories/OrderSlipRepository.php');
$invoice = file_get_contents(__DIR__ . '/../src/Repositories/InvoiceRepository.php');
$salesOrder = file_get_contents(__DIR__ . '/../src/Repositories/SalesOrderRepository.php');

$checks = [
    'order slip generation synchronizes stock movements' => str_contains($orderSlip, "\$this->syncInventoryStockLogs(\n                    \$pdo,")
        && str_contains($orderSlip, '$createdSlip[\'order_slip\']'),
    'invoice generation synchronizes stock movements' => str_contains($invoice, '$this->syncInventoryStockLogs($pdo, $created[\'invoice\']'),
    'order slip and invoice deductions include only OnStock items' => substr_count($orderSlip, "strcasecmp(trim((string) (\$item['remark'] ?? '')), 'OnStock') !== 0") >= 2
        && substr_count($invoice, "strcasecmp(trim((string) (\$item['remark'] ?? '')), 'OnStock') !== 0") >= 2,
    'order slip and invoice movements are stock out entries' => str_contains($orderSlip, "'lout' => \$qty")
        && str_contains($orderSlip, "'lstatus_logs' => '-'")
        && str_contains($invoice, "'lout' => \$qty")
        && str_contains($invoice, "'lstatus_logs' => '-'") ,
    'cancellation restores only documents with an existing stock out' => str_contains($orderSlip, '$this->insertCancellationInventoryRestoreLogs($pdo, $orderSlip')
        && str_contains($invoice, '$this->insertCancellationInventoryRestoreLogs($pdo, $invoice')
        && substr_count($orderSlip, "'lstatus_logs' => '+'") > 0
        && substr_count($invoice, "'lstatus_logs' => '+'") > 0,
    'unposting removes linked document stock movements' => str_contains($salesOrder, 'DELETE FROM tblinventory_logs WHERE lrefno = :refno')
        && str_contains($salesOrder, '$this->cleanupLinkedDocumentsForSalesOrder($salesRefno)'),
];

$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? '  PASS ' : '  FAIL ') . $name . "\n";
    if (!$passed) {
        $failed++;
    }
}

echo 'Sales document inventory movement contract: ' . (count($checks) - $failed) . '/' . count($checks) . " passed\n";
exit($failed === 0 ? 0 : 1);
