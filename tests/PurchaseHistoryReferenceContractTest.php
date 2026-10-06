<?php

declare(strict_types=1);

$controller = file_get_contents(__DIR__ . '/../src/Controllers/CustomerController.php');
$repository = file_get_contents(__DIR__ . '/../src/Repositories/CustomerRepository.php');

$checks = [
    'customer identity is returned' => str_contains($controller, "'customer' => ["),
    'old customer name is returned' => str_contains($repository, "AS old_name"),
    'creating agent name is returned' => str_contains($repository, "AS agent_name"),
    'current month sales is calculated' => str_contains($controller, "'current_month_sales' =>"),
    'customer sales totals are sourced from the ledger' => str_contains($repository, 'FROM tblledger l') && str_contains($repository, "l.lref_name") && str_contains($repository, "'invoice', 'order slip', 'order_slip'"),
    'purchase history returns persisted discount code' => str_contains($controller, "'discount_code' =>"),
    'outstanding balance and credit limit are returned' => str_contains($controller, "'outstanding_balance'") && str_contains($controller, "'credit_limit'"),
    'purchase history uses the legacy invoice and delivery item tables' => str_contains($repository, 'INNER JOIN tblinvoice_itemrec item ON item.linvoice_refno = inv.lrefno') && str_contains($repository, 'INNER JOIN tbldelivery_receipt_items dri ON dri.lor_refno = dr.lrefno'),
    'returns use the legacy source transaction and item code linkage' => str_contains($repository, 'cri.ltransaction_item_id = src.source_refno') && str_contains($repository, 'cri.litemcode = src.litemcode'),
    'purchased item lookup is available for complaints and returns' => str_contains($repository, 'function searchPurchasedItems') && str_contains($repository, 'function assertCustomerPurchasedItem'),
    'discount code normalization is centralized' => str_contains($repository, 'function normalizeDiscountCode'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Purchase History reference contract: ' . count($checks) . '/' . count($checks) . " passed\n";
