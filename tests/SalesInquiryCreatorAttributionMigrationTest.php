<?php

declare(strict_types=1);

$migration = file_get_contents(__DIR__ . '/../migrations/056_repair_sales_inquiry_customer_agent_attribution.sql');

if ($migration === false) {
    throw new RuntimeException('FAIL: unable to read sales inquiry customer-agent attribution repair migration');
}

$checks = [
    'repairs inquiry salesperson fields from the assigned customer agent' =>
        str_contains($migration, 'UPDATE tblinquiry AS inquiry')
        && str_contains($migration, 'INNER JOIN tblpatient AS customer')
        && str_contains($migration, "CAST(agent.lid AS CHAR) = TRIM(COALESCE(customer.lsales_person, ''))")
        && str_contains($migration, 'inquiry.lsales_person_id = CAST(customer.lsales_person AS CHAR)')
        && str_contains($migration, 'inquiry.lsalesperson = TRIM(CONCAT'),
    'does not coerce blank or legacy agent values to integers' =>
        !str_contains($migration, 'CAST(customer.lsales_person AS UNSIGNED)'),
    'preserves the recorded inquiry creator for Prepared By' =>
        !str_contains($migration, 'inquiry.luser ='),
    'repairs linked Sales Orders from the same customer agent' =>
        str_contains($migration, 'UPDATE tbltransaction AS sales_order')
        && str_contains($migration, 'inquiry.lrefno = sales_order.linquiry_refno')
        && str_contains($migration, 'sales_order.lsales_person_id = CAST(customer.lsales_person AS CHAR)'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
